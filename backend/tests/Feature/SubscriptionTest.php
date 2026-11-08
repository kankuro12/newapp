<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_businesses_share_one_trial_clock_and_cannot_restart_it(): void
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $first = $this->postJson('/api/businesses', ['name' => 'One'])->assertCreated()->json('data');
        $this->travel(2)->days();
        $second = $this->postJson('/api/businesses', ['name' => 'Two'])->assertCreated()->json('data');
        $this->assertSame($first['trial_ends_at'], $second['trial_ends_at']);
        $this->assertDatabaseCount('billing_subscriptions', 1);
        $this->postJson('/api/billing/accounts/'.$first['billing_account_id'].'/trial', ['package_id' => 1])->assertConflict();
        $this->travel(13)->days();
        $this->postJson('/api/businesses', ['name' => 'Restart'])->assertForbidden();
        $this->getJson('/api/app/'.$first['slug'].'/lookup')->assertOk();
        $this->getJson('/api/billing/accounts/'.$first['billing_account_id'].'/subscription')->assertOk()->assertJsonPath('data.status', 'expired');
    }

    public function test_package_limits_and_product_entitlements_are_server_enforced(): void
    {
        DB::table('billing_packages')->where('id', 1)->update(['max_businesses' => 1, 'products' => json_encode(['bookkeeping'])]);
        $this->actingAs(User::factory()->create(), 'tenant');
        $first = $this->postJson('/api/businesses', ['name' => 'One'])->assertCreated()->json('data');
        $this->postJson('/api/businesses', ['name' => 'Two'])->assertUnprocessable();
        $this->getJson('/api/app/'.$first['slug'].'/lookup')->assertOk();
        $this->getJson('/api/app/'.$first['slug'].'/restaurant/orders')->assertForbidden();
        DB::table('billing_accounts')->where('id', $first['billing_account_id'])->update(['status' => 'suspended']);
        $this->getJson('/api/app/'.$first['slug'].'/lookup')->assertForbidden();
        $this->getJson('/api/billing/accounts/'.$first['billing_account_id'].'/subscription')->assertOk();
    }

    public function test_platform_business_list_serialises_dates_as_strings(): void
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->postJson('/api/businesses', ['name' => 'Dated'])->assertCreated();
        $this->actingAs(SuperAdmin::create(['name' => 'Platform', 'email' => 'admin@example.com', 'password' => bcrypt('secret-password')]), 'superadmin');
        $this->assertIsString($this->getJson('/api/platform/tenants')->assertOk()->json('data.0.trial_ends_at'));
    }

    public function test_platform_packages_require_separate_guard_and_snapshot_existing_terms(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $first = $this->postJson('/api/businesses', ['name' => 'One'])->assertCreated()->json('data');
        $this->getJson('/api/platform/packages')->assertUnauthorized();
        $admin = SuperAdmin::create(['name' => 'Platform', 'email' => 'admin@example.com', 'password' => bcrypt('secret-password')]);
        $this->actingAs($admin, 'superadmin');
        $this->getJson('/api/platform/packages')->assertOk();
        $package = ['name' => 'Edited', 'duration_days' => 30, 'trial_days' => 3, 'max_businesses' => 1, 'products' => ['bookkeeping'], 'prices' => [['currency' => 'NPR', 'amount_minor' => '10000']], 'active' => true, 'version' => (int) DB::table('billing_packages')->where('id', 1)->value('version'), 'password' => 'secret-password', 'reason' => 'Package test update'];
        $this->patchJson('/api/platform/packages/1', $package)->assertOk();
        $this->patchJson('/api/platform/packages/1', $package)->assertConflict();
        $this->actingAs($owner, 'tenant');
        $this->getJson('/api/billing/accounts/'.$first['billing_account_id'].'/subscription')->assertOk()->assertJsonPath('data.max_businesses', 100)->assertJsonPath('data.status', 'trial');
    }

    public function test_platform_unlimited_test_account_lifts_limits_and_expiry_until_removed(): void
    {
        DB::table('billing_packages')->where('id', 1)->update(['max_businesses' => 1, 'products' => json_encode(['bookkeeping'])]);
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $first = $this->postJson('/api/businesses', ['name' => 'One'])->assertCreated()->json('data');
        $this->postJson('/api/businesses', ['name' => 'Two'])->assertUnprocessable();
        $account = (int) $first['billing_account_id'];

        $admin = SuperAdmin::create(['name' => 'Platform', 'email' => 'admin@example.com', 'password' => bcrypt('secret-password')]);
        $this->actingAs($admin, 'superadmin');
        $grant = ['unlimited' => true, 'version' => (int) DB::table('billing_accounts')->where('id', $account)->value('version'), 'reason' => 'QA test account', 'password' => 'wrong'];
        $this->patchJson("/api/platform/accounts/$account/unlimited", $grant)->assertForbidden();
        $this->patchJson("/api/platform/accounts/$account/unlimited", ['password' => 'secret-password'] + $grant)->assertOk();
        $this->getJson('/api/platform/accounts')->assertOk()->assertJsonPath('data.0.unlimited', true)->assertJsonPath('data.0.package', 'Unlimited test account');
        $this->assertDatabaseHas('billing_audit_logs', ['action' => 'account.unlimited', 'subject_id' => $account]);

        $this->actingAs($owner, 'tenant');
        $this->travel(400)->days();
        $restaurant = $this->postJson('/api/businesses', ['name' => 'Two', 'business_type' => 'restaurant', 'billing_account_id' => $account])->assertCreated()->json('data');
        $this->getJson('/api/app/'.$restaurant['slug'].'/restaurant/orders')->assertOk();
        $this->postJson('/api/businesses', ['name' => 'Three', 'billing_account_id' => $account])->assertCreated();
        $this->getJson("/api/billing/accounts/$account/subscription")->assertOk()->assertJsonPath('data.unlimited', true)->assertJsonPath('data.max_businesses', null);

        $this->actingAs($admin, 'superadmin');
        $version = (int) DB::table('billing_accounts')->where('id', $account)->value('version');
        $this->patchJson("/api/platform/accounts/$account/unlimited", ['unlimited' => false, 'version' => $version, 'reason' => 'QA finished', 'password' => 'secret-password'])->assertOk();
        $this->actingAs($owner, 'tenant');
        // The old trial ended long ago, so the books stay readable but writing and new businesses stop.
        $this->getJson('/api/app/'.$first['slug'].'/lookup')->assertOk();
        $this->postJson('/api/businesses', ['name' => 'Four', 'billing_account_id' => $account])->assertForbidden();
        $this->getJson("/api/billing/accounts/$account/subscription")->assertOk()->assertJsonPath('data.status', 'expired');
    }

    public function test_legacy_access_preserves_existing_books_without_issuing_a_new_trial(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Existing'])->assertCreated()->json('data');
        DB::table('billing_subscriptions')->where('billing_account_id', $business['billing_account_id'])->delete();
        DB::table('billing_accounts')->where('id', $business['billing_account_id'])->update(['legacy_access' => true]);
        $this->getJson('/api/app/'.$business['slug'].'/lookup')->assertOk();
        $this->postJson('/api/businesses', ['name' => 'New trial'])->assertForbidden();
        $this->postJson('/api/billing/accounts/'.$business['billing_account_id'].'/trial', ['package_id' => 1])->assertConflict();
    }

    public function test_business_creation_validates_one_package_enabled_type(): void
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->postJson('/api/businesses', ['name' => 'Restaurant', 'business_type' => 'restaurant'])->assertCreated()->assertJsonPath('data.pos_profile', 'restaurant');
        $this->postJson('/api/businesses', ['name' => 'Invalid', 'business_type' => 'restaurant,gym'])->assertUnprocessable();
        $account = DB::table('billing_accounts')->first();
        $subscription = DB::table('billing_subscriptions')->where('billing_account_id', $account->id)->first();
        $snapshot = json_decode($subscription->package_snapshot, true);
        $snapshot['products'] = ['bookkeeping'];
        DB::table('billing_subscriptions')->where('id', $subscription->id)->update(['package_snapshot' => json_encode($snapshot)]);
        $this->postJson('/api/billing/accounts/'.$account->id.'/businesses', ['name' => 'Gym', 'business_type' => 'gym'])->assertForbidden();
    }
}
