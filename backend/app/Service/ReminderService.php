<?php

namespace App\Service;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily package expiry reminders: 7, 3 and 1 Nepal calendar days before the end, then once on expiry.
 */
class ReminderService
{
    public const THRESHOLDS = [7, 3, 1];

    public const CHANNELS = ['email', 'fcm', 'whatsapp'];

    public function threshold(string $endAt, ?Carbon $now = null): ?string
    {
        $now = ($now ?? now())->copy()->setTimezone('Asia/Kathmandu');
        $end = Carbon::parse($endAt)->setTimezone('Asia/Kathmandu');
        if ($end->lte($now)) {
            return $end->gt($now->copy()->subDay()) ? 'expired' : null;
        }
        $days = (int) $now->copy()->startOfDay()->diffInDays($end->copy()->startOfDay());

        return in_array($days, self::THRESHOLDS, true) ? $days.'d' : null;
    }

    /**
     * Queues durable deliveries; the event key makes repeated or overlapping runs harmless.
     */
    public function queueDue(): int
    {
        $billing = app(BillingService::class);
        $notifications = app(NotificationService::class);
        $queued = 0;
        DB::table('billing_subscriptions')->whereIn('status', ['trial', 'active'])->whereNull('revoked_at')
            ->where('start_at', '<=', now())->whereBetween('end_at', [now()->subDay(), now()->addDays(8)])
            ->orderBy('id')->chunkById(200, function ($subscriptions) use ($billing, $notifications, &$queued) {
                foreach ($subscriptions as $subscription) {
                    $threshold = $this->threshold($subscription->end_at);
                    $current = $billing->entitlements((int) $subscription->billing_account_id);
                    // Renewed (stacked term) or replaced accounts need no reminder; suspended ones get none.
                    if (! $threshold || $current['status'] === 'suspended' || ($current['next'] ?? null) || ($current['subscription_id'] ?? null) !== (string) $subscription->id) {
                        continue;
                    }
                    $owners = DB::table('billing_account_user')->join('users', 'users.id', '=', 'billing_account_user.user_id')
                        ->where('billing_account_id', $subscription->billing_account_id)->where('billing_account_user.role', 'owner')
                        ->where('billing_account_user.active', true)->whereNull('users.disabled_at')->pluck('users.id');
                    foreach ($owners as $owner) {
                        $notifications->enqueue($subscription->status === 'trial' ? 'trial_reminder' : 'expiry_reminder', (int) $owner,
                            ['subscription_id' => (string) $subscription->id, 'end_at' => Carbon::parse($subscription->end_at)->toIso8601String()],
                            self::CHANNELS, 'reminder:'.$subscription->id.':'.$threshold, (int) $subscription->billing_account_id);
                        $queued++;
                    }
                }
            });

        return $queued;
    }
}
