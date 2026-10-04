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

    private function action(array $s, array $package, string $action, int $date = 20830102): array
    {
        $path = $s['base'].'/package/'.$package['id'].'/'.$action;
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => $date, ...($action === 'ship' ? ['carrier' => 'Manual local delivery', 'tracking_reference' => 'LOCAL-1'] : ['handover_confirmed' => true])];
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

    public function test_multiple_packages_keep_remaining_shipped_and_delivered_quantities_distinct(): void
    {
        $s = $this->shop();
        $first = $this->pack($s, '2');
        $second = $this->pack($s, '3');
        $first = $this->action($s, $first, 'ship');
        $second = $this->action($s, $second, 'ship');
        $this->assertSame(25000, $this->balance($s, 'goods_in_transit'));
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.fulfilment.lines.0.packed_qty_milli', '0')->assertJsonPath('data.fulfilment.lines.0.shipped_qty_milli', '5000')->assertJsonPath('data.fulfilment.lines.0.completed_qty_milli', '0')->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '5000');
        $this->action($s, $first, 'deliver');
        $this->assertSame(15000, $this->balance($s, 'goods_in_transit'));
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.fulfilment.lines.0.shipped_qty_milli', '3000')->assertJsonPath('data.fulfilment.lines.0.completed_qty_milli', '2000')->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '3000');
        $this->action($s, $second, 'deliver');
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(-50000, $this->balance($s, 'sales'));
        $this->assertSame(25000, $this->balance($s, 'cogs'));
        $this->assertSame(3, DB::table('stock_movements')->count());
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '0');
    }

    public function test_delivery_can_be_undone_and_confirmed_again_with_unique_journal_history_and_date_gates(): void
    {
        $s = $this->shop();
        $package = $this->action($s, $this->pack($s, '2'), 'ship');
        $package = $this->action($s, $package, 'deliver');
        $package = $this->reverse($s, $package, 'undo-delivery');
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'handover_confirmed' => true];
        $this->postJson($s['base'].'/package/'.$package['id'].'/deliver/preview', $input)->assertUnprocessable();
        $this->postJson($s['base'].'/package/'.$package['id'].'/cancel', [...$input, 'mutation_uuid' => (string) Str::uuid(), 'reason' => 'Do not precede delivery undo'])->assertUnprocessable();
        $package = $this->action($s, $package, 'deliver', 20830103);
        $package = $this->reverse($s, $package, 'undo-delivery');
        $this->assertCount(2, $package['delivery_reversals']);
        $this->assertSame(4, DB::table('journal_entries')->where('source_type', 'fulfilment_delivery')->count());
        $this->assertSame(10000, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(0, $this->balance($s, 'sales'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->reverse($s, $package, 'cancel');
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
    }

    public function test_packing_does_not_protect_the_item_pool_from_other_daily_sales(): void
    {
        $s = $this->shop();
        $package = $this->pack($s, '5');
        $this->postJson($s['base'].'/documents/sale', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'money_account_id' => $s['cash'], 'paid_now' => '800', 'lines' => [['item_id' => $s['item'], 'qty' => '8', 'unit_price' => '100']], 'expected_total_paisa' => '80000'])->assertCreated();
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830102];
        $this->postJson($s['base'].'/package/'.$package['id'].'/ship/preview', $input)->assertUnprocessable();
        $this->assertSame(0, DB::table('workflow_fulfilments')->count());
        $this->assertSame(0, DB::table('workflow_dispatch_allocations')->count());
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '2000')->assertJsonPath('data.value_paisa', '10000');
        $this->reverse($s, $package, 'cancel');
    }

    public function test_shipment_review_binds_carrier_and_delivery_review_binds_current_bill_version(): void
    {
        $s = $this->shop();
        $package = $this->pack($s, '2');
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'carrier' => 'Reviewed carrier'];
        $preview = $this->postJson($s['base'].'/package/'.$package['id'].'/ship/preview', $input)->assertOk()->json('data');
        $this->postJson($s['base'].'/package/'.$package['id'].'/ship', [...$input, 'carrier' => 'Different carrier', 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']])->assertStatus(409);
        $this->assertSame(1, DB::table('stock_movements')->count());
        $package = $this->action($s, $package, 'ship');
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'handover_confirmed' => true];
        $preview = $this->postJson($s['base'].'/package/'.$package['id'].'/deliver/preview', $input)->assertOk()->json('data');
        DB::table('documents')->where('id', $s['bill']['id'])->increment('version');
        $this->postJson($s['base'].'/package/'.$package['id'].'/deliver', [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']])->assertStatus(409);
        $this->assertSame(0, $this->balance($s, 'sales'));
        $this->assertSame(10000, $this->balance($s, 'goods_in_transit'));
    }

    public function test_posted_physical_return_blocks_delivery_undo_until_its_own_reversal(): void
    {
        $s = $this->shop();
        $package = $this->action($s, $this->pack($s, '2'), 'ship');
        $package = $this->action($s, $package, 'deliver');
        $source = DB::table('workflow_dispatch_allocations')->value('id');
        $return = $this->postJson($s['base'].'/document/'.$s['bill']['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Actual customer return', 'lines' => [['source_line_id' => $s['bill']['lines'][0]['id'], 'qty' => '1', 'return_source' => (string) $source]]])->assertCreated()->json('data');
        $this->assertSame('5000', $return['lines'][0]['inventory_cost_paisa']);
        $this->reverse($s, $package, 'undo-delivery', 409);
        $this->postJson($s['base'].'/document/'.$return['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Undo returned delivery first'])->assertCreated();
        $package = $this->reverse($s, $package, 'undo-delivery');
        $this->assertSame(10000, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(0, $this->balance($s, 'sales'));
        $this->reverse($s, $package, 'cancel');
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '50000');
    }

    public function test_cashier_package_reviews_hide_cost_and_revoked_membership_denies_original_retries(): void
    {
        $s = $this->shop();
        DB::table('tenant_user')->where('tenant_id', $s['tenant']['id'])->where('user_id', $s['actor']->id)->update(['role' => 'cashier']);
        $package = $this->pack($s, '2');
        $this->assertArrayNotHasKey('delivery_journal_id', $package);
        $shipPath = $s['base'].'/package/'.$package['id'].'/ship';
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830102];
        $preview = $this->postJson($shipPath.'/preview', $input)->assertOk()->json('data');
        $this->assertArrayNotHasKey('pools_after', $preview['stage']);
        $this->assertArrayNotHasKey('inventory_value_paisa', $preview['stage']['lines'][0]);
        $this->assertArrayNotHasKey('inventory_cost_paisa', $preview['stage']['allocations'][0]);
        $shipPayload = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        $package = $this->postJson($shipPath, $shipPayload)->assertCreated()->json('data');
        $this->assertArrayNotHasKey('journal_id', $package['shipment']);
        $this->assertArrayNotHasKey('inventory_value_paisa', $package['shipment']['lines'][0]);
        $this->postJson($shipPath, $shipPayload)->assertOk();
        $deliverPath = $s['base'].'/package/'.$package['id'].'/deliver';
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'handover_confirmed' => true];
        $preview = $this->postJson($deliverPath.'/preview', $input)->assertOk()->json('data');
        $this->assertArrayNotHasKey('inventory_cost_paisa', $preview);
        $deliverPayload = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        $package = $this->postJson($deliverPath, $deliverPayload)->assertCreated()->json('data');
        $this->assertArrayNotHasKey('delivery_journal_id', $package);
        $this->assertArrayNotHasKey('recognition_journal_id', $package['shipment']);
        $this->postJson($deliverPath, $deliverPayload)->assertOk();
        DB::table('tenant_user')->where('tenant_id', $s['tenant']['id'])->where('user_id', $s['actor']->id)->update(['active' => false]);
        $this->postJson($shipPath, $shipPayload)->assertNotFound();
        $this->postJson($deliverPath, $deliverPayload)->assertNotFound();
        $this->getJson($s['base'].'/package/'.$package['id'])->assertNotFound();
        $this->assertSame(1, DB::table('workflow_packages')->count());
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_foreign_branch_package_and_bill_line_are_not_readable_or_mutable(): void
    {
        $s = $this->shop();
        $package = $this->pack($s, '2');
        $other = $this->shop();
        $this->getJson($other['base'].'/package/'.$package['id'])->assertNotFound();
        $this->postJson($other['base'].'/package/'.$package['id'].'/ship/preview', ['version' => 1, 'workflow_version' => 3, 'business_date_bs' => 20830102])->assertNotFound();
        $input = ['version' => $this->current($other)['version'], 'business_date_bs' => 20830102, 'lines' => [['document_line_id' => $s['bill']['lines'][0]['id'], 'qty' => '1']]];
        $this->postJson($other['base'].'/workflow/'.$other['order']['id'].'/packages/preview', $input)->assertNotFound();
        $this->assertSame(0, DB::table('workflow_packages')->where('tenant_id', $other['tenant']['id'])->count());
        $this->assertSame(0, DB::table('workflow_fulfilments')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_zero_paisa_credit_between_packages_keeps_the_last_delivery_penny_in_held_value(): void
    {
        $s = $this->shop();
        $s['order'] = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '3', 'unit_price' => '0.01']], 'invoice_discount' => '0.02', 'expected_total_paisa' => '1'])->assertCreated()->json('data');
        $input = ['version' => 1, 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['position' => 1, 'qty' => '3']]];
        $preview = $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills/preview', $input)->assertOk()->json('data');
        $s['bill'] = $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills', [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_total_paisa' => '1', 'expected_fingerprint' => $preview['fingerprint']])->assertCreated()->json('data');
        $first = $this->action($s, $this->pack($s, '1'), 'ship');
        $this->action($s, $first, 'deliver');
        $this->assertSame(-50001, $this->balance($s, 'sales_unfulfilled'));
        $credit = $this->postJson($s['base'].'/document/'.$s['bill']['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Zero financial credit', 'lines' => [['source_line_id' => $s['bill']['lines'][0]['id'], 'qty' => '1', 'return_source' => 'unfulfilled']]])->assertCreated()->json('data');
        $this->assertSame('0', $credit['total_paisa']);
        $last = $this->action($s, $this->pack($s, '1'), 'ship');
        $this->assertSame(-50001, $this->balance($s, 'sales_unfulfilled'));
        $this->action($s, $last, 'deliver');
        // The initial unrelated NPR500 prebill remains held; this penny order is fully cleared.
        $this->assertSame(-50000, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(-1, $this->balance($s, 'sales'));
        $this->assertSame(10000, $this->balance($s, 'cogs'));
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '8000')->assertJsonPath('data.value_paisa', '40000');
    }
}
