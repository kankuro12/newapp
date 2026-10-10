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

    private function transitInput(array $s, array $package, string $qty = '1', int $date = 20830103): array
    {
        $source = DB::table('workflow_dispatch_allocations')->whereIn('fulfilment_line_id', DB::table('workflow_fulfilment_lines')->where('fulfilment_id', $package['fulfilment_id'])->select('id'))->value('id');

        return ['version' => $s['bill']['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => $date, 'reason' => 'Actual undelivered goods received', 'lines' => [['source_line_id' => $s['bill']['lines'][0]['id'], 'qty' => $qty, 'return_source' => (string) $source, 'return_mode' => 'transit']]];
    }

    private function transitReturn(array $s, array $package, string $qty = '1', int $date = 20830103): array
    {
        $path = $s['base'].'/document/'.$s['bill']['id'].'/returns';
        $input = $this->transitInput($s, $package, $qty, $date);
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $payload = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        $result = $this->postJson($path, $payload)->assertCreated()->json('data');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $result['id']);

        return $result;
    }

    private function pennyBill(array $s): array
    {
        $s['order'] = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '3', 'unit_price' => '0.01']], 'invoice_discount' => '0.02', 'expected_total_paisa' => '1'])->assertCreated()->json('data');
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills';
        $input = ['version' => 1, 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['position' => 1, 'qty' => '3']]];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $s['bill'] = $this->postJson($path, [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_total_paisa' => '1', 'expected_fingerprint' => $preview['fingerprint']])->assertCreated()->json('data');

        return $s;
    }

    public function test_transit_return_uses_original_cost_after_later_stock_then_only_remaining_goods_are_delivered(): void
    {
        $s = $this->shop();
        $package = $this->action($s, $this->pack($s, '2'), 'ship');
        $supplier = $this->postJson($s['base'].'/contacts', ['name' => 'Later supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $this->postJson($s['base'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'type' => 'purchase', 'contact_id' => $supplier, 'business_date_bs' => 20830103, 'paid_now' => '0', 'lines' => [['item_id' => $s['item'], 'qty' => '2', 'unit_price' => '200']], 'expected_total_paisa' => '40000'])->assertCreated();
        $this->reverse($s, $package, 'cancel', 409);
        $returned = $this->transitReturn($s, $package);
        $this->assertSame('10000', $returned['total_paisa']);
        $this->assertSame('transit', DB::table('workflow_return_allocations')->value('return_mode'));
        $this->assertSame(5000, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->assertSame(0, $this->balance($s, 'sales'));
        $this->assertSame(-40000, $this->balance($s, 'sales_unfulfilled'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '11000')->assertJsonPath('data.value_paisa', '85000');
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.fulfilment.lines.0.shipped_qty_milli', '1000')->assertJsonPath('data.fulfilment.lines.0.credited_transit_qty_milli', '1000')->assertJsonPath('data.fulfilment.lines.0.completed_qty_milli', '0');
        $this->getJson($s['base'].'/package/'.$package['id'])->assertOk()->assertJsonPath('data.lines.0.remaining_delivery_qty_milli', '1000')->assertJsonPath('data.lines.0.credited_transit_qty_milli', '1000')->assertJsonPath('data.lines.0.delivered_qty_milli', '0');
        $moves = DB::table('stock_movements')->count();
        $package = $this->action($s, $package, 'deliver', 20830103);
        $this->assertSame($moves, DB::table('stock_movements')->count());
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(5000, $this->balance($s, 'cogs'));
        $this->assertSame(-10000, $this->balance($s, 'sales'));
        $this->assertSame('1000', $package['lines'][0]['delivered_qty_milli']);
        $this->assertSame('0', $package['lines'][0]['remaining_delivery_qty_milli']);
        $this->assertCount(1, $package['delivery_confirmations']);
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.fulfilment.lines.0.completed_qty_milli', '1000')->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '3000');
        $cancel = ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Restore undelivered return'];
        $this->postJson($s['base'].'/document/'.$returned['id'].'/cancel', $cancel)->assertStatus(409);
        $package = $this->reverse($s, $package, 'undo-delivery');
        $this->assertSame(5000, $this->balance($s, 'goods_in_transit'));
        $this->postJson($s['base'].'/document/'.$returned['id'].'/cancel', $cancel)->assertCreated();
        $this->assertSame(10000, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(-50000, $this->balance($s, 'sales_unfulfilled'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '80000');
    }

    public function test_transit_zero_credit_from_middle_package_leaves_penny_for_remaining_actual_delivery(): void
    {
        $s = $this->pennyBill($this->shop());
        $first = $this->action($s, $this->pack($s, '1'), 'ship');
        $middle = $this->action($s, $this->pack($s, '1'), 'ship');
        $last = $this->action($s, $this->pack($s, '1'), 'ship');
        $returned = $this->transitReturn($s, $middle);
        $this->assertSame('0', $returned['total_paisa']);
        $this->assertSame(-50001, $this->balance($s, 'sales_unfulfilled'));
        $this->postJson($s['base'].'/package/'.$middle['id'].'/deliver/preview', ['version' => $middle['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830103, 'handover_confirmed' => true])->assertStatus(409);
        $this->action($s, $first, 'deliver', 20830103);
        $this->action($s, $last, 'deliver', 20830103);
        $this->assertSame(-50000, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(-1, $this->balance($s, 'sales'));
        $this->assertSame(10000, $this->balance($s, 'cogs'));
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.fulfilment.lines.0.completed_qty_milli', '2000')->assertJsonPath('data.fulfilment.lines.0.shipped_qty_milli', '0')->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '0');
        $this->getJson($s['base'].'/document/'.$s['bill']['id'])->assertOk()->assertJsonPath('data.lines.0.unfulfilled_qty_milli', '0');
    }

    public function test_transit_zero_journal_post_and_cancel_still_protect_package_history_dates(): void
    {
        $s = $this->shop();
        $s['item'] = $this->postJson($s['base'].'/items', ['name' => 'Free cost goods', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '0.01'])->assertCreated()->json('data.id');
        $this->postJson($s['base'].'/stock-adjustments', ['mutation_uuid' => (string) Str::uuid(), 'item_id' => $s['item'], 'counted_qty' => '3', 'expected_qty_milli' => '0', 'unit_cost' => '0', 'zero_cost_confirmed' => true, 'business_date_bs' => 20830102, 'reason' => 'Free cost opening sample'])->assertCreated();
        $s = $this->pennyBill($s);
        $package = $this->action($s, $this->pack($s, '3'), 'ship');
        $returned = $this->transitReturn($s, $package, '1', 20830104);
        $this->assertNull(DB::table('documents')->where('id', $returned['id'])->value('journal_id'));
        $this->postJson($s['base'].'/document/'.$returned['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830105, 'reason' => 'Undo zero journal return'])->assertCreated();
        $input = ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'handover_confirmed' => true, 'business_date_bs' => 20830104];
        $this->postJson($s['base'].'/package/'.$package['id'].'/deliver/preview', $input)->assertUnprocessable();
        $this->action($s, $package, 'deliver', 20830105);
        $this->assertSame(-1, $this->balance($s, 'sales'));
        $this->assertSame(-50000, $this->balance($s, 'sales_unfulfilled'));
    }

    public function test_transit_review_is_required_and_cannot_be_reused_after_source_delivery_or_changed_reason(): void
    {
        $s = $this->shop();
        $package = $this->action($s, $this->pack($s, '2'), 'ship');
        $path = $s['base'].'/document/'.$s['bill']['id'].'/returns';
        $input = $this->transitInput($s, $package);
        $this->postJson($path, [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path, [...$input, 'reason' => 'Changed reviewed reason', 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()])->assertStatus(409);
        $this->action($s, $package, 'deliver', 20830103);
        $this->postJson($path, [...$input, 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(0, DB::table('workflow_return_allocations')->count());
    }

    public function test_transit_review_and_retry_enforce_foreign_sources_roles_and_revoked_membership(): void
    {
        $s = $this->shop();
        $package = $this->action($s, $this->pack($s, '2'), 'ship');
        $input = $this->transitInput($s, $package);
        $path = $s['base'].'/document/'.$s['bill']['id'].'/returns';
        $other = $this->shop();
        $foreign = $this->action($other, $this->pack($other, '1'), 'ship');
        $foreignInput = $this->transitInput($other, $foreign);
        $this->actingAs($s['actor'], 'tenant');
        $this->postJson($path.'/preview', [...$input, 'lines' => [[...$input['lines'][0], 'return_source' => $foreignInput['lines'][0]['return_source']]]])->assertNotFound();
        $this->postJson($other['base'].'/document/'.$other['bill']['id'].'/returns/preview', $foreignInput)->assertNotFound();
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $payload = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        DB::table('tenant_user')->where('tenant_id', $s['tenant']['id'])->where('user_id', $s['actor']->id)->update(['role' => 'cashier']);
        $this->postJson($path.'/preview', $input)->assertForbidden();
        $this->postJson($path, $payload)->assertForbidden();
        DB::table('tenant_user')->where('tenant_id', $s['tenant']['id'])->where('user_id', $s['actor']->id)->update(['role' => 'manager']);
        $returned = $this->postJson($path, $payload)->assertCreated()->json('data');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $returned['id']);
        DB::table('tenant_user')->where('tenant_id', $s['tenant']['id'])->where('user_id', $s['actor']->id)->update(['role' => 'cashier']);
        $this->postJson($path, $payload)->assertForbidden();
        DB::table('tenant_user')->where('tenant_id', $s['tenant']['id'])->where('user_id', $s['actor']->id)->update(['active' => false]);
        $this->postJson($path, $payload)->assertNotFound();
        $this->assertSame(1, DB::table('workflow_return_allocations')->count());
    }

    public function test_transit_review_binds_stock_context_and_refund_choice_without_changing_order_version(): void
    {
        $s = $this->shop();
        $package = $this->action($s, $this->pack($s, '2'), 'ship');
        $input = $this->transitInput($s, $package);
        $path = $s['base'].'/document/'.$s['bill']['id'].'/returns';
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->assertSame('5000', $preview['lines'][0]['inventory_cost_paisa']);
        $this->assertSame('0', $preview['refund_paisa']);
        $this->postJson($path, [...$input, 'refund_now' => true, 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()])->assertStatus(409);
        $this->postJson($s['base'].'/stock-adjustments', ['mutation_uuid' => (string) Str::uuid(), 'item_id' => $s['item'], 'counted_qty' => '9', 'expected_qty_milli' => '8000', 'unit_cost' => '70', 'business_date_bs' => 20830103, 'reason' => 'Independent inbound count'])->assertCreated();
        $this->assertSame($input['workflow_version'], $this->current($s)['version']);
        $this->postJson($path, [...$input, 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()])->assertStatus(409);
        $this->transitReturn($s, $package);
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '52000');
    }

    public function test_fully_returned_transit_package_cannot_claim_delivery_and_reverses_dependencies_in_order(): void
    {
        $s = $this->shop();
        $package = $this->action($s, $this->pack($s, '5'), 'ship');
        $first = $this->transitReturn($s, $package, '1');
        $last = $this->transitReturn($s, $package, '4');
        $this->assertSame(0, $this->balance($s, 'goods_in_transit'));
        $this->assertSame(0, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(0, $this->balance($s, 'sales'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->getJson($s['base'].'/package/'.$package['id'])->assertOk()->assertJsonPath('data.lines.0.remaining_delivery_qty_milli', '0')->assertJsonPath('data.lines.0.delivered_qty_milli', '0');
        $this->postJson($s['base'].'/package/'.$package['id'].'/deliver/preview', ['version' => $package['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830103, 'handover_confirmed' => true])->assertStatus(409);
        $this->reverse($s, $package, 'cancel', 409);
        $cancel = ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Reverse exact source return'];
        $this->postJson($s['base'].'/document/'.$first['id'].'/cancel', $cancel)->assertUnprocessable();
        $this->postJson($s['base'].'/document/'.$last['id'].'/cancel', $cancel)->assertCreated();
        $this->postJson($s['base'].'/document/'.$first['id'].'/cancel', [...$cancel, 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->reverse($s, $package, 'cancel');
        $this->postJson($s['base'].'/document/'.$s['bill']['id'].'/cancel', [...$cancel, 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        foreach (['goods_in_transit', 'sales_unfulfilled', 'receivables', 'sales', 'cogs'] as $key) {
            $this->assertSame(0, $this->balance($s, $key));
        }
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '50000');
    }

    public function test_plain_invoice_return_rejects_fulfilment_modes_and_sources_instead_of_receiving_stock(): void
    {
        $s = $this->shop();
        $bill = $this->postJson($s['base'].'/documents/sale', ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '100']], 'expected_total_paisa' => '10000'])->assertCreated()->json('data');
        $input = ['business_date_bs' => 20830103, 'reason' => 'Reject misleading source mode', 'lines' => [['source_line_id' => $bill['lines'][0]['id'], 'qty' => '1', 'return_mode' => 'unfulfilled']]];
        $path = $s['base'].'/document/'.$bill['id'].'/returns';
        $this->postJson($path.'/preview', $input)->assertUnprocessable();
        $this->postJson($path, [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $input['lines'][0] = [...$input['lines'][0], 'return_mode' => 'completed', 'return_source' => '1'];
        $this->postJson($path.'/preview', $input)->assertUnprocessable();
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('workflow_return_allocations')->count());
        unset($input['lines'][0]['return_source']);
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path, [...$input, 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '50000');
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
        $this->assertArrayNotHasKey('inventory_cost_paisa', $package['delivery_confirmations'][0]['allocations'][0]);
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
