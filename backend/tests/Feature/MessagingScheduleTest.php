<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use App\Models\User;
use App\Service\CampaignService;
use App\Service\NotificationService;
use App\Service\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MessagingScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function trialOwner(array $attributes = []): array
    {
        $owner = User::factory()->create(['phone' => '9800000000', 'country_code' => 'NP', ...$attributes]);
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Reminder shop'])->assertCreated()->json('data');

        return [$owner, $business, DB::table('billing_subscriptions')->where('billing_account_id', $business['billing_account_id'])->first()];
    }

    private function whatsappConfigured(): void
    {
        config(['services.whatsapp.token' => 'test-token', 'services.whatsapp.phone_number_id' => '1234567890', 'services.whatsapp.templates.trial_reminder' => 'trial_ending', 'services.whatsapp.templates.promotion' => 'promotion_news']);
    }

    public function test_thresholds_follow_nepal_calendar_days(): void
    {
        $reminders = app(ReminderService::class);
        $now = Carbon::parse('2026-10-10 20:00:00', 'Asia/Kathmandu');
        // 01:00 Nepal on the 17th is still the 16th in UTC; the Nepal calendar decides.
        $this->assertSame('7d', $reminders->threshold(Carbon::parse('2026-10-17 01:00:00', 'Asia/Kathmandu')->utc()->toIso8601String(), $now->copy()->utc()));
        $this->assertSame('1d', $reminders->threshold(Carbon::parse('2026-10-11 08:00:00', 'Asia/Kathmandu')->utc()->toIso8601String(), $now->copy()->utc()));
        $this->assertNull($reminders->threshold(Carbon::parse('2026-10-15 08:00:00', 'Asia/Kathmandu')->utc()->toIso8601String(), $now->copy()->utc()));
        $this->assertSame('expired', $reminders->threshold(Carbon::parse('2026-10-10 10:00:00', 'Asia/Kathmandu')->utc()->toIso8601String(), $now->copy()->utc()));
    }

    public function test_reminders_are_queued_once_per_threshold_and_whatsapp_needs_consent(): void
    {
        Queue::fake();
        [$owner, , $trial] = $this->trialOwner();
        $this->travelTo(Carbon::parse($trial->end_at)->subDays(3)->addHour());
        $this->artisan('app:send-package-reminders')->assertSuccessful();
        app(ReminderService::class)->queueDue();
        app(ReminderService::class)->queueDue();
        $this->assertSame(3, DB::table('message_deliveries')->where('event', 'trial_reminder')->count());
        $this->whatsappConfigured();
        $whatsapp = DB::table('message_deliveries')->where('event', 'trial_reminder')->where('channel', 'whatsapp')->value('id');
        app(NotificationService::class)->deliver($whatsapp);
        $this->assertDatabaseHas('message_deliveries', ['id' => $whatsapp, 'status' => 'skipped', 'error_code' => 'consent_missing']);
    }

    public function test_whatsapp_template_is_sent_to_full_international_number_when_opted_in(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);
        [$owner, , $trial] = $this->trialOwner();
        $this->whatsappConfigured();
        app(NotificationService::class)->preferences($owner->id, ['operational_whatsapp' => true]);
        $id = app(NotificationService::class)->enqueue('trial_reminder', $owner->id, ['subscription_id' => (string) $trial->id, 'end_at' => Carbon::parse($trial->end_at)->toIso8601String()], ['whatsapp'], 'manual', (int) $trial->billing_account_id)[0];
        app(NotificationService::class)->deliver($id);
        $this->assertDatabaseHas('message_deliveries', ['id' => $id, 'status' => 'sent', 'provider_id' => 'wamid.TEST']);
        Http::assertSent(fn ($request) => $request['to'] === '9779800000000' && $request['template']['name'] === 'trial_ending');
    }

    public function test_missing_phone_skips_only_whatsapp(): void
    {
        Queue::fake();
        [$owner, , $trial] = $this->trialOwner(['phone' => null]);
        $this->whatsappConfigured();
        app(NotificationService::class)->preferences($owner->id, ['operational_whatsapp' => true]);
        $id = app(NotificationService::class)->enqueue('trial_reminder', $owner->id, ['subscription_id' => (string) $trial->id, 'end_at' => (string) $trial->end_at], ['whatsapp'], 'manual', (int) $trial->billing_account_id)[0];
        app(NotificationService::class)->deliver($id);
        $this->assertDatabaseHas('message_deliveries', ['id' => $id, 'status' => 'skipped', 'error_code' => 'phone_missing']);
    }

    public function test_campaign_preview_schedule_send_and_cancel_are_superadmin_only(): void
    {
        Queue::fake();
        [$owner] = $this->trialOwner();
        app(NotificationService::class)->preferences($owner->id, ['promotional_email' => true]);
        $this->getJson('/api/platform/campaigns')->assertUnauthorized();
        $admin = SuperAdmin::create(['name' => 'Platform', 'email' => 'admin@example.com', 'password' => bcrypt('secret-password')]);
        $this->actingAs($admin, 'superadmin');
        $campaign = $this->postJson('/api/platform/campaigns', ['name' => 'Festival', 'body' => 'Festival offer for all owners.', 'channels' => ['email', 'whatsapp'], 'audience' => 'trial'])->assertCreated()->json('data');
        $this->getJson('/api/platform/campaigns/'.$campaign['id'].'/preview')->assertOk()->assertJsonPath('data.recipients', '1')->assertJsonPath('data.consented.email', '1');
        $this->postJson('/api/platform/campaigns/'.$campaign['id'].'/schedule', ['version' => 1, 'password' => 'wrong', 'reason' => 'Send festival offer'])->assertForbidden();
        $this->postJson('/api/platform/campaigns/'.$campaign['id'].'/schedule', ['version' => 1, 'password' => 'secret-password', 'reason' => 'Send festival offer'])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->artisan('app:dispatch-campaigns')->assertSuccessful();
        app(CampaignService::class)->send((int) $campaign['id']);
        $this->assertSame(2, DB::table('message_deliveries')->where('campaign_id', $campaign['id'])->count());
        $this->postJson('/api/platform/campaigns/'.$campaign['id'].'/cancel', ['password' => 'secret-password', 'reason' => 'Already sent'])->assertConflict();

        $second = $this->postJson('/api/platform/campaigns', ['name' => 'Later', 'body' => 'Later offer body.', 'channels' => ['email'], 'audience' => 'all'])->assertCreated()->json('data');
        $this->postJson('/api/platform/campaigns/'.$second['id'].'/schedule', ['version' => 1, 'scheduled_at' => now()->addDay()->toIso8601String(), 'password' => 'secret-password', 'reason' => 'Schedule later'])->assertOk();
        $this->postJson('/api/platform/campaigns/'.$second['id'].'/cancel', ['password' => 'secret-password', 'reason' => 'Plan changed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->travel(2)->days();
        $this->artisan('app:dispatch-campaigns')->assertSuccessful();
        $this->assertSame(0, DB::table('message_deliveries')->where('campaign_id', $second['id'])->count());
    }

    public function test_platform_refund_revokes_paid_term_with_audit(): void
    {
        [$owner, $business] = $this->trialOwner();
        $account = $business['billing_account_id'];
        DB::table('billing_subscriptions')->where('billing_account_id', $account)->update(['end_at' => now()->subMinute()]);
        $subscription = DB::table('billing_subscriptions')->insertGetId(['billing_account_id' => $account, 'package_id' => 1, 'package_snapshot' => DB::table('billing_subscriptions')->where('billing_account_id', $account)->value('package_snapshot'), 'status' => 'active', 'start_at' => now(), 'end_at' => now()->addDays(30), 'applied_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $payment = DB::table('subscription_payments')->insertGetId(['billing_account_id' => $account, 'actor_id' => $owner->id, 'mutation_uuid' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'reference' => (string) Str::uuid(), 'gateway' => 'khalti', 'currency' => 'NPR', 'amount_minor' => 10000, 'package_snapshot' => '{}', 'retained_business_ids' => '[]', 'status' => 'verified', 'provider_transaction_id' => 'refund-me', 'subscription_id' => $subscription, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/billing/accounts/'.$account.'/subscription')->assertJsonPath('data.status', 'active');
        $this->actingAs(SuperAdmin::create(['name' => 'Platform', 'email' => 'admin@example.com', 'password' => bcrypt('secret-password')]), 'superadmin');
        $this->postJson('/api/platform/payments/'.$payment.'/revoke', ['status' => 'refunded', 'password' => 'secret-password', 'reason' => 'Customer refund'])->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->assertDatabaseHas('billing_audit_logs', ['action' => 'payment.refunded', 'subject_id' => $payment]);
        $this->actingAs($owner, 'tenant');
        $this->getJson('/api/billing/accounts/'.$account.'/subscription')->assertJsonPath('data.status', 'expired');
        $this->postJson('/api/app/'.$business['slug'].'/payments', [])->assertForbidden();
    }
}
