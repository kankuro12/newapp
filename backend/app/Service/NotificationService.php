<?php

namespace App\Service;

use App\Jobs\SendAccountMessage;
use App\Mail\AccountMessage;
use App\Support\Money;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationService
{
    private function notificationActor(int $actor): void
    {
        abort_unless(DB::table('users')->where('id', $actor)->whereNull('disabled_at')->exists(), 401);
    }

    public function preferences(int $actor, array $changes = []): array
    {
        $this->notificationActor($actor);
        $defaults = ['operational_email' => true, 'promotional_email' => false, 'operational_fcm' => false, 'promotional_fcm' => false, 'operational_whatsapp' => false, 'promotional_whatsapp' => false];
        if (array_diff(array_keys($changes), array_keys($defaults))) {
            throw new \InvalidArgumentException('Messaging preference unavailable.');
        }
        if ($changes) {
            DB::table('notification_preferences')->upsert([['user_id' => $actor, ...$defaults, ...$changes, 'created_at' => now(), 'updated_at' => now()]], ['user_id'], [...array_keys($changes), 'updated_at']);
        }
        $row = DB::table('notification_preferences')->where('user_id', $actor)->first();

        return array_map(fn ($key) => (bool) ($row->{$key} ?? $defaults[$key]), array_combine(array_keys($defaults), array_keys($defaults)));
    }

    public function devices(int $actor): array
    {
        $this->notificationActor($actor);

        return DB::table('notification_devices')->where('user_id', $actor)->orderByDesc('id')->get(['id', 'device_uuid', 'name', 'active', 'last_seen_at', 'revoked_at'])->map(fn ($device) => ['id' => (string) $device->id, 'device_uuid' => $device->device_uuid, 'name' => $device->name, 'active' => (bool) $device->active, 'last_seen_at' => $device->last_seen_at, 'revoked_at' => $device->revoked_at])->all();
    }

    public function registerDevice(int $actor, array $input): array
    {
        $this->notificationActor($actor);

        return DB::transaction(function () use ($actor, $input) {
            DB::table('users')->where('id', $actor)->lockForUpdate()->first();
            $this->notificationActor($actor);
            $hash = hash('sha256', $input['token']);
            $token = DB::table('notification_devices')->where('token_hash', $hash)->lockForUpdate()->first();
            abort_if($token && ($token->user_id != $actor || $token->device_uuid !== $input['device_uuid']), 409, 'Device token already registered.');
            $existing = DB::table('notification_devices')->where('user_id', $actor)->where('device_uuid', $input['device_uuid'])->lockForUpdate()->first();
            abort_if((! $existing || ! $existing->active) && DB::table('notification_devices')->where('user_id', $actor)->where('active', true)->count() >= 10, 422, 'Maximum ten active devices.');
            $values = ['name' => $input['name'] ?? 'This browser', 'token_hash' => $hash, 'target_kind' => $input['target_kind'] ?? 'token', 'token_encrypted' => Crypt::encryptString($input['token']), 'active' => true, 'last_seen_at' => now(), 'revoked_at' => null, 'updated_at' => now()];
            if ($existing) {
                $id = (int) $existing->id;
                DB::table('notification_devices')->where('id', $id)->update($values);
            } else {
                $id = DB::table('notification_devices')->insertGetId(['user_id' => $actor, 'device_uuid' => $input['device_uuid'], ...$values, 'created_at' => now()]);
            }
            $this->preferences($actor, ['operational_fcm' => true]);

            return ['id' => (string) $id, 'device_uuid' => $input['device_uuid'], 'active' => true];
        }, 3);
    }

    public function revokeDevice(int $actor, int $id): void
    {
        $this->notificationActor($actor);
        $device = DB::table('notification_devices')->where('id', $id)->where('user_id', $actor)->first() ?? abort(404);
        DB::table('notification_devices')->where('id', $device->id)->where('user_id', $actor)->update(['active' => false, 'token_hash' => null, 'token_encrypted' => null, 'revoked_at' => now(), 'updated_at' => now()]);
    }

    public function revokeForLogout(int $actor, mixed $uuid): void
    {
        if (! is_string($uuid) || ! Str::isUuid($uuid)) {
            return;
        }
        DB::table('notification_devices')->where('user_id', $actor)->where('device_uuid', $uuid)->update(['active' => false, 'token_hash' => null, 'token_encrypted' => null, 'revoked_at' => now(), 'updated_at' => now()]);
    }

    public function enqueue(string $event, int $user, array $context = [], array $channels = ['email'], ?string $key = null, ?int $account = null): array
    {
        $fields = match ($event) {
            'welcome', 'password_changed' => [],
            'payment_receipt' => ['currency', 'amount_minor', 'reference'],
            'trial_reminder', 'expiry_reminder' => ['subscription_id', 'end_at'],
            'promotion' => ['body', 'unsubscribe_url', 'campaign_id'],
            default => throw new \InvalidArgumentException('Durable account event unavailable.'),
        };
        if (array_diff(array_keys($context), $fields) || array_diff($channels, ['email', 'fcm', 'whatsapp'])) {
            throw new \InvalidArgumentException('Account delivery context unavailable.');
        }
        $this->message($event, '', $context);
        $key ??= (string) Str::uuid();

        return DB::transaction(function () use ($event, $user, $context, $channels, $key, $account) {
            $ids = [];
            foreach (array_unique($channels) as $channel) {
                $hash = hash('sha256', json_encode([$event, $user, $account, $channel, $key], JSON_THROW_ON_ERROR));
                $created = DB::table('message_deliveries')->insertOrIgnore(['event_key' => $hash, 'event' => $event, 'user_id' => $user, 'billing_account_id' => $account, 'channel' => $channel,
                    'context_encrypted' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR)), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                $id = (int) DB::table('message_deliveries')->where('event_key', $hash)->value('id');
                if (! $id) {
                    throw new \RuntimeException('Delivery record unavailable.');
                }
                $ids[] = $id;
                if ($created) {
                    DB::afterCommit(fn () => $this->dispatch($id));
                }
            }

            return $ids;
        }, 3);
    }

    private function dispatch(int $id): void
    {
        $claimed = DB::table('message_deliveries')->where('id', $id)->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', now()))
            ->update(['next_dispatch_at' => now()->addMinutes(15)]);
        if (! $claimed) {
            return;
        }
        try {
            SendAccountMessage::dispatch($id);
        } catch (\Throwable) {
            DB::table('message_deliveries')->where('id', $id)->where('status', 'pending')->update(['error_code' => 'queue_unavailable', 'next_dispatch_at' => now()->addMinutes(5)]);
        }
    }

    public function dispatchPending(int $limit = 50): int
    {
        DB::table('message_deliveries')->where('status', 'sending')->where('attempted_at', '<', now()->subMinutes(2))->update(['status' => 'unknown', 'error_code' => 'worker_outcome_unknown', 'updated_at' => now()]);
        $ids = DB::table('message_deliveries')->where('status', 'pending')->where(fn ($query) => $query->whereNull('next_dispatch_at')->orWhere('next_dispatch_at', '<=', now()))->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $this->dispatch((int) $id);
        }

        return $ids->count();
    }

    public function deliver(int $id): void
    {
        $delivery = DB::transaction(function () use ($id) {
            $row = DB::table('message_deliveries')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending') {
                return null;
            }
            $user = DB::table('users')->where('id', $row->user_id)->first();
            $preferences = DB::table('notification_preferences')->where('user_id', $row->user_id)->first();
            $code = null;
            if (! $user || $user->disabled_at) {
                $code = 'recipient_unavailable';
            } elseif ($row->billing_account_id && ! DB::table('billing_account_user')->where('billing_account_id', $row->billing_account_id)->where('user_id', $row->user_id)->where('active', true)->exists()) {
                $code = 'membership_revoked';
            } elseif (! in_array($row->channel, ['email', 'fcm'], true)) {
                $code = 'channel_not_configured';
            } elseif ($row->channel === 'fcm' && ! ($preferences?->{$row->event === 'promotion' ? 'promotional_fcm' : 'operational_fcm'} ?? false)) {
                $code = 'consent_missing';
            } elseif ($row->channel === 'fcm' && ! app(FcmService::class)->ready()) {
                $code = 'channel_not_configured';
            } elseif ($row->channel === 'email' && $row->event === 'promotion' && ! ($preferences?->promotional_email ?? false)) {
                $code = 'consent_missing';
            } elseif ($row->channel === 'email' && $row->event !== 'promotion' && $preferences && ! $preferences->operational_email && in_array($row->event, ['trial_reminder', 'expiry_reminder'], true)) {
                $code = 'consent_missing';
            }
            $context = json_decode(Crypt::decryptString($row->context_encrypted), true, 32, JSON_THROW_ON_ERROR);
            if (! $code && in_array($row->event, ['trial_reminder', 'expiry_reminder'], true)) {
                $current = $row->billing_account_id ? app(BillingService::class)->entitlements((int) $row->billing_account_id) : [];
                if (($current['subscription_id'] ?? null) !== (string) ($context['subscription_id'] ?? '')) {
                    $code = 'subscription_changed';
                }
            }
            if ($code) {
                DB::table('message_deliveries')->where('id', $id)->update(['status' => 'skipped', 'error_code' => $code, 'updated_at' => now()]);

                return null;
            }
            DB::table('message_deliveries')->where('id', $id)->update(['status' => 'sending', 'attempts' => $row->attempts + 1, 'attempted_at' => now(), 'error_code' => null, 'updated_at' => now()]);

            return ['row' => $row, 'user' => $user, 'context' => $context];
        }, 3);
        if (! $delivery) {
            return;
        }
        if ($delivery['row']->channel === 'fcm') {
            $this->deliverPush($delivery);

            return;
        }
        try {
            $sent = Mail::to($delivery['user']->email)->send(new AccountMessage($delivery['row']->event, $delivery['user']->name, $delivery['context']));
            $providerId = $sent && method_exists($sent, 'getMessageId') ? $sent->getMessageId() : null;
            DB::table('message_deliveries')->where('id', $id)->update(['status' => 'sent', 'provider_id' => $providerId, 'sent_at' => now(), 'error_code' => null, 'updated_at' => now()]);
        } catch (\Throwable) {
            // Transport exceptions can occur after acceptance. Do not automatically resend.
            DB::table('message_deliveries')->where('id', $id)->update(['status' => 'unknown', 'error_code' => 'provider_outcome_unknown', 'updated_at' => now()]);
        }
    }

    private function deliverPush(array $delivery): void
    {
        $row = $delivery['row'];
        $message = $this->message($row->event, $delivery['user']->name, $delivery['context']);
        $devices = DB::table('notification_devices')->where('user_id', $row->user_id)->where('active', true)->orderBy('id')->get();
        $results = [];
        foreach ($devices as $device) {
            $claimed = DB::table('message_device_deliveries')->insertOrIgnore(['message_delivery_id' => $row->id, 'notification_device_id' => $device->id, 'status' => 'sending', 'created_at' => now(), 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            try {
                $fresh = DB::table('notification_devices')->where('id', $device->id)->where('user_id', $row->user_id)->where('active', true)->where('token_hash', $device->token_hash)->first();
                $preferences = $this->preferences((int) $row->user_id);
                if (! $fresh || ! $preferences[$row->event === 'promotion' ? 'promotional_fcm' : 'operational_fcm']) {
                    $result = ['status' => 'skipped', 'error_code' => 'device_or_consent_changed'];
                } else {
                    $result = app(FcmService::class)->send(Crypt::decryptString($fresh->token_encrypted), ['title' => 'Business Book', 'body' => $row->event === 'promotion' ? mb_substr(implode(' ', $message['lines']), 0, 1000) : 'You have an account update. Open Business Book to review.', 'url' => rtrim(config('app.frontend_url'), '/').'/notifications'], $fresh->target_kind);
                }
            } catch (\Throwable) {
                $result = ['status' => 'unknown', 'error_code' => 'fcm_outcome_unknown'];
            }
            DB::table('message_device_deliveries')->where('message_delivery_id', $row->id)->where('notification_device_id', $device->id)->update([...$result, 'updated_at' => now()]);
            if ($result['status'] === 'invalid') {
                DB::table('notification_devices')->where('id', $device->id)->where('token_hash', $device->token_hash)->update(['active' => false, 'token_hash' => null, 'token_encrypted' => null, 'revoked_at' => now(), 'updated_at' => now()]);
            }
            $results[] = $result['status'];
        }
        $status = in_array('unknown', $results, true) ? 'unknown' : (in_array('sent', $results, true) ? 'sent' : ($results ? 'failed' : 'skipped'));
        DB::table('message_deliveries')->where('id', $row->id)->update(['status' => $status, 'sent_at' => $status === 'sent' ? now() : null, 'error_code' => $status === 'sent' ? null : ($results ? 'fcm_delivery_incomplete' : 'no_active_devices'), 'updated_at' => now()]);
    }

    public function resetUrl(string $email, string $token): string
    {
        return rtrim(config('app.frontend_url'), '/').'/reset?'.http_build_query(['token' => $token, 'email' => $email]);
    }

    private function actionUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Account email link invalid.');
        }
        $origin = fn ($value) => strtolower(($value['scheme'] ?? '').'://'.($value['host'] ?? '')).(isset($value['port']) ? ':'.$value['port'] : '');
        $allowed = array_map(fn ($base) => $origin(parse_url($base) ?: []), [config('app.frontend_url'), config('app.url')]);
        if (! in_array($origin($parts), $allowed, true)) {
            throw new \InvalidArgumentException('Account email link origin unavailable.');
        }

        return $url;
    }

    public function message(string $event, string $name, array $context = []): array
    {
        $home = rtrim(config('app.frontend_url'), '/');
        $definitions = [
            'welcome' => ['Welcome to Business Book', ['Your account is ready. Verify your email, then create or select your business.', 'Each business keeps separate records and staff access.'], 'Open Business Book', $home.'/businesses'],
            'verification' => ['Verify your Business Book email', ['Confirm this email address to finish setting up your account.', 'If you did not create this account, you can ignore this email.'], 'Verify email', null],
            'password_reset' => ['Reset your Business Book password', ['A password reset was requested for your account.', 'This link expires in '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60).' minutes.', 'If you did not request this change, ignore this email.'], 'Reset password', null],
            'password_changed' => ['Your Business Book password changed', ['Your account password was changed.', 'If you did not make this change, reset your password and review account access immediately.'], 'Review account', $home.'/account'],
            'invitation' => ['Business Book invitation', ['You have been invited to join a business.', 'Sign in with the invited email address and review the invitation before accepting.'], 'Review invitation', null],
            'payment_receipt' => ['Business Book package payment confirmed', ['Your package payment was confirmed. View account package access and payment history.'], 'View packages', $home.'/billing'],
            'trial_reminder' => ['Your Business Book trial is ending', ['Your account trial is nearing its end. Review package access and available plans.', 'Adding businesses does not restart the trial.'], 'Review packages', $home.'/billing'],
            'expiry_reminder' => ['Review your Business Book package expiry', ['Your package is nearing expiry or has expired. Review your current access and renewal options.', 'Expired owners and accountants retain existing-record read and export access.'], 'Review packages', $home.'/billing'],
            'promotion' => ['News from Business Book', [(string) ($context['body'] ?? '')], 'Open Business Book', $home.'/businesses'],
        ];
        if (! isset($definitions[$event])) {
            throw new \InvalidArgumentException('Account email event unavailable.');
        }
        [$subject, $lines, $label, $url] = $definitions[$event];
        if ($event === 'payment_receipt') {
            $minor = $context['amount_minor'] ?? null;
            $currency = $context['currency'] ?? null;
            if (! is_string($minor) || ! preg_match('/^[1-9][0-9]{0,11}$/D', $minor) || ! in_array($currency, ['NPR', 'USD', 'EUR'], true)) {
                throw new \InvalidArgumentException('Receipt amount unavailable.');
            }
            $lines[] = 'Payment: '.$currency.' '.Money::format((int) $minor).'.';
            if (isset($context['reference'])) {
                $lines[] = 'Reference: '.$context['reference'];
            }
        }

        return ['subject' => $subject, 'title' => $subject, 'recipient_name' => $name, 'lines' => $lines,
            'action_label' => $label, 'action_url' => $this->actionUrl($context['url'] ?? $url), 'unsubscribe_url' => $event === 'promotion' ? $this->actionUrl($context['unsubscribe_url'] ?? null) : null];
    }

    public function mailMessage(string $event, string $name, array $context): MailMessage
    {
        $data = $this->message($event, $name, $context);

        return (new MailMessage)->subject($data['subject'])->view('emails.account-message', $data)->text('emails.account-message-text', $data);
    }
}
