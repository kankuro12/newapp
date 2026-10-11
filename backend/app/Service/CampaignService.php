<?php

namespace App\Service;

use App\Jobs\SendCampaign;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform marketing campaigns delivered through the durable message pipeline with current consent checks.
 */
class CampaignService
{
    public const AUDIENCES = ['all', 'trial', 'active', 'expired'];

    public const CHANNELS = ['email', 'fcm', 'whatsapp'];

    /** Recipients per queued job, keeping each job well under the queue retry window. */
    public const CHUNK = 500;

    public function list(): array
    {
        return DB::table('marketing_campaigns')->orderByDesc('id')->limit(100)->get()->map(fn ($row) => $this->data($row))->all();
    }

    public function data(object $row): array
    {
        $deliveries = DB::table('message_deliveries')->where('campaign_id', $row->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['id' => (string) $row->id, 'name' => $row->name, 'body' => $row->body, 'channels' => json_decode($row->channels, true), 'audience' => $row->audience,
            'status' => $row->status, 'scheduled_at' => $row->scheduled_at, 'started_at' => $row->started_at, 'finished_at' => $row->finished_at,
            'recipient_count' => (string) $row->recipient_count, 'version' => (string) $row->version,
            'deliveries' => collect($deliveries)->map(fn ($total) => (string) $total)->all()];
    }

    public function save(int $admin, ?int $id, array $input): array
    {
        return DB::transaction(function () use ($admin, $id, $input) {
            $values = ['name' => $input['name'], 'body' => $input['body'], 'channels' => json_encode(array_values(array_unique($input['channels']))), 'audience' => $input['audience'], 'updated_at' => now()];
            if ($id) {
                $row = DB::table('marketing_campaigns')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
                abort_unless((int) $row->version === (int) $input['version'], 409, 'Campaign changed. Reload.');
                abort_unless($row->status === 'draft', 409, 'Only draft campaigns can be edited.');
                DB::table('marketing_campaigns')->where('id', $id)->update([...$values, 'version' => $row->version + 1]);
            } else {
                $id = DB::table('marketing_campaigns')->insertGetId([...$values, 'status' => 'draft', 'version' => 1, 'created_by' => $admin, 'created_at' => now()]);
            }
            $this->audit($admin, 'campaign.saved', $id, []);

            return $this->data(DB::table('marketing_campaigns')->where('id', $id)->first());
        }, 3);
    }

    /**
     * @return list<int> owner user ids for the audience (consent is checked again when each message sends)
     */
    public function recipients(string $audience): array
    {
        abort_unless(in_array($audience, self::AUDIENCES, true), 422, 'Audience unavailable.');
        $billing = app(BillingService::class);
        $users = [];
        DB::table('billing_account_user')->join('users', 'users.id', '=', 'billing_account_user.user_id')
            ->where('billing_account_user.role', 'owner')->where('billing_account_user.active', true)->whereNull('users.disabled_at')
            ->orderBy('billing_account_user.billing_account_id')->select('billing_account_user.billing_account_id', 'users.id')
            ->chunk(500, function ($rows) use ($audience, $billing, &$users) {
                foreach ($rows as $row) {
                    if ($audience === 'all' || $billing->entitlements((int) $row->billing_account_id)['status'] === $audience) {
                        $users[(int) $row->id] = true;
                    }
                }
            });

        return array_keys($users);
    }

    public function preview(int $id): array
    {
        $row = DB::table('marketing_campaigns')->where('id', $id)->first() ?? abort(404);
        $recipients = $this->recipients($row->audience);
        $preferences = DB::table('notification_preferences')->whereIn('user_id', $recipients ?: [0]);
        $message = app(NotificationService::class)->message('promotion', 'Customer', ['body' => $row->body, 'unsubscribe_url' => $this->unsubscribeUrl()]);

        return ['campaign' => $this->data($row), 'message' => $message, 'recipients' => (string) count($recipients), 'consented' => [
            'email' => (string) (clone $preferences)->where('promotional_email', true)->count(),
            'fcm' => (string) (clone $preferences)->where('promotional_fcm', true)->count(),
            'whatsapp' => (string) (clone $preferences)->where('promotional_whatsapp', true)->count(),
        ]];
    }

    public function schedule(int $admin, int $id, int $version, ?string $at, string $reason): array
    {
        return DB::transaction(function () use ($admin, $id, $version, $at, $reason) {
            $row = DB::table('marketing_campaigns')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $row->version === $version, 409, 'Campaign changed. Review preview again.');
            abort_unless($row->status === 'draft', 409, 'Campaign already scheduled or sent.');
            DB::table('marketing_campaigns')->where('id', $id)->update(['status' => 'scheduled', 'scheduled_at' => $at ? Carbon::parse($at)->utc() : now(), 'version' => $row->version + 1, 'updated_at' => now()]);
            $this->audit($admin, 'campaign.scheduled', $id, ['reason' => $reason, 'scheduled_at' => $at]);

            return $this->data(DB::table('marketing_campaigns')->where('id', $id)->first());
        }, 3);
    }

