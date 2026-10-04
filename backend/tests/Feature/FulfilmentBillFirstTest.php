<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfilmentBillFirstTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $kind = 'sales_order', string $itemKind = 'stock'): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Bill before fulfilment'])->assertCreated()->json('data');
        $base = '/api/app/'.$business['slug'];
        $party = $this->postJson($base.'/contacts', ['name' => 'Named buyer supplier', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Ordered item', 'kind' => $itemKind, 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $cash = $this->getJson($base.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [['account_id' => $cash, 'amount' => '1000']], 'stock' => $itemKind === 'stock' ? [['item_id' => $item, 'qty' => '10', 'value' => '500']] : [], 'parties' => []])->assertCreated();
        $order = $this->postJson($base.'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => $kind, 'contact_id' => $party, 'business_date_bs' => 20830102, 'lines' => [['item_id' => $item, 'qty' => '5', 'unit_price' => $kind === 'purchase_order' ? '50' : '100', 'tax_category' => 'outside_scope', 'tax_bps' => 0]], 'expected_total_paisa' => $kind === 'purchase_order' ? '25000' : '50000'])->assertCreated()->json('data');

        return compact('owner', 'business', 'base', 'party', 'item', 'cash', 'order');
    }

    private function current(array $s): array
    {
        return $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->json('data');
    }

    private function balance(array $s, string $key): int
    {
        $account = DB::table('accounts')->where('tenant_id', $s['business']['id'])->where('system_key', $key)->value('id');
        $this->assertNotNull($account, 'Missing system account '.$key);

        return (int) DB::table('journal_lines')->where('tenant_id', $s['business']['id'])->where('account_id', $account)->selectRaw('COALESCE(SUM(debit_paisa-credit_paisa),0) AS value')->value('value');
    }

    private function bill(array $s, string $qty, string $total): array
    {
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills';
        $input = ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'vat_recoverable' => false, 'lines' => [['position' => 1, 'qty' => $qty]]];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->assertSame($total, $preview['total_paisa']);
        $payload = [...$input, 'expected_total_paisa' => $total, 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()];
        $bill = $this->postJson($path, $payload)->assertCreated()->json('data');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $bill['id']);
        $this->assertSame('bill_first', $bill['fulfilment_policy']);

        return $bill;
    }

    private function fulfil(array $s, array $bill, string $qty): array
    {
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/billed-fulfilments';
        $input = ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'handover_confirmed' => true, 'lines' => [['document_line_id' => $bill['lines'][0]['id'], 'qty' => $qty]]];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $payload = [...$input, 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()];
        $stage = $this->postJson($path, $payload)->assertCreated()->json('data');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $stage['id']);

        return $stage;
    }

    private function returned(array $s, array $bill, string $qty, string $source): array
    {
        return $this->postJson($s['base'].'/document/'.$bill['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Original source credit', 'lines' => [['source_line_id' => $bill['lines'][0]['id'], 'qty' => $qty, 'return_source' => $source]]])->assertCreated()->json('data');
    }

    public function test_sale_invoice_waits_for_real_handover_before_revenue_cost_or_stock_movement(): void
    {
        $s = $this->shop();
        $bill = $this->bill($s, '3', '30000');
        $this->assertSame(30000, $this->balance($s, 'receivables'));
        $this->assertSame(-30000, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(0, $this->balance($s, 'sales'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->assertSame('0', $bill['lines'][0]['inventory_cost_paisa']);
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '50000');

        $this->fulfil($s, $bill, '2');
        $this->assertSame(-20000, $this->balance($s, 'sales'));
        $this->assertSame(10000, $this->balance($s, 'cogs'));
        $this->assertSame(-10000, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '8000')->assertJsonPath('data.value_paisa', '40000');
        $this->postJson($s['base'].'/document/'.$bill['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Dependent handover must block'])->assertStatus(409);
    }

    public function test_purchase_bill_waits_in_held_value_until_actual_partial_receipt(): void
    {
        $s = $this->shop('purchase_order');
        $bill = $this->bill($s, '5', '25000');
        $this->assertSame(-25000, $this->balance($s, 'payables'));
        $this->assertSame(25000, $this->balance($s, 'billed_unreceived'));
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->fulfil($s, $bill, '2');
        $this->assertSame(15000, $this->balance($s, 'billed_unreceived'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '12000')->assertJsonPath('data.value_paisa', '60000');
        $this->returned($s, $bill, '1', 'unfulfilled');
        $this->assertSame(-20000, $this->balance($s, 'payables'));
        $this->assertSame(10000, $this->balance($s, 'billed_unreceived'));
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->fulfil($s, $bill, '2');
        $this->assertSame(0, $this->balance($s, 'billed_unreceived'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '14000')->assertJsonPath('data.value_paisa', '70000');
    }

    public function test_crediting_unfulfilled_sale_quantity_does_not_receive_goods_that_never_left_stock(): void
    {
        $s = $this->shop();
        $bill = $this->bill($s, '3', '30000');
        $stage = $this->fulfil($s, $bill, '2');
        $this->returned($s, $bill, '1', 'unfulfilled');
        $this->assertSame(20000, $this->balance($s, 'receivables'));
        $this->assertSame(0, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(2, DB::table('stock_movements')->count());
        $source = DB::table('workflow_dispatch_allocations')->where('tenant_id', $s['business']['id'])->where('document_line_id', $bill['lines'][0]['id'])->where('fulfilment_line_id', $stage['lines'][0]['id'])->value('id');
        $this->assertNotNull($source);
        $return = $this->returned($s, $bill, '1', (string) $source);
        $this->assertSame('5000', $return['lines'][0]['inventory_cost_paisa']);
        $this->assertSame(10000, $this->balance($s, 'receivables'));
        $this->assertSame(-20000, $this->balance($s, 'sales'));
        $this->assertSame(10000, $this->balance($s, 'sales_returns'));
        $this->assertSame(5000, $this->balance($s, 'cogs'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '9000')->assertJsonPath('data.value_paisa', '45000');
        $this->assertSame('3000', $this->current($s)['fulfilment']['lines'][0]['billed_qty_milli']);
    }

    public function test_service_bills_wait_for_completed_work_without_creating_stock_movements(): void
    {
        $s = $this->shop('sales_order', 'service');
        $bill = $this->bill($s, '3', '30000');
        $this->assertSame(0, $this->balance($s, 'sales'));
        $this->fulfil($s, $bill, '2');
        $this->assertSame(-20000, $this->balance($s, 'sales'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->assertSame(0, DB::table('stock_movements')->count());

        $this->assertSame('2000', $this->current($s)['fulfilment']['lines'][0]['completed_qty_milli']);
    }

    private function cancelDocument(array $s, array $doc): void
    {
        $this->postJson($s['base'].'/document/'.$doc['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Reverse source bookkeeping'])->assertCreated();
    }

    private function cancelStage(array $s, array $stage, int $status = 201): void
    {
        $this->postJson($s['base'].'/fulfilment/'.$stage['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'version' => $stage['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830103, 'reason' => 'Reverse physical action'])->assertStatus($status);
    }

    public function test_credit_physical_return_dispatch_and_bill_reverse_in_dependency_order(): void
    {
        $s = $this->shop();
        $bill = $this->bill($s, '3', '30000');
        $stage = $this->fulfil($s, $bill, '2');
        $credit = $this->returned($s, $bill, '1', 'unfulfilled');
        $allocation = DB::table('workflow_dispatch_allocations')->where('document_line_id', $bill['lines'][0]['id'])->value('id');
        $physical = $this->returned($s, $bill, '1', (string) $allocation);
        $this->cancelStage($s, $stage, 409);
        $this->cancelDocument($s, $physical);
        $this->cancelDocument($s, $credit);
        $this->cancelStage($s, $stage);
        $this->cancelDocument($s, $bill);
        foreach (['sales_unfulfilled', 'receivables', 'sales', 'sales_returns', 'cogs'] as $key) {
            $this->assertSame(0, $this->balance($s, $key));
        }
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '50000');
        $this->assertSame('bill_first', $this->current($s)['fulfilment_policy']);
        $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/fulfilments/preview', ['version' => $this->current($s)['version'], 'business_date_bs' => 20830103, 'lines' => [['position' => 1, 'qty' => '1']]])->assertStatus(409);
    }

    public function test_billed_return_requires_explicit_source_and_cannot_credit_delivered_goods_as_unfulfilled(): void
    {
        $s = $this->shop();
        $bill = $this->bill($s, '3', '30000');
        $this->fulfil($s, $bill, '2');
        $before = DB::table('journal_entries')->count();
        $path = $s['base'].'/document/'.$bill['id'].'/returns';
        $input = ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Reject wrong source', 'lines' => [['source_line_id' => $bill['lines'][0]['id'], 'qty' => '1']]];
        $this->postJson($path, $input)->assertUnprocessable();
        $input['lines'][0] = [...$input['lines'][0], 'qty' => '2', 'return_source' => 'unfulfilled'];
        $this->postJson($path, $input)->assertUnprocessable();
        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_physical_return_uses_original_dispatch_cost_after_later_average_changes(): void
    {
        $s = $this->shop();
        $bill = $this->bill($s, '3', '30000');
        $stage = $this->fulfil($s, $bill, '1');
        $this->postJson($s['base'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '100']], 'expected_total_paisa' => '10000'])->assertCreated();
        $this->fulfil($s, $bill, '1');
        $allocation = DB::table('workflow_dispatch_allocations')->where('fulfilment_line_id', $stage['lines'][0]['id'])->value('id');
        $returned = $this->returned($s, $bill, '1', (string) $allocation);
        $this->assertSame('5000', $returned['lines'][0]['inventory_cost_paisa']);
        $this->assertSame(5500, $this->balance($s, 'cogs'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '54500');
    }

    public function test_fixed_recoverable_tax_is_retained_when_later_ordered_bill_omits_optional_flag(): void
    {
        $s = $this->shop('purchase_order');
        DB::table('tenants')->where('id', $s['business']['id'])->update(['tax_recording_enabled' => true]);
        $s['order'] = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'purchase_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '5', 'unit_price' => '100', 'tax_category' => 'standard', 'tax_bps' => 1300]], 'expected_total_paisa' => '56500'])->assertCreated()->json('data');
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills';
        $input = ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'vat_recoverable' => true, 'lines' => [['position' => 1, 'qty' => '1']]];
        $review = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path, [...$input, 'expected_fingerprint' => $review['fingerprint'], 'expected_total_paisa' => '11300', 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        unset($input['vat_recoverable']);
        $input['version'] = $this->current($s)['version'];
        $this->postJson($path.'/preview', $input)->assertOk()->assertJsonPath('data.terms.vat_recoverable', true);
        $this->assertSame(10000, $this->balance($s, 'billed_unreceived'));
        $this->assertSame(1300, $this->balance($s, 'input_vat'));
    }

    public function test_receiving_two_bills_of_same_order_position_preserves_each_original_penny_cost(): void
    {
        $s = $this->shop('purchase_order');
        $s['order'] = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'purchase_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '3', 'unit_price' => '0.01']], 'expected_total_paisa' => '3'])->assertCreated()->json('data');
        $first = $this->bill($s, '1.5', '2');
        $second = $this->bill($s, '1.5', '1');
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/billed-fulfilments';
        $input = ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'handover_confirmed' => true, 'lines' => [['document_line_id' => $second['lines'][0]['id'], 'qty' => '1.5'], ['document_line_id' => $first['lines'][0]['id'], 'qty' => '1.5']]];
        $review = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path, [...$input, 'expected_fingerprint' => $review['fingerprint'], 'mutation_uuid' => (string) Str::uuid()])->assertCreated()->assertJsonCount(1, 'data.lines');
        $costs = DB::table('workflow_dispatch_allocations')->get()->keyBy('document_line_id');
        $this->assertSame(1, (int) $costs[$second['lines'][0]['id']]->inventory_cost_paisa);
        $this->assertSame(2, (int) $costs[$first['lines'][0]['id']]->inventory_cost_paisa);
        $this->assertSame(0, $this->balance($s, 'billed_unreceived'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '13000')->assertJsonPath('data.value_paisa', '50003');
    }

    public function test_billed_source_progress_and_slip_do_not_offer_an_unbilled_return(): void
    {
        $s = $this->shop();
        $bill = $this->bill($s, '3', '30000');
        $stage = $this->fulfil($s, $bill, '2');
        $this->getJson($s['base'].'/fulfilment/'.$stage['id'])->assertOk()->assertJsonPath('data.party_snapshot.name', 'Named buyer supplier')->assertJsonPath('data.lines.0.billable_qty_milli', '0')->assertJsonPath('data.lines.0.returnable_qty_milli', '0');
        $this->getJson($s['base'].'/document/'.$bill['id'])->assertOk()->assertJsonPath('data.lines.0.unfulfilled_qty_milli', '1000')->assertJsonPath('data.lines.0.fulfilment_sources.0.returnable_qty_milli', '2000');
    }

    public function test_unfulfilled_credit_closes_dispatch_capacity_without_becoming_a_backorder(): void
    {
        $s = $this->shop();
        $bill = $this->bill($s, '5', '50000');
        $this->fulfil($s, $bill, '4');
        $this->returned($s, $bill, '1', 'unfulfilled');
        $line = $this->current($s)['fulfilment']['lines'][0];
        $this->assertSame('0', $line['remaining_qty_milli']);
        $this->assertSame('0', $line['billable_qty_milli']);
        $this->assertSame('1000', $line['credited_unfulfilled_qty_milli']);
        $this->assertSame('5000', $line['billed_qty_milli']);
    }

    public function test_same_total_changed_notes_and_missing_actual_handover_cannot_post(): void
    {
        $s = $this->shop();
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills';
        $input = ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'notes' => 'Reviewed terms', 'lines' => [['position' => 1, 'qty' => '3']]];
        $review = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path, [...$input, 'notes' => 'Different terms', 'expected_fingerprint' => $review['fingerprint'], 'expected_total_paisa' => '30000', 'mutation_uuid' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(0, DB::table('documents')->count());
        $bill = $this->bill($s, '3', '30000');
        $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/billed-fulfilments/preview', ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'handover_confirmed' => false, 'lines' => [['document_line_id' => $bill['lines'][0]['id'], 'qty' => '1']]])->assertUnprocessable();
        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    public function test_foreign_branch_bill_and_dispatch_sources_fail_without_financial_writes(): void
    {
        $foreign = $this->shop();
        $foreignBill = $this->bill($foreign, '3', '30000');
        $foreignStage = $this->fulfil($foreign, $foreignBill, '1');
        $foreignAllocation = DB::table('workflow_dispatch_allocations')->where('fulfilment_line_id', $foreignStage['lines'][0]['id'])->value('id');
        $s = $this->shop();
        $bill = $this->bill($s, '3', '30000');
        $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/billed-fulfilments/preview', ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'handover_confirmed' => true, 'lines' => [['document_line_id' => $foreignBill['lines'][0]['id'], 'qty' => '1']]])->assertNotFound();
        $this->postJson($s['base'].'/document/'.$bill['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Reject foreign delivery', 'lines' => [['source_line_id' => $bill['lines'][0]['id'], 'qty' => '1', 'return_source' => (string) $foreignAllocation]]])->assertNotFound();
        $this->assertSame(0, DB::table('workflow_return_allocations')->count());
        $this->assertSame(1, DB::table('workflow_dispatch_allocations')->count());
        $this->assertSame(2, DB::table('documents')->count());
        $this->assertSame(-30000, $this->balance($s, 'sales_unfulfilled'));
    }

    public function test_zero_paisa_unfulfilled_sale_credit_does_not_release_a_future_delivery_penny_as_income(): void
    {
        $s = $this->shop();
        $s['order'] = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '3', 'unit_price' => '0.01']], 'invoice_discount' => '0.02', 'expected_total_paisa' => '1'])->assertCreated()->json('data');
        $bill = $this->bill($s, '3', '1');
        $this->fulfil($s, $bill, '1');
        $credit = $this->returned($s, $bill, '1', 'unfulfilled');
        $this->assertSame('0', $credit['total_paisa']);
        $this->assertSame(-1, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(0, $this->balance($s, 'sales_returns'));
        $this->fulfil($s, $bill, '1');
        $this->assertSame(0, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(-1, $this->balance($s, 'sales'));
        $this->assertSame(10000, $this->balance($s, 'cogs'));
        $this->assertSame(3, DB::table('stock_movements')->count());
    }

    public function test_zero_paisa_unreceived_purchase_credit_preserves_cost_for_actual_final_receipt(): void
    {
        $s = $this->shop('purchase_order');
        $s['order'] = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'purchase_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '3', 'unit_price' => '0.01']], 'invoice_discount' => '0.02', 'expected_total_paisa' => '1'])->assertCreated()->json('data');
        $bill = $this->bill($s, '3', '1');
        $this->fulfil($s, $bill, '1');
        $credit = $this->returned($s, $bill, '1', 'unfulfilled');
        $this->assertSame('0', $credit['total_paisa']);
        $this->assertSame(1, $this->balance($s, 'billed_unreceived'));
        $this->assertSame(0, $this->balance($s, 'inventory_loss'));
        $this->fulfil($s, $bill, '1');
        $this->assertSame(0, $this->balance($s, 'billed_unreceived'));
        $this->assertSame(0, $this->balance($s, 'inventory_loss'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '12000')->assertJsonPath('data.value_paisa', '50001');
    }

    private function reviewedBill(array $s, string $qty = '3', string $total = '30000'): array
    {
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills';
        $input = ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['position' => 1, 'qty' => $qty]]];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');

        return [$path, [...$input, 'expected_total_paisa' => $total, 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()], $preview];
    }

    private function reviewedDispatch(array $s, array $bill): array
    {
        $path = $s['base'].'/workflow/'.$s['order']['id'].'/billed-fulfilments';
        $input = ['version' => $this->current($s)['version'], 'business_date_bs' => 20830102, 'handover_confirmed' => true, 'lines' => [['document_line_id' => $bill['lines'][0]['id'], 'qty' => '1']]];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');

        return [$path, [...$input, 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()], $preview];
    }

    private function cashierShop(): array
    {
        $s = $this->shop();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $s['cashier'] = $cashier;
        $s['owner_order'] = $s['order'];
        $s['order'] = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '5', 'unit_price' => '100']], 'expected_total_paisa' => '50000'])->assertCreated()->json('data');

        return $s;
    }

    public function test_cashier_bill_and_handover_hide_cost_and_original_retries_require_current_membership(): void
    {
        $s = $this->cashierShop();
        [$billPath, $billPayload, $billPreview] = $this->reviewedBill($s);
        $this->assertArrayNotHasKey('context', $billPreview);
        $this->assertArrayNotHasKey('clearing_value_paisa', $billPreview['allocations'][0]);
        $bill = $this->postJson($billPath, $billPayload)->assertCreated()->json('data');
        $this->assertArrayNotHasKey('inventory_cost_paisa', $bill['lines'][0]);
        $this->postJson($billPath, $billPayload)->assertOk()->assertJsonPath('data.id', $bill['id']);
        [$dispatchPath, $dispatchPayload, $dispatchPreview] = $this->reviewedDispatch($s, $bill);
        $this->assertArrayNotHasKey('pools_after', $dispatchPreview);
        $this->assertArrayNotHasKey('inventory_value_paisa', $dispatchPreview['lines'][0]);
        $this->assertArrayNotHasKey('clearing_value_paisa', $dispatchPreview['lines'][0]);
        $this->assertArrayNotHasKey('inventory_cost_paisa', $dispatchPreview['allocations'][0]);
        $this->assertArrayNotHasKey('pending_value_paisa', $dispatchPreview['allocations'][0]);
        $stage = $this->postJson($dispatchPath, $dispatchPayload)->assertCreated()->json('data');
        foreach (['inventory_value_paisa', 'clearing_value_paisa', 'journal_id', 'reversal_journal_id', 'recognition_journal_id'] as $private) {
            $this->assertArrayNotHasKey($private, $stage);
        }
        $this->assertArrayNotHasKey('inventory_value_paisa', $stage['lines'][0]);
        $this->postJson($dispatchPath, $dispatchPayload)->assertOk()->assertJsonPath('data.id', $stage['id']);
        $ownerPath = $s['base'].'/workflow/'.$s['owner_order']['id'];
        $this->postJson($ownerPath.'/ordered-bills/preview', $billPayload)->assertForbidden();
        $this->postJson($ownerPath.'/billed-fulfilments/preview', $dispatchPayload)->assertForbidden();
        DB::table('tenant_user')->where('tenant_id', $s['business']['id'])->where('user_id', $s['cashier']->id)->update(['active' => false]);
        $this->postJson($billPath, $billPayload)->assertNotFound();
        $this->postJson($dispatchPath, $dispatchPayload)->assertNotFound();
        $this->getJson($s['base'].'/fulfilment/'.$stage['id'])->assertNotFound();
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_cashier_replay_rechecks_parent_ownership_after_original_bill_and_handover(): void
    {
        $s = $this->cashierShop();
        [$billPath, $billPayload] = $this->reviewedBill($s);
        $bill = $this->postJson($billPath, $billPayload)->assertCreated()->json('data');
        [$dispatchPath, $dispatchPayload] = $this->reviewedDispatch($s, $bill);
        $stage = $this->postJson($dispatchPath, $dispatchPayload)->assertCreated()->json('data');
        // Simulate revoked source ownership without rewriting original actor or mutation records.
        DB::table('business_workflows')->where('id', $s['order']['id'])->update(['created_by' => $s['owner']->id]);
        $this->postJson($billPath, $billPayload)->assertForbidden();
        $this->postJson($dispatchPath, $dispatchPayload)->assertForbidden();
        $this->getJson($s['base'].'/fulfilment/'.$stage['id'])->assertForbidden();
        $this->getJson($s['base'].'/document/'.$bill['id'])->assertForbidden();
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_purchase_original_retries_are_denied_after_actor_is_downgraded_to_cashier(): void
    {
        $s = $this->shop('purchase_order');
        [$billPath, $billPayload] = $this->reviewedBill($s, '3', '15000');
        $bill = $this->postJson($billPath, $billPayload)->assertCreated()->json('data');
        [$dispatchPath, $dispatchPayload] = $this->reviewedDispatch($s, $bill);
        $this->postJson($dispatchPath, $dispatchPayload)->assertCreated();
        DB::table('tenant_user')->where('tenant_id', $s['business']['id'])->where('user_id', $s['owner']->id)->update(['role' => 'cashier']);
        $this->postJson($billPath, $billPayload)->assertForbidden();
        $this->postJson($dispatchPath, $dispatchPayload)->assertForbidden();
        $this->postJson($billPath.'/preview', $billPayload)->assertForbidden();
        $this->postJson($dispatchPath.'/preview', $dispatchPayload)->assertForbidden();
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_original_uuid_cannot_be_reused_for_changed_input_or_another_actor(): void
    {
        $s = $this->shop();
        [$billPath, $billPayload] = $this->reviewedBill($s);
        $bill = $this->postJson($billPath, $billPayload)->assertCreated()->json('data');
        [$dispatchPath, $dispatchPayload] = $this->reviewedDispatch($s, $bill);
        $this->postJson($dispatchPath, $dispatchPayload)->assertCreated();
        $this->postJson($billPath, [...$billPayload, 'notes' => 'Changed retry notes'])->assertStatus(409);
        $this->postJson($dispatchPath, [...$dispatchPayload, 'notes' => 'Changed retry notes'])->assertStatus(409);
        $manager = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $manager->id, 'role' => 'manager', 'active' => true]);
        $this->actingAs($manager, 'tenant');
        $this->postJson($billPath, $billPayload)->assertStatus(409);
        $this->postJson($dispatchPath, $dispatchPayload)->assertStatus(409);
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_archived_sources_and_closed_or_pre_source_dates_block_reviewed_writes_without_side_effects(): void
    {
        $s = $this->shop();
        [$billPath, $billPayload] = $this->reviewedBill($s);
        DB::table('items')->where('id', $s['item'])->update(['archived_at' => now()]);
        $this->postJson($billPath, $billPayload)->assertUnprocessable();
        $this->assertSame(0, DB::table('documents')->count());
        DB::table('items')->where('id', $s['item'])->update(['archived_at' => null]);
        $bill = $this->postJson($billPath, $billPayload)->assertCreated()->json('data');
        [$dispatchPath, $dispatchPayload] = $this->reviewedDispatch($s, $bill);
        DB::table('contacts')->where('id', $s['party'])->update(['archived_at' => now()]);
        $this->postJson($dispatchPath, $dispatchPayload)->assertUnprocessable();
        DB::table('contacts')->where('id', $s['party'])->update(['archived_at' => null]);
        DB::table('tenants')->where('id', $s['business']['id'])->update(['closed_through_bs' => 20830102]);
        $this->postJson($dispatchPath, $dispatchPayload)->assertUnprocessable();
        DB::table('tenants')->where('id', $s['business']['id'])->update(['closed_through_bs' => null]);
        $this->postJson($dispatchPath, [...$dispatchPayload, 'business_date_bs' => 20830101])->assertUnprocessable();
        $this->assertSame(0, DB::table('workflow_fulfilments')->count());
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->assertSame(-30000, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(0, $this->balance($s, 'sales'));
    }

    public function test_billed_handover_retry_rechecks_cashier_ownership_of_each_original_source_bill(): void
    {
        $s = $this->shop();
        $manager = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $manager->id, 'role' => 'manager', 'active' => true]);
        $this->actingAs($manager, 'tenant');
        [$billPath, $billPayload] = $this->reviewedBill($s);
        $bill = $this->postJson($billPath, $billPayload)->assertCreated()->json('data');
        $this->actingAs($s['owner'], 'tenant');
        [$dispatchPath, $dispatchPayload] = $this->reviewedDispatch($s, $bill);
        $this->postJson($dispatchPath, $dispatchPayload)->assertCreated();
        DB::table('tenant_user')->where('tenant_id', $s['business']['id'])->where('user_id', $s['owner']->id)->update(['role' => 'cashier']);
        // Parent and physical action remain this actor's, but the source bill is another staff member's.
        $this->postJson($dispatchPath, $dispatchPayload)->assertForbidden();
        $this->postJson($dispatchPath.'/preview', [...$dispatchPayload, 'version' => 3])->assertForbidden();
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_credit_penny_ahead_of_physical_target_carries_forward_and_final_delivery_clears_exact_residual(): void
    {
        $s = $this->shop();
        $s['order'] = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '7', 'unit_price' => '0.01']], 'invoice_discount' => '0.04', 'expected_total_paisa' => '3'])->assertCreated()->json('data');
        $bill = $this->bill($s, '7', '3');
        $stage = $this->fulfil($s, $bill, '2');
        $allocation = DB::table('workflow_dispatch_allocations')->where('fulfilment_line_id', $stage['lines'][0]['id'])->value('id');
        $this->returned($s, $bill, '1', (string) $allocation);
        $this->returned($s, $bill, '1', 'unfulfilled');
        $this->fulfil($s, $bill, '0.001');
        $this->assertSame(-1, $this->balance($s, 'sales_unfulfilled'));
        $this->fulfil($s, $bill, '3.999');
        $this->assertSame(0, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(-2, $this->balance($s, 'sales'));
        $this->assertSame(2, $this->balance($s, 'receivables'));
        $this->assertSame(25000, $this->balance($s, 'cogs'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '5000')->assertJsonPath('data.value_paisa', '25000');
    }
}
