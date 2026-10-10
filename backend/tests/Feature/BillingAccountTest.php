<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_group_businesses_without_sharing_ledger_membership(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $account = $this->postJson('/api/billing/accounts', ['name' => 'Owner account'])->assertCreated()->json('data.id');
        $first = $this->postJson('/api/billing/accounts/'.$account.'/businesses', ['name' => 'First'])->assertCreated()->json('data');
        $second = $this->postJson('/api/billing/accounts/'.$account.'/businesses', ['name' => 'Second'])->assertCreated()->json('data');
        $this->assertSame($account, $first['billing_account_id']);
        $this->assertSame($account, $second['billing_account_id']);
        $this->assertNotSame($first['id'], $second['id']);
        $staff = User::factory()->create();
        DB::table('billing_account_user')->insert(['billing_account_id' => $account, 'user_id' => $staff->id, 'role' => 'owner', 'active' => true]);
        $this->actingAs($staff, 'tenant');
        $this->getJson('/api/billing/accounts/'.$account)->assertOk();
        $this->getJson('/api/app/'.$first['slug'].'/lookup')->assertNotFound();
    }

    public function test_foreign_account_cannot_be_read_or_used_and_default_is_deterministic(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $account = $this->postJson('/api/billing/accounts', ['name' => 'First account'])->assertCreated()->json('data.id');
        $this->postJson('/api/billing/accounts', ['name' => 'Second account'])->assertCreated();
        $this->postJson('/api/businesses', ['name' => 'Default'])->assertCreated()->assertJsonPath('data.billing_account_id', $account);
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->getJson('/api/billing/accounts/'.$account)->assertNotFound();
        $this->postJson('/api/billing/accounts/'.$account.'/businesses', ['name' => 'Foreign'])->assertNotFound();
        $this->postJson('/api/businesses', ['name' => 'Foreign', 'billing_account_id' => $account])->assertNotFound();
    }

    public function test_revoked_and_disabled_owners_cannot_create_businesses(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $account = $this->postJson('/api/billing/accounts', ['name' => 'Account'])->assertCreated()->json('data.id');
        DB::table('billing_account_user')->where('user_id', $owner->id)->update(['active' => false]);
        $this->postJson('/api/billing/accounts/'.$account.'/businesses', ['name' => 'Denied'])->assertNotFound();
        DB::table('users')->where('id', $owner->id)->update(['disabled_at' => now()]);
        $this->postJson('/api/billing/accounts', ['name' => 'Denied'])->assertForbidden();
    }

    public function test_legacy_backfill_keeps_unrelated_books_separate_and_preserves_expiry(): void
    {
        $migration = require database_path('migrations/2026_10_10_084238_create_billing_accounts.php');

        $owner = User::factory()->create();
        $expiry = now()->addDays(4)->format('Y-m-d H:i:s');
        $first = DB::table('tenants')->insertGetId(['name' => 'Legacy main', 'slug' => 'legacy-main', 'trial_ends_at' => $expiry, 'created_at' => now()]);
        $other = DB::table('tenants')->insertGetId(['name' => 'Other main', 'slug' => 'other-main', 'access_status' => 'active', 'access_until' => $expiry, 'created_at' => now()]);
        $branch = DB::table('tenants')->insertGetId(['name' => 'Child', 'slug' => 'child', 'parent_tenant_id' => $first, 'trial_ends_at' => $expiry, 'created_at' => now()]);
        foreach ([$first, $other, $branch] as $id) {
            DB::table('tenant_user')->insert(['tenant_id' => $id, 'user_id' => $owner->id, 'role' => 'owner', 'active' => true]);
        }
        $migration->backfill();
        $mainAccount = DB::table('tenants')->where('id', $first)->value('billing_account_id');
        $this->assertSame($mainAccount, DB::table('tenants')->where('id', $branch)->value('billing_account_id'));
        $this->assertNotSame($mainAccount, DB::table('tenants')->where('id', $other)->value('billing_account_id'));
        $this->assertSame($expiry, DB::table('tenants')->where('id', $first)->value('trial_ends_at'));
        $this->assertSame($expiry, DB::table('tenants')->where('id', $other)->value('access_until'));
        $this->assertDatabaseCount('billing_accounts', 2);
        $this->assertDatabaseCount('billing_account_user', 2);
    }
}