    public function cancel(int $admin, int $id, string $reason): array
    {
        return DB::transaction(function () use ($admin, $id, $reason) {
            $row = DB::table('marketing_campaigns')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless(in_array($row->status, ['draft', 'scheduled', 'sending'], true), 409, 'Campaign already finished.');
            DB::table('marketing_campaigns')->where('id', $id)->update(['status' => 'cancelled', 'finished_at' => now(), 'version' => $row->version + 1, 'updated_at' => now()]);
            DB::table('message_deliveries')->where('campaign_id', $id)->where('status', 'pending')->update(['status' => 'skipped', 'error_code' => 'campaign_cancelled', 'updated_at' => now()]);
            $this->audit($admin, 'campaign.cancelled', $id, ['reason' => $reason]);

            return $this->data(DB::table('marketing_campaigns')->where('id', $id)->first());
        }, 3);
    }

    /**
     * Scheduler entry: claim due campaigns and hand each to a queued job.
     */
    public function dispatchDue(): int
    {
        $count = 0;
        foreach (DB::table('marketing_campaigns')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(20)->pluck('id') as $id) {
            if (DB::table('marketing_campaigns')->where('id', $id)->where('status', 'scheduled')->update(['status' => 'sending', 'started_at' => now(), 'updated_at' => now()])) {
                SendCampaign::dispatch((int) $id);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Sends one chunk of recipients, then queues the next chunk so each job stays short.
     */
    public function send(int $id, int $offset = 0): void
    {
        $row = DB::table('marketing_campaigns')->where('id', $id)->first();
        if (! $row || $row->status !== 'sending') {
            return;
        }
        $notifications = app(NotificationService::class);
        $channels = json_decode($row->channels, true, 512, JSON_THROW_ON_ERROR);
        $recipients = $this->recipients($row->audience);
        sort($recipients);
        foreach (array_slice($recipients, $offset, self::CHUNK) as $user) {
            // Delivery keys make a repeated chunk harmless.
            $notifications->enqueue('promotion', $user, ['body' => $row->body, 'unsubscribe_url' => $this->unsubscribeUrl(), 'campaign_id' => (string) $id], $channels, 'campaign:'.$id, null, $id);
        }
        if ($offset + self::CHUNK < count($recipients)) {
            SendCampaign::dispatch($id, $offset + self::CHUNK);

            return;
        }
        DB::table('marketing_campaigns')->where('id', $id)->where('status', 'sending')->update(['status' => 'sent', 'recipient_count' => count($recipients), 'finished_at' => now(), 'updated_at' => now()]);
    }

    private function unsubscribeUrl(): string
    {
        return rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/notifications';
    }

    private function audit(int $admin, string $action, int $id, array $metadata): void
    {
        DB::table('billing_audit_logs')->insert(['actor_id' => $admin, 'actor_guard' => 'superadmin', 'action' => $action, 'subject_id' => $id, 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }
}
