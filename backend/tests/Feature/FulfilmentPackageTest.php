<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfilmentPackageTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $actor = User::factory()->create();
        $this->actingAs($actor, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Packages and transit'])->assertCreated()->json('data');
        $base = '/api/app/'.$tenant['slug'];
        $party = $this->postJson($base.'/contacts', ['name' => 'Package customer', 'is_customer' => true, 'is_supplier' => false])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Packable stock', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $cash = $this->getJson($base.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [['account_id' => $cash, 'amount' => '1000']], 'stock' => [['item_id' => $item, 'qty' => '10', 'value' => '500']], 'parties' => []])->assertCreated();
        $order = $this->postJson($base.'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $party, 'business_date_bs' => 20830102, 'lines' => [['item_id' => $item, 'qty' => '5', 'unit_price' => '100']], 'expected_total_paisa' => '50000'])->assertCreated()->json('data');
        $billInput = ['version' => 1, 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['position' => 1, 'qty' => '5']]];
        $preview = $this->postJson($base.'/workflow/'.$order['id'].'/ordered-bills/preview', $billInput)->assertOk()->json('data');
        $bill = $this->postJson($base.'/workflow/'.$order['id'].'/ordered-bills', [...$billInput, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint'], 'expected_total_paisa' => '50000'])->assertCreated()->json('data');

        return compact('actor', 'tenant', 'base', 'party', 'item', 'cash', 'order', 'bill');
    }

    private function current(array $s): array
    {
        return $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->json('data');
    }

    private function balance(array $s, string $key): int
    {
        $account = DB::table('accounts')->where('tenant_id', $s['tenant']['id'])->where('system_key', $key)->value('id');
        $this->assertNotNull($account);

        return (int) DB::table('journal_lines')->where('tenant_id', $s['tenant']['id'])->where('account_id', $account)->selectRaw('COALESCE(SUM(debit_paisa-credit_paisa),0) AS value')->value('value');
    }

    private function pack(array $s, string $qty): array
    {
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/packages';
        $input = ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'reference' => 'Reviewed parcel', 'lines' => [['document_line_id' => $s['bill']['lines'][0]['id'], 'qty' => $qty]]];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $payload = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        $package = $this->postJson($path, $payload)->assertCreated()->json('data');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $package['id']);

        return $package;
    }

    private function action(array $s, array $package, string $action): array
    {
        $path = $s['base'].'/package/'.$package['id'].'/'.$action;
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830102, ...($action === 'ship' ? ['carrier' => 'Manual local delivery', 'tracking_reference' => 'LOCAL-1'] : ['handover_confirmed' => true])];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $payload = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        $result = $this->postJson($path, $payload)->assertCreated()->json('data');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $result['id']);

        return $result;
    }

    private function reverse(array $s, array $package, string $action, int $status = 201): array
    {
        return $this->postJson($s['base'].'/package/'.$package['id'].'/'.$action, ['mutation_uuid' => (string) Str::uuid(), 'version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830103, 'reason' => 'Reverse exact package source'])->assertStatus($status)->json('data') ?? [];
    }

    public function test_pack_ship_and_confirm_delivery_have_distinct_money_stock_and_progress_effects(): void
    {
        $s = $this->shop();
        $journals = DB::table('journal_entries')->count();
        $package = $this->pack($s, '2');
        $this->assertSame('packed', $package['status']);
        $this->assertSame($journals, DB::table('journal_entries')->count());
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->getJson($s['base'].'/document/'.$s['bill']['id'])->assertOk()->assertJsonPath('data.lines.0.packed_qty_milli', '2000')->assertJsonPath('data.lines.0.unfulfilled_qty_milli', '3000');
        $package = $this->action($s, $package, 'ship');
        $this->assertSame('shipped', $package['status']);
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->assertSame(10000, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->assertSame(0, $this->balance($s, 'sales'));
        $this->assertSame(-50000, $this->balance($s, 'sales_unfulfilled'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '8000')->assertJsonPath('data.value_paisa', '40000');
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.fulfilment.lines.0.completed_qty_milli', '0')->assertJsonPath('data.fulfilment.lines.0.shipped_qty_milli', '2000')->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '5000');
        $this->getJson($s['base'].'/document/'.$s['bill']['id'])->assertOk()->assertJsonPath('data.lines.0.fulfilment_sources.0.handover_confirmed', false)->assertJsonPath('data.lines.0.fulfilment_sources.0.returnable_qty_milli', '0');
        $package = $this->action($s, $package, 'deliver');
        $this->assertSame('delivered', $package['status']);
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(10000, $this->balance($s, 'cogs'));
        $this->assertSame(-20000, $this->balance($s, 'sales'));
        $this->assertSame(-30000, $this->balance($s, 'sales_unfulfilled'));
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.fulfilment.lines.0.completed_qty_milli', '2000')->assertJsonPath('data.fulfilment.lines.0.shipped_qty_milli', '0')->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '3000');
    }

    public function test_delivery_recognition_then_shipment_reverse_without_erasing_package_history(): void
    {
        $s = $this->shop();
        $package = $this->action($s, $this->pack($s, '2'), 'ship');
        $package = $this->action($s, $package, 'deliver');
        $this->reverse($s, $package, 'cancel', 409);
        $package = $this->reverse($s, $package, 'undo-delivery');
        $this->assertSame('shipped', $package['status']);
        $this->assertSame(10000, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->assertSame(0, $this->balance($s, 'sales'));
        $package = $this->reverse($s, $package, 'cancel');
        $this->assertSame('cancelled', $package['status']);
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(-50000, $this->balance($s, 'sales_unfulfilled'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '50000');
        $this->assertSame(1, DB::table('workflow_packages')->count());
        $this->assertSame(1, DB::table('workflow_dispatch_allocations')->count());
        $this->assertSame('cancelled', DB::table('workflow_fulfilments')->value('status'));
        $this->postJson($s['base'].'/document/'.$s['bill']['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Reverse prebill after package'])->assertCreated();
        $this->assertSame(0, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(0, $this->balance($s, 'receivables'));
    }

    public function test_packing_reserves_only_billed_capacity_and_unconfirmed_shipment_is_not_customer_returnable(): void
    {
        $s = $this->shop();
        $package = $this->pack($s, '2');
        $credit = ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'No nonexistent stock return', 'lines' => [['source_line_id' => $s['bill']['lines'][0]['id'], 'qty' => '4', 'return_source' => 'unfulfilled']]];
        $this->postJson($s['base'].'/document/'.$s['bill']['id'].'/returns', $credit)->assertUnprocessable();
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000');
        $package = $this->action($s, $package, 'ship');
        $source = DB::table('workflow_dispatch_allocations')->value('id');
        $this->postJson($s['base'].'/document/'.$s['bill']['id'].'/returns', [...$credit, 'lines' => [['source_line_id' => $s['bill']['lines'][0]['id'], 'qty' => '1', 'return_source' => (string) $source]]])->assertStatus(409);
        $this->assertSame(0, DB::table('workflow_return_allocations')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->reverse($s, $package, 'cancel');
    }

    public function test_cancelling_unshipped_package_releases_capacity_without_stock_or_journal_changes(): void
    {
        $s = $this->shop();
        $package = $this->pack($s, '5');
        $journals = DB::table('journal_entries')->count();
        $this->postJson($s['base'].'/document/'.$s['bill']['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Packed dependency blocks'])->assertStatus(409);
        $package = $this->reverse($s, $package, 'cancel');
        $this->assertSame('cancelled', $package['status']);
        $this->assertSame($journals, DB::table('journal_entries')->count());
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->getJson($s['base'].'/document/'.$s['bill']['id'])->assertOk()->assertJsonPath('data.lines.0.packed_qty_milli', '0')->assertJsonPath('data.lines.0.unfulfilled_qty_milli', '5000');
        $this->pack($s, '5');
        $this->assertSame(2, DB::table('workflow_packages')->count());
    }
}
