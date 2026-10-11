<?php

namespace Tests\Feature;

use App\Models\User;
use App\Service\GatewayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class BillingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function checkout(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Paid shop'])->assertCreated()->json('data');
        DB::table('billing_package_prices')->insert(['package_id' => 1, 'currency' => 'NPR', 'amount_minor' => 10000]);
        $gateway = Mockery::mock(GatewayService::class);
        $gateway->shouldReceive('supported')->andReturn(true);
        $gateway->shouldReceive('ready')->andReturn(true);
        $gateway->shouldReceive('initiate')->andReturn(['provider_id' => 'checkout-1', 'checkout' => ['type' => 'redirect', 'url' => 'https://example.com/pay']]);
        $this->app->instance(GatewayService::class, $gateway);
        $input = ['mutation_uuid' => (string) Str::uuid(), 'package_id' => 1, 'gateway' => 'khalti', 'currency' => 'NPR'];

        return compact('owner', 'business', 'gateway', 'input');
    }

    public function test_checkout_retries_and_verified_settlement_extend_once_without_ledger_posting(): void
    {
        $s = $this->checkout();
        $url = '/api/billing/accounts/'.$s['business']['billing_account_id'].'/checkout';
        $first = $this->postJson($url, $s['input'])->assertCreated()->json('data');
        $this->postJson($url, $s['input'])->assertOk()->assertJsonPath('data.reference', $first['reference']);
        $this->postJson($url, [...$s['input'], 'gateway' => 'esewa'])->assertConflict();
        $this->postJson($url, [...$s['input'], 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $s['gateway']->shouldReceive('verify')->andReturn(['status' => 'verified', 'reference' => $first['reference'], 'provider_transaction_id' => 'txn-1', 'amount_minor' => '10000', 'currency' => 'NPR']);
        $confirmation = '/api/billing/accounts/'.$s['business']['billing_account_id'].'/payments/'.$first['reference'].'/confirm';
        $this->postJson($confirmation, ['status' => 'verified', 'amount_minor' => '1'])->assertOk()->assertJsonPath('data.status', 'verified');
        $end = DB::table('billing_subscriptions')->orderByDesc('id')->value('end_at');
        $this->postJson($confirmation)->assertOk();
        $this->assertSame($end, DB::table('billing_subscriptions')->orderByDesc('id')->value('end_at'));
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseCount('billing_subscriptions', 2);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_payment_history_and_business_selection_are_owner_scoped(): void
    {
        $s = $this->checkout();
        $account = $s['business']['billing_account_id'];
        $this->postJson('/api/billing/accounts/'.$account.'/checkout', $s['input'])->assertCreated();
        $this->getJson('/api/billing/accounts/'.$account.'/payments')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/billing/accounts/'.$account.'/businesses')->assertOk()->assertJsonPath('data.0.id', $s['business']['id']);
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->getJson('/api/billing/accounts/'.$account.'/payments')->assertNotFound();
        $this->getJson('/api/billing/accounts/'.$account.'/businesses')->assertNotFound();
    }

    public function test_unconfigured_gateway_creates_no_payment_attempt(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Unconfigured shop'])->assertCreated()->json('data');
        config(['billing.khalti.secret' => null]);
        DB::table('billing_package_prices')->insert(['package_id' => 1, 'currency' => 'NPR', 'amount_minor' => 10000]);
        $this->postJson('/api/billing/accounts/'.$business['billing_account_id'].'/checkout', ['mutation_uuid' => (string) Str::uuid(), 'package_id' => 1, 'gateway' => 'khalti', 'currency' => 'NPR'])->assertStatus(503);
        $this->assertDatabaseCount('subscription_payments', 0);
    }

    public function test_canonical_failed_payment_status_is_saved_without_extension(): void
    {
        $s = $this->checkout();
        $account = $s['business']['billing_account_id'];
        $attempt = $this->postJson('/api/billing/accounts/'.$account.'/checkout', $s['input'])->assertCreated()->json('data');
        $s['gateway']->shouldReceive('verify')->andReturn(['status' => 'failed']);
        $this->postJson('/api/billing/accounts/'.$account.'/payments/'.$attempt['reference'].'/confirm')->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseCount('billing_subscriptions', 1);
    }

    public function test_explicit_empty_selection_does_not_reenable_existing_businesses(): void
    {
        $s = $this->checkout();
        $account = $s['business']['billing_account_id'];
        $attempt = $this->postJson('/api/billing/accounts/'.$account.'/checkout', [...$s['input'], 'retained_business_ids' => []])->assertCreated()->json('data');
        $s['gateway']->shouldReceive('verify')->andReturn(['status' => 'verified', 'reference' => $attempt['reference'], 'provider_transaction_id' => 'empty-selection', 'amount_minor' => '10000', 'currency' => 'NPR']);
        $this->postJson('/api/billing/accounts/'.$account.'/payments/'.$attempt['reference'].'/confirm')->assertOk();
        // The paid term is stacked after the running trial, so the trial allowance holds until it ends.
        $this->assertTrue((bool) DB::table('tenants')->where('id', $s['business']['id'])->value('package_enabled'));
        $this->travelTo(DB::table('billing_subscriptions')->orderByDesc('id')->value('start_at'));
        $this->travel(1)->minutes();
        $this->artisan('app:apply-subscription-terms')->assertSuccessful();
        $this->assertFalse((bool) DB::table('tenants')->where('id', $s['business']['id'])->value('package_enabled'));
    }

    public function test_early_renewal_keeps_current_terms_until_the_stacked_term_starts(): void
    {
        $s = $this->checkout();
        $account = $s['business']['billing_account_id'];
        $trial = DB::table('billing_subscriptions')->where('billing_account_id', $account)->first();
        $attempt = $this->postJson('/api/billing/accounts/'.$account.'/checkout', $s['input'])->assertCreated()->json('data');
        $s['gateway']->shouldReceive('verify')->andReturn(['status' => 'verified', 'reference' => $attempt['reference'], 'provider_transaction_id' => 'early', 'amount_minor' => '10000', 'currency' => 'NPR']);
        $this->postJson('/api/billing/accounts/'.$account.'/payments/'.$attempt['reference'].'/confirm')->assertOk();
        $this->getJson('/api/billing/accounts/'.$account.'/subscription')->assertOk()->assertJsonPath('data.status', 'trial')
            ->assertJsonPath('data.subscription_id', (string) $trial->id)->assertJsonPath('data.next.start_at', $trial->end_at);
        $this->travelTo($trial->end_at);
        $this->travel(1)->minutes();
        $this->getJson('/api/billing/accounts/'.$account.'/subscription')->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.next', null);
    }

    public function test_owner_can_abandon_stuck_checkout_and_late_completion_still_settles(): void
    {
        $s = $this->checkout();
        $account = $s['business']['billing_account_id'];
        $attempt = $this->postJson('/api/billing/accounts/'.$account.'/checkout', $s['input'])->assertCreated()->json('data');
        $s['gateway']->shouldReceive('verify')->once()->andReturn(['status' => 'pending']);
        $this->postJson('/api/billing/accounts/'.$account.'/payments/'.$attempt['reference'].'/abandon')->assertOk()->assertJsonPath('data.status', 'abandoned');
        $this->postJson('/api/billing/accounts/'.$account.'/payments/'.$attempt['reference'].'/abandon')->assertConflict();
        $this->postJson('/api/billing/accounts/'.$account.'/checkout', [...$s['input'], 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $s['gateway']->shouldReceive('verify')->andReturn(['status' => 'verified', 'reference' => $attempt['reference'], 'provider_transaction_id' => 'late', 'amount_minor' => '10000', 'currency' => 'NPR']);
        $this->travel(16)->minutes();
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        $this->assertDatabaseHas('subscription_payments', ['reference' => $attempt['reference'], 'status' => 'verified']);
    }

    public function test_second_billing_account_does_not_receive_another_trial(): void
    {
        $s = $this->checkout();
        $second = $this->postJson('/api/billing/accounts', ['name' => 'Second account'])->assertCreated()->json('data');
        $this->assertDatabaseMissing('billing_subscriptions', ['billing_account_id' => $second['id']]);
        $this->postJson('/api/billing/accounts/'.$second['id'].'/trial', ['package_id' => 1])->assertConflict();
    }

    public function test_foreign_owner_and_provider_amount_mismatch_cannot_activate_access(): void
    {
        $s = $this->checkout();
        $account = $s['business']['billing_account_id'];
        $attempt = $this->postJson('/api/billing/accounts/'.$account.'/checkout', $s['input'])->assertCreated()->json('data');
        $s['gateway']->shouldReceive('verify')->andReturn(['status' => 'verified', 'reference' => $attempt['reference'], 'provider_transaction_id' => 'mismatch', 'amount_minor' => '9999', 'currency' => 'NPR']);
        $url = '/api/billing/accounts/'.$account.'/payments/'.$attempt['reference'].'/confirm';
        $this->postJson($url)->assertUnprocessable();
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->postJson($url)->assertNotFound();
    }
}
