<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfilmentBillFirstSchemaTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        try {
            if ($this->app && Schema::hasTable('workflow_fulfilments') && DB::table('workflow_fulfilments')->exists()) {
                $this->assertSame('business_book_testing', DB::connection()->getDatabaseName());
                // Schema DDL commits implicitly; clean only this guarded test database before rollback callbacks.
                $this->artisan('migrate:fresh')->assertExitCode(0);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Bill-first schema'])->assertCreated()->json('data');
        $base = '/api/app/'.$tenant['slug'];
        $party = $this->postJson($base.'/contacts', ['name' => 'Own customer', 'is_customer' => true, 'is_supplier' => false])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Own service', 'kind' => 'service', 'unit_label' => 'job', 'pos_unit' => 'unit', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $order = $this->postJson($base.'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $party, 'business_date_bs' => 20830102, 'lines' => [['item_id' => $item, 'qty' => '5', 'unit_price' => '100', 'tax_category' => 'outside_scope', 'tax_bps' => 0]], 'expected_total_paisa' => '50000'])->assertCreated()->json('data');

        return compact('owner', 'tenant', 'base', 'party', 'item', 'order');
    }

    private function oldStage(array $s): int
    {
        return DB::table('workflow_fulfilments')->insertGetId(['tenant_id' => $s['tenant']['id'], 'workflow_id' => $s['order']['id'], 'kind' => 'delivery', 'sequence' => 1, 'status' => 'cancelled', 'business_date_bs' => 20830102, 'inventory_value_paisa' => 0, 'clearing_value_paisa' => 0, 'created_by' => $s['owner']->id]);
    }

    public function test_owned_allocation_schema_and_new_tenant_hold_accounts_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('business_workflows', 'fulfilment_policy'));
        $s = $this->shop();
        foreach (['workflow_dispatch_allocations', 'workflow_return_allocations', 'workflow_packages', 'workflow_package_lines'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumn('documents', 'fulfilment_policy'));
        $this->assertTrue(Schema::hasColumn('workflow_fulfilments', 'party_snapshot'));
        $accounts = DB::table('accounts')->where('tenant_id', $s['tenant']['id'])->whereIn('system_key', ['sales_unfulfilled', 'billed_unreceived', 'goods_in_transit'])->get()->keyBy('system_key');
        $this->assertCount(3, $accounts);
        $this->assertSame('liability', $accounts['sales_unfulfilled']->category);
        $this->assertSame('asset', $accounts['billed_unreceived']->category);
        $this->assertSame('asset', $accounts['goods_in_transit']->category);
    }

    public function test_backfill_freezes_even_cancelled_legacy_stages_and_keeps_custom_accounts(): void
    {
        $this->assertTrue(Schema::hasColumn('business_workflows', 'fulfilment_policy'));
        $s = $this->shop();
        $migration = require database_path('migrations/2026_10_04_043511_create_bill_first_fulfilment_sources.php');
        $transit = require database_path('migrations/2026_10_04_112917_add_return_modes_and_recognition_to_fulfilment_sources.php');
        $transit->down();
        $migration->down();
        foreach (['1355', '1360', '2060'] as $code) {
            DB::table('accounts')->insert(['tenant_id' => $s['tenant']['id'], 'code' => $code, 'name' => 'Keep original '.$code, 'category' => 'asset', 'normal_side' => 'dr', 'is_money' => false]);
        }
        $stage = $this->oldStage($s);
        $migration->up();
        $transit->up();
        $this->assertSame('delivery_first', DB::table('business_workflows')->where('id', $s['order']['id'])->value('fulfilment_policy'));
        $this->assertNull(DB::table('workflow_fulfilments')->where('id', $stage)->value('party_snapshot'), 'Do not invent historical party snapshots from an editable current order.');
        foreach (['billed_unreceived' => '1355', 'goods_in_transit' => '1360', 'sales_unfulfilled' => '2060'] as $key => $code) {
            $this->assertSame($code.'-1', DB::table('accounts')->where('tenant_id', $s['tenant']['id'])->where('system_key', $key)->value('code'));
        }
        $this->assertSame(3, DB::table('accounts')->where('tenant_id', $s['tenant']['id'])->where('name', 'like', 'Keep original %')->whereNull('system_key')->count());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function prebill(array $s): array
    {
        $input = ['version' => 1, 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['position' => 1, 'qty' => '3']]];
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills';
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');

        return $this->postJson($path, [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_total_paisa' => '30000', 'expected_fingerprint' => $preview['fingerprint']])->assertCreated()->json('data');
    }

    private function sourceCredit(array $s, array $bill): array
    {
        return $this->postJson($s['base'].'/document/'.$bill['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Historical source credit', 'lines' => [['source_line_id' => $bill['lines'][0]['id'], 'qty' => '0.5', 'return_source' => 'unfulfilled']]])->assertCreated()->json('data');
    }

    public function test_transit_schema_backfills_completed_and_unfulfilled_history_without_inventing_recognition(): void
    {
        $s = $this->shop();
        $bill = $this->prebill($s);
        $first = $this->sourceCredit($s, $bill);
        $last = $this->sourceCredit($s, $bill);
        $migration = require database_path('migrations/2026_10_04_112917_add_return_modes_and_recognition_to_fulfilment_sources.php');
        $migration->down();
        $sources = [];
        foreach ([true, false] as $index => $confirmed) {
            $stage = DB::table('workflow_fulfilments')->insertGetId(['tenant_id' => $s['tenant']['id'], 'workflow_id' => $s['order']['id'], 'kind' => 'delivery', 'sequence' => $index + 1, 'status' => $confirmed ? 'cancelled' : 'posted', 'handover_confirmed' => $confirmed, 'business_date_bs' => 20830102, 'inventory_value_paisa' => 0, 'clearing_value_paisa' => 0, 'created_by' => $s['owner']->id]);
            $line = DB::table('workflow_fulfilment_lines')->insertGetId(['tenant_id' => $s['tenant']['id'], 'fulfilment_id' => $stage, 'item_id' => $s['item'], 'position' => 1, 'qty_milli' => 1000, 'inventory_value_paisa' => 0, 'clearing_value_paisa' => 0, 'item_snapshot' => '{}']);
            $sources[] = DB::table('workflow_dispatch_allocations')->insertGetId(['tenant_id' => $s['tenant']['id'], 'workflow_id' => $s['order']['id'], 'document_line_id' => $bill['lines'][0]['id'], 'fulfilment_line_id' => $line, 'qty_milli' => 1000, 'inventory_cost_paisa' => 0, 'pending_value_paisa' => 0, 'sales_base_paisa' => 101 + $index]);
        }
        DB::table('workflow_return_allocations')->where('return_document_line_id', $first['lines'][0]['id'])->update(['dispatch_allocation_id' => $sources[0]]);
        $migration->up();
        $this->assertSame('completed', DB::table('workflow_return_allocations')->where('return_document_line_id', $first['lines'][0]['id'])->value('return_mode'));
        $this->assertSame('unfulfilled', DB::table('workflow_return_allocations')->where('return_document_line_id', $last['lines'][0]['id'])->value('return_mode'));
        $this->assertSame(101, (int) DB::table('workflow_dispatch_allocations')->where('id', $sources[0])->value('recognition_sales_base_paisa'));
        $this->assertNull(DB::table('workflow_dispatch_allocations')->where('id', $sources[1])->value('recognition_sales_base_paisa'));
        $migration->down();
        $migration->up();
        $this->assertSame(2, DB::table('workflow_return_allocations')->count());
    }

    public function test_transit_schema_rollback_preserves_cancelled_transit_return_history(): void
    {
        $s = $this->shop();
        $bill = $this->prebill($s);
        $return = $this->sourceCredit($s, $bill);
        $this->oldStage($s);
        DB::table('documents')->where('id', $return['id'])->update(['status' => 'cancelled']);
        DB::table('workflow_return_allocations')->where('return_document_line_id', $return['lines'][0]['id'])->update(['return_mode' => 'transit']);
        $migration = require database_path('migrations/2026_10_04_112917_add_return_modes_and_recognition_to_fulfilment_sources.php');
        try {
            $migration->down();
            $this->fail('Rollback erased cancelled transit history.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('transit return', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('workflow_return_allocations', 'return_mode'));
        $this->assertTrue(Schema::hasColumn('workflow_dispatch_allocations', 'recognition_sales_base_paisa'));
    }

    public function test_transit_schema_rollback_preserves_actual_delivery_audit_history(): void
    {
        $s = $this->shop();
        $this->oldStage($s);
        DB::table('audit_logs')->insert(['tenant_id' => $s['tenant']['id'], 'actor_id' => $s['owner']->id, 'action' => 'package.delivery.confirm', 'subject_type' => 'workflow_packages', 'subject_id' => 1, 'metadata' => '{}']);
        $migration = require database_path('migrations/2026_10_04_112917_add_return_modes_and_recognition_to_fulfilment_sources.php');
        try {
            $migration->down();
            $this->fail('Rollback erased actual delivery allocations.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('delivery allocation', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('workflow_dispatch_allocations', 'recognition_sales_base_paisa'));
    }

    public function test_real_database_foreign_keys_reject_cross_branch_dispatch_sources(): void
    {
        $this->assertTrue(Schema::hasTable('workflow_dispatch_allocations'));
        $s = $this->shop();
        $other = $this->shop();
        $document = $this->postJson($other['base'].'/documents/sale', ['mutation_uuid' => (string) Str::uuid(), 'type' => 'sale', 'contact_id' => $other['party'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['item_id' => $other['item'], 'qty' => '1', 'unit_price' => '100', 'tax_category' => 'outside_scope', 'tax_bps' => 0]], 'expected_total_paisa' => '10000'])->assertCreated()->json('data');
        $stage = $this->oldStage($s);
        $line = DB::table('workflow_fulfilment_lines')->insertGetId(['tenant_id' => $s['tenant']['id'], 'fulfilment_id' => $stage, 'item_id' => $s['item'], 'position' => 1, 'qty_milli' => 1000, 'inventory_value_paisa' => 0, 'clearing_value_paisa' => 0, 'item_snapshot' => '{}']);
        try {
            DB::table('workflow_dispatch_allocations')->insert(['tenant_id' => $s['tenant']['id'], 'workflow_id' => $s['order']['id'], 'document_line_id' => $document['lines'][0]['id'], 'fulfilment_line_id' => $line, 'qty_milli' => 1000, 'inventory_cost_paisa' => 0, 'pending_value_paisa' => 0, 'sales_base_paisa' => 0]);
            $this->fail('Database accepted foreign billed source.');
        } catch (QueryException $error) {
            $this->assertSame('23000', $error->errorInfo[0]);
        }

    }
}
