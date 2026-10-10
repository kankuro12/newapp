<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FulfilmentSchemaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_tenants_receive_pending_accounts_without_overwriting_code_collisions(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $first = $this->postJson('/api/businesses', ['name' => 'Existing first'])->assertCreated()->json('data.id');
        $second = $this->postJson('/api/businesses', ['name' => 'Existing second'])->assertCreated()->json('data.id');
        $migration = require database_path('migrations/2026_10_04_015829_create_workflow_fulfilments.php');
        $billing = require database_path('migrations/2026_10_04_025708_create_workflow_bill_allocations.php');
        $billFirst = require database_path('migrations/2026_10_04_043511_create_bill_first_fulfilment_sources.php');
        $transit = require database_path('migrations/2026_10_04_112917_add_return_modes_and_recognition_to_fulfilment_sources.php');
        $transit->down();
        $billFirst->down();
        $billing->down();
        $migration->down();
        $this->assertSame(0, DB::table('accounts')->whereIn('system_key', ['delivered_unbilled', 'received_unbilled'])->count());
        foreach (['1350', '2050'] as $code) {
            DB::table('accounts')->insert(['tenant_id' => $first, 'code' => $code, 'name' => 'Keep custom '.$code, 'category' => 'asset', 'normal_side' => 'dr', 'is_money' => false]);
        }
        $migration->up();
        foreach ([$first, $second] as $tenant) {
            $accounts = DB::table('accounts')->where('tenant_id', $tenant)->whereIn('system_key', ['delivered_unbilled', 'received_unbilled'])->get()->keyBy('system_key');
            $this->assertCount(2, $accounts);
            $this->assertSame($tenant === $first ? '1350-1' : '1350', $accounts['delivered_unbilled']->code);
            $this->assertSame($tenant === $first ? '2050-1' : '2050', $accounts['received_unbilled']->code);
            $this->assertSame('asset', $accounts['delivered_unbilled']->category);
            $this->assertSame('liability', $accounts['received_unbilled']->category);
        }
        $this->assertSame(2, DB::table('accounts')->where('tenant_id', $first)->where('name', 'like', 'Keep custom %')->whereNull('system_key')->count());
        $migration->down();
        $this->assertSame(2, DB::table('accounts')->where('tenant_id', $first)->where('name', 'like', 'Keep custom %')->count());
        $migration->up();
        $this->assertSame(4, DB::table('accounts')->whereIn('system_key', ['delivered_unbilled', 'received_unbilled'])->count());
        $billing->up();
        $billFirst->up();
        $transit->up();
    }
}
