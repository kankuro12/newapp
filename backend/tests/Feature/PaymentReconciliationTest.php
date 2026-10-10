<?php

namespace Tests\Feature;

use App\Models\User;
use App\Service\GatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Reconcile shop'])->assertCreated()->json('data');
        DB::table('billing_package_prices')->insert(['package_id' => 1, 'currency' => 'NPR', 'amount_minor' => 10000]);
        $gateway = Mockery::mock(GatewayService::class);
        $gateway->shouldReceive('supported')->andReturn(true);
        $gateway->shouldReceive('ready')->andReturn(true);
        $gateway->shouldReceive('initiate')->once()->andReturn(['provider_id' => 'stored-pidx', 'checkout' => ['type' => 'redirect', 'url' => 'https://test-pay.khalti.com/pay']]);
        $this->app->instance(GatewayService::class, $gateway);
        $attempt = $this->postJson('/api/billing/accounts/'.$business['billing_account_id'].'/checkout', ['mutation_uuid' => (string) Str::uuid(), 'package_id' => 1, 'gateway' => 'khalti', 'currency' => 'NPR'])->assertCreated()->json('data');

        return [$gateway, $attempt];
    }

    public function test_scheduler_verifies_pending_payment_and_extends_only_once(): void
    {
        [$gateway, $attempt] = $this->pending();
        $gateway->shouldReceive('verify')->once()->andReturn(['status' => 'verified', 'reference' => $attempt['reference'], 'provider_transaction_id' => 'reconciled-txn', 'amount_minor' => '10000', 'currency' => 'NPR']);
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->assertDatabaseHas('subscription_payments', ['reference' => $attempt['reference'], 'status' => 'verified']);
        $this->assertDatabaseCount('billing_subscriptions', 2);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_missing_provider_identifier_never_reinitiates_charge(): void
    {
        [$gateway, $attempt] = $this->pending();
        $gateway->shouldNotReceive('verify');
        DB::table('subscription_payments')->where('reference', $attempt['reference'])->update(['status' => 'unknown', 'provider_id' => null]);
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->assertDatabaseHas('subscription_payments', ['reference' => $attempt['reference'], 'status' => 'unknown', 'last_error_code' => 'provider_identifier_missing']);
        $this->assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_provider_failure_preserves_attempt_and_defers_retry(): void
    {
        [$gateway, $attempt] = $this->pending();
        $gateway->shouldReceive('verify')->once()->andThrow(new \RuntimeException('sensitive provider failure'));
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $row = DB::table('subscription_payments')->where('reference', $attempt['reference'])->first();
        $this->assertSame('provider_unavailable', $row->last_error_code);
        $this->assertNotNull($row->next_check_at);
        $this->assertNull($row->reconcile_until);
        $this->assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_scheduler_leaves_inflight_initiation_unmodified(): void
    {
        [$gateway, $attempt] = $this->pending();
        $gateway->shouldNotReceive('verify');
        DB::table('subscription_payments')->where('reference', $attempt['reference'])->update(['status' => 'initiating', 'provider_id' => null, 'updated_at' => now()]);
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->assertDatabaseHas('subscription_payments', ['reference' => $attempt['reference'], 'last_checked_at' => null, 'last_error_code' => null]);
    }

    public function test_pending_response_defers_next_lookup_without_activating(): void
    {
        [$gateway, $attempt] = $this->pending();
        $gateway->shouldReceive('verify')->once()->andReturn(['status' => 'pending']);
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->assertDatabaseHas('subscription_payments', ['reference' => $attempt['reference'], 'status' => 'pending', 'reconcile_token' => null]);
        $this->assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_active_lease_is_skipped(): void
    {
        [$gateway, $attempt] = $this->pending();
        $gateway->shouldNotReceive('verify');
        DB::table('subscription_payments')->where('reference', $attempt['reference'])->update(['reconcile_until' => now()->addMinutes(2)]);
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->assertDatabaseCount('billing_subscriptions', 1);
    }
}
