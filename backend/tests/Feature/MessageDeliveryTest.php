<?php

namespace Tests\Feature;

use App\Jobs\SendAccountMessage;
use App\Mail\AccountMessage;
use App\Models\User;
use App\Service\BillingService;
use App\Service\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_replay_creates_one_delivery_and_one_send(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $ids = $service->enqueue('welcome', $user->id, [], ['email'], 'welcome-1');
        $this->assertSame($ids, $service->enqueue('welcome', $user->id, [], ['email'], 'welcome-1'));
        $service->deliver($ids[0]);
        $service->deliver($ids[0]);
        Mail::assertSent(AccountMessage::class, 1);
        $this->assertDatabaseCount('message_deliveries', 1);
        $this->assertDatabaseHas('message_deliveries', ['id' => $ids[0], 'status' => 'sent']);
    }

    public function test_rollback_leaves_no_delivery_or_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        try {
            DB::transaction(function () use ($user) {
                app(NotificationService::class)->enqueue('welcome', $user->id, [], ['email'], 'rolled-back');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('message_deliveries', 0);
        Queue::assertNotPushed(SendAccountMessage::class);
    }

    public function test_disabled_recipient_and_later_optout_skip_delivery(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create();
        $service = app(NotificationService::class);
        $welcome = $service->enqueue('welcome', $user->id, [], ['email'], 'disabled');
        DB::table('users')->where('id', $user->id)->update(['disabled_at' => now()]);
        $service->deliver($welcome[0]);
        DB::table('users')->where('id', $user->id)->update(['disabled_at' => null]);
        DB::table('notification_preferences')->insert(['user_id' => $user->id, 'promotional_email' => true]);
        $promotion = $service->enqueue('promotion', $user->id, ['body' => 'Offer'], ['email'], 'promotion-1');
        DB::table('notification_preferences')->where('user_id', $user->id)->update(['promotional_email' => false]);
        $service->deliver($promotion[0]);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('message_deliveries', ['id' => $promotion[0], 'status' => 'skipped', 'error_code' => 'consent_missing']);
    }

    public function test_reset_tokens_cannot_enter_durable_history(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->expectException(\InvalidArgumentException::class);
        app(NotificationService::class)->enqueue('password_reset', $user->id, ['token' => 'secret'], ['email'], 'reset');
    }

    public function test_registration_queues_welcome_after_successful_creation(): void
    {
        Queue::fake();
        Notification::fake();
        $this->postJson('/register', ['name' => 'Welcome owner', 'email' => 'welcome@example.test', 'country_code' => 'NP', 'phone' => '9801234567', 'password' => 'password-123', 'password_confirmation' => 'password-123'])->assertCreated();
        $this->assertDatabaseHas('message_deliveries', ['event' => 'welcome', 'channel' => 'email']);
        Queue::assertPushed(SendAccountMessage::class);
    }

    public function test_password_change_uses_tenant_guard_and_only_success_queues_notice(): void
    {
        Queue::fake();
        $user = User::factory()->create(['password' => Hash::make('old-pass-123')]);
        $this->actingAs($user, 'tenant');
        $input = ['current_password' => 'wrong', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'];
        $this->putJson('/user/password', $input)->assertUnprocessable();
        $this->assertDatabaseCount('message_deliveries', 0);
        $this->putJson('/user/password', [...$input, 'current_password' => 'old-pass-123'])->assertOk();
        $this->assertDatabaseHas('message_deliveries', ['event' => 'password_changed', 'user_id' => $user->id]);
        $this->assertTrue(Hash::check('new-pass-123', $user->fresh()->password));
    }

    public function test_revoked_account_membership_skips_queued_receipt(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create();
        $account = app(BillingService::class)->createAccount($user->id, 'Billing account');
        $id = app(NotificationService::class)->enqueue('payment_receipt', $user->id, ['currency' => 'NPR', 'amount_minor' => '10000', 'reference' => 'paid-reference'], ['email'], 'receipt', $account->id)[0];
        DB::table('billing_account_user')->where('billing_account_id', $account->id)->where('user_id', $user->id)->update(['active' => false]);
        app(NotificationService::class)->deliver($id);
        $this->assertDatabaseHas('message_deliveries', ['id' => $id, 'status' => 'skipped', 'error_code' => 'membership_revoked']);
        Mail::assertNothingSent();
    }

    public function test_renewal_replaces_old_subscription_reminder(): void
    {
        Queue::fake();
        Mail::fake();
        $user = User::factory()->create();
        $billing = app(BillingService::class);
        $account = $billing->createAccount($user->id, 'Renewal account');
        $subscription = $billing->entitlements($account->id)['subscription_id'];
        $id = app(NotificationService::class)->enqueue('trial_reminder', $user->id, ['subscription_id' => $subscription], ['email'], 'trial-reminder', $account->id)[0];
        $row = DB::table('billing_subscriptions')->where('id', $subscription)->first();
        DB::table('billing_subscriptions')->insert(['billing_account_id' => $account->id, 'package_id' => $row->package_id, 'package_snapshot' => $row->package_snapshot, 'status' => 'active', 'start_at' => now(), 'end_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        app(NotificationService::class)->deliver($id);
        $this->assertDatabaseHas('message_deliveries', ['id' => $id, 'status' => 'skipped', 'error_code' => 'subscription_changed']);
        Mail::assertNothingSent();
    }

    public function test_job_payload_exposes_delivery_id_without_recipient_context(): void
    {
        $payload = serialize(new SendAccountMessage(73));
        $this->assertStringContainsString('deliveryId', $payload);
        $this->assertStringNotContainsString('context_encrypted', $payload);
        $this->assertStringNotContainsString('recipientName', $payload);
    }

    public function test_provider_exception_records_unknown_without_blind_resend(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ids = app(NotificationService::class)->enqueue('welcome', $user->id, [], ['email'], 'uncertain');
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP result uncertain'));
        app(NotificationService::class)->deliver($ids[0]);
        app(NotificationService::class)->deliver($ids[0]);
        $this->assertDatabaseHas('message_deliveries', ['id' => $ids[0], 'status' => 'unknown', 'error_code' => 'provider_outcome_unknown']);
    }
}
