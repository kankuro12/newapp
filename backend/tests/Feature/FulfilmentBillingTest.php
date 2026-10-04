<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfilmentBillingTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $kind = 'sales_order', ?array $lines = null, string $total = '35010', bool $tax = false, string $discount = '0', string $stockValue = '50'): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Partial bills'])->assertCreated()->json('data');
        $base = '/api/app/'.$business['slug'];
        $party = $this->postJson($base.'/contacts', ['name' => 'Stage buyer supplier', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Source stock', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $cash = $this->getJson($base.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [['account_id' => $cash, 'amount' => '1000']], 'stock' => [['item_id' => $item, 'qty' => '10', 'value' => $stockValue]], 'parties' => []])->assertCreated();
        if ($tax) {
            DB::table('tenants')->where('id', $business['id'])->update(['tax_recording_enabled' => true]);
        }
        $lines ??= [['qty' => '3.501', 'unit_price' => $kind === 'purchase_order' ? '50' : '100', 'tax_category' => $tax ? 'standard' : 'outside_scope', 'tax_bps' => $tax ? 1300 : 0]];
        $lines = array_map(fn ($line) => ['item_id' => $item, ...$line], $lines);
        $order = $this->postJson($base.'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => $kind, 'contact_id' => $party, 'business_date_bs' => 20830102, 'lines' => $lines, 'invoice_discount' => $discount, 'expected_total_paisa' => $total])->assertCreated()->json('data');

        return compact('owner', 'business', 'base', 'party', 'item', 'cash', 'order');
    }

    private function current(array $s): array
    {
        return $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->json('data');
    }

    private function stage(array $s, array $lines, bool $vat = false, ?string $source = null): array
    {
        $order = $this->current($s);
        $path = $s['base'].'/workflow/'.$order['id'].'/fulfilments';
        $input = ['version' => $order['version'], 'business_date_bs' => 20830102, 'vat_recoverable' => $vat, 'source_id' => $source, 'lines' => $lines];
        $proof = $this->postJson($path.'/preview', $input)->assertOk()->json('data.fingerprint');

        return $this->postJson($path, [...$input, 'expected_fingerprint' => $proof, 'mutation_uuid' => (string) Str::uuid()])->assertCreated()->json('data');
    }

    private function bill(array $s, array $lines, string $expected): array
    {
        $order = $this->current($s);
        $path = $s['base'].'/workflow/'.$order['id'].'/staged-bills';
        $input = ['version' => $order['version'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => $lines];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->assertSame($expected, $preview['total_paisa']);
        $payload = [...$input, 'expected_fingerprint' => $preview['fingerprint'], 'expected_total_paisa' => $expected, 'mutation_uuid' => (string) Str::uuid()];
        $doc = $this->postJson($path, $payload)->assertCreated()->json('data');
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $doc['id']);

        return $doc;
    }

    private function balance(array $s, string $key): int
    {
        $account = DB::table('accounts')->where('tenant_id', $s['business']['id'])->where('system_key', $key)->value('id');
        $this->assertNotNull($account);

        return (int) DB::table('journal_lines')->where('tenant_id', $s['business']['id'])->where('account_id', $account)->selectRaw('COALESCE(SUM(debit_paisa-credit_paisa),0) AS value')->value('value');
    }

    private function cancelBill(array $s, array $doc, int $status = 201): void
    {
        $this->postJson($s['base'].'/document/'.$doc['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Reverse staged bill'])->assertStatus($status);
    }

    private function cancelStage(array $s, array $stage, int $status = 201): void
    {
        $this->postJson($s['base'].'/fulfilment/'.$stage['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'version' => $stage['version'], 'workflow_version' => $this->current($s)['version'], 'business_date_bs' => 20830103, 'reason' => 'Reverse staged source'])->assertStatus($status);
    }

    public function test_partial_sale_bills_recognize_frozen_cost_without_second_stock_movement(): void
    {
        $s = $this->shop();
        $stage = $this->stage($s, [['position' => 1, 'qty' => '3.501']]);
        $first = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1.251']], '12510');
        $this->assertSame('626', $first['lines'][0]['inventory_cost_paisa']);
        $this->assertSame(1125, $this->balance($s, 'delivered_unbilled'));
        $this->assertSame(626, $this->balance($s, 'cogs'));
        $second = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '2.250']], '22500');
        $this->assertSame('1125', $second['lines'][0]['inventory_cost_paisa']);
        $this->assertSame(0, $this->balance($s, 'delivered_unbilled'));
        $this->assertSame(1751, $this->balance($s, 'cogs'));
        $this->assertSame(35010, $this->balance($s, 'receivables'));
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '6499')->assertJsonPath('data.value_paisa', '3249');
        $this->assertSame('3501', $this->current($s)['fulfilment']['lines'][0]['billed_qty_milli']);
    }

    public function test_partial_purchase_bills_clear_receipts_and_recover_original_tax_residual(): void
    {
        $s = $this->shop('purchase_order', total: '19781', tax: true);
        $receipt = $this->stage($s, [['position' => 1, 'qty' => '3.501']], true);
        $first = $this->bill($s, [['fulfilment_line_id' => $receipt['lines'][0]['id'], 'qty' => '1.251']], '7068');
        $this->assertSame('813', $first['tax_paisa']);
        $second = $this->bill($s, [['fulfilment_line_id' => $receipt['lines'][0]['id'], 'qty' => '2.250']], '12713');
        $this->assertSame('1463', $second['tax_paisa']);
        $this->assertSame(0, $this->balance($s, 'received_unbilled'));
        $this->assertSame(-19781, $this->balance($s, 'payables'));
        $this->assertSame(2276, $this->balance($s, 'input_vat'));
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '13501')->assertJsonPath('data.value_paisa', '22505');
    }

    public function test_multiple_discount_allocation_never_creates_negative_partial_net_and_positions_stay_distinct(): void
    {
        $s = $this->shop(lines: [['qty' => '1', 'unit_price' => '0.02', 'discount' => '0.01'], ['qty' => '1.5', 'unit_price' => '0.02']], total: '2', discount: '0.02');
        $stage = $this->stage($s, [['position' => 1, 'qty' => '1'], ['position' => 2, 'qty' => '1.5']]);
        $selection = [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '0.5'], ['fulfilment_line_id' => $stage['lines'][1]['id'], 'qty' => '0.75']];
        $first = $this->bill($s, $selection, '1');
        $this->assertSame('0', $first['lines'][0]['net_base_paisa']);
        $this->assertSame('2', $first['lines'][0]['gross_paisa']);
        $this->assertSame('1', $first['source_order_snapshot']['lines'][0]['gross_rounding_paisa']);
        $second = $this->bill($s, $selection, '1');
        $this->assertSame('0', $second['lines'][0]['gross_paisa']);
        $this->assertSame('-1', $second['source_order_snapshot']['lines'][0]['gross_rounding_paisa']);
        $this->assertSame(2, $this->balance($s, 'receivables'));
        $this->assertCount(2, $first['lines']);
        $this->assertCount(2, $second['lines']);
        $this->assertSame(0, $this->balance($s, 'delivered_unbilled'));
    }

    public function test_source_tax_allocation_reconciles_when_fresh_partial_tax_rounds_to_zero(): void
    {
        $s = $this->shop(lines: [['qty' => '2', 'unit_price' => '0.02', 'tax_category' => 'standard', 'tax_bps' => 1300]], total: '5', tax: true);
        $stage = $this->stage($s, [['position' => 1, 'qty' => '2']]);
        $first = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1']], '3');
        $this->assertSame('1', $first['tax_paisa']);
        $this->assertSame('1', $first['source_order_snapshot']['lines'][0]['tax_rounding_paisa']);
        $second = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1']], '2');
        $this->assertSame('0', $second['tax_paisa']);
        $this->assertSame(-1, $this->balance($s, 'output_vat'));
        $this->assertSame(5, $this->balance($s, 'receivables'));
    }

    public function test_billed_cost_is_protected_from_unbilled_returns_and_cancels_in_dependency_order(): void
    {
        $s = $this->shop(lines: [['qty' => '2', 'unit_price' => '100']], total: '20000', stockValue: '0.05');
        $stage = $this->stage($s, [['position' => 1, 'qty' => '2']]);
        $bill = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1']], '10000');
        $this->assertSame('1', $bill['lines'][0]['inventory_cost_paisa']);
        $order = $this->current($s);
        $path = $s['base'].'/workflow/'.$order['id'].'/fulfilments/preview';
        $this->postJson($path, ['version' => $order['version'], 'business_date_bs' => 20830102, 'source_id' => $stage['id'], 'lines' => [['position' => 1, 'qty' => '1.001']]])->assertUnprocessable();
        $return = $this->stage($s, [['position' => 1, 'qty' => '1']], source: $stage['id']);
        $this->assertSame('0', $return['clearing_value_paisa']);
        $this->assertSame(0, $this->balance($s, 'delivered_unbilled'));
        $this->cancelBill($s, $bill, 409);
        $this->cancelStage($s, $return);
        $this->cancelStage($s, $stage, 409);
        $this->cancelBill($s, $bill);
        $this->assertSame(1, $this->balance($s, 'delivered_unbilled'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->cancelStage($s, $stage);
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '5');
        $this->assertSame(0, $this->balance($s, 'receivables'));
    }

    public function test_later_bills_cancel_first_and_release_source_capacity_without_stock_reversal(): void
    {
        $s = $this->shop();
        $stage = $this->stage($s, [['position' => 1, 'qty' => '3.501']]);
        $first = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1.251']], '12510');
        $second = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '2.25']], '22500');
        $this->cancelBill($s, $first, 409);
        $this->cancelBill($s, $second);
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->assertSame('2250', $this->current($s)['fulfilment']['lines'][0]['billable_qty_milli']);
        $this->cancelBill($s, $first);
        $this->assertSame('3501', $this->current($s)['fulfilment']['lines'][0]['billable_qty_milli']);
        $this->assertSame(1751, $this->balance($s, 'delivered_unbilled'));
        $this->cancelStage($s, $stage);
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '5000');
        $this->assertSame(0, $this->balance($s, 'delivered_unbilled'));
    }

    public function test_billed_return_uses_original_amount_cost_and_does_not_reopen_order_capacity(): void
    {
        $s = $this->shop();
        $stage = $this->stage($s, [['position' => 1, 'qty' => '3.501']]);
        $bill = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '3.501']], '35010');
        $input = ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Return billed goods', 'lines' => [['source_line_id' => $bill['lines'][0]['id'], 'qty' => '1.251']]];
        $return = $this->postJson($s['base'].'/document/'.$bill['id'].'/returns', $input)->assertCreated()->json('data');
        $this->postJson($s['base'].'/document/'.$bill['id'].'/returns', $input)->assertOk()->assertJsonPath('data.id', $return['id']);
        $this->assertSame('12510', $return['total_paisa']);
        $this->assertSame('626', $return['lines'][0]['inventory_cost_paisa']);
        $this->assertSame('cumulative_source_bill_return', $return['source_order_snapshot']['allocation']);
        $line = $this->current($s)['fulfilment']['lines'][0];
        $this->assertSame('3501', $line['billed_qty_milli']);
        $this->assertSame('1251', $line['billed_returned_qty_milli']);
        $this->assertSame('0', $line['billable_qty_milli']);
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '7750')->assertJsonPath('data.value_paisa', '3875');
        $this->cancelBill($s, $bill, 422);
        $this->cancelBill($s, $return);
        $this->assertSame('0', $this->current($s)['fulfilment']['lines'][0]['billed_returned_qty_milli']);
        $this->cancelBill($s, $bill);
        $this->cancelStage($s, $stage);
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '5000');
    }

    public function test_same_total_payment_change_invalidates_review_and_source_limits_block_overbilling(): void
    {
        $s = $this->shop();
        $stage = $this->stage($s, [['position' => 1, 'qty' => '1']]);
        $order = $this->current($s);
        $path = $s['base'].'/workflow/'.$order['id'].'/staged-bills';
        $input = ['version' => $order['version'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'money_account_id' => $s['cash'], 'lines' => [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1']]];
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path, [...$input, 'paid_now' => '1', 'expected_fingerprint' => $preview['fingerprint'], 'expected_total_paisa' => '10000', 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->postJson($path.'/preview', [...$input, 'lines' => [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1.001']]])->assertUnprocessable();
        $this->postJson($path.'/preview', [...$input, 'lines' => [$input['lines'][0], $input['lines'][0]]])->assertUnprocessable();
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_supplier_penny_variance_is_disclosed_without_second_stock_and_reconciles_final_cost(): void
    {
        $s = $this->shop('purchase_order', [['qty' => '1', 'unit_price' => '0.01', 'tax_category' => 'standard', 'tax_bps' => 10000], ['qty' => '1', 'unit_price' => '0.01', 'tax_category' => 'outside_scope', 'tax_bps' => 0]], '3', true);
        $receipt = $this->stage($s, [['position' => 1, 'qty' => '1'], ['position' => 2, 'qty' => '1']]);
        $first = $this->bill($s, [['fulfilment_line_id' => $receipt['lines'][0]['id'], 'qty' => '0.4'], ['fulfilment_line_id' => $receipt['lines'][1]['id'], 'qty' => '0.5']], '1');
        $this->assertSame('-1', $first['source_order_snapshot']['lines'][0]['cost_variance_paisa']);
        $this->assertSame(-1, $this->balance($s, 'inventory_gain'));
        $this->bill($s, [['fulfilment_line_id' => $receipt['lines'][0]['id'], 'qty' => '0.6'], ['fulfilment_line_id' => $receipt['lines'][1]['id'], 'qty' => '0.5']], '2');
        $this->assertSame(1, $this->balance($s, 'inventory_loss'));
        $this->assertSame(0, $this->balance($s, 'received_unbilled'));
        $this->assertSame(-3, $this->balance($s, 'payables'));
        $this->assertSame(0, $this->balance($s, 'input_vat'));
        $this->assertSame(3, DB::table('stock_movements')->count());
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '12000')->assertJsonPath('data.value_paisa', '5003');
    }

    public function test_cashier_own_billing_hides_cost_and_original_retry_checks_membership(): void
    {
        $s = $this->shop();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $order = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '100']], 'expected_total_paisa' => '10000'])->assertCreated()->json('data');
        $own = [...$s, 'order' => $order];
        $stage = $this->stage($own, [['position' => 1, 'qty' => '1']]);
        $order = $this->current($own);
        $input = ['version' => $order['version'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1']]];
        $path = $s['base'].'/workflow/'.$order['id'].'/staged-bills';
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->assertArrayNotHasKey('context', $preview);
        $this->assertArrayNotHasKey('clearing_value_paisa', $preview['allocations'][0]);
        $payload = [...$input, 'expected_total_paisa' => '10000', 'expected_fingerprint' => $preview['fingerprint'], 'mutation_uuid' => (string) Str::uuid()];
        $doc = $this->postJson($path, $payload)->assertCreated()->json('data');
        $this->assertArrayNotHasKey('inventory_cost_paisa', $doc['lines'][0]);
        $this->postJson($path, $payload)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/staged-bills/preview', $input)->assertForbidden();
        DB::table('tenant_user')->where('tenant_id', $s['business']['id'])->where('user_id', $cashier->id)->update(['active' => false]);
        $this->postJson($path, $payload)->assertNotFound();
    }

    public function test_agreed_offer_is_frozen_and_foreign_source_and_database_links_are_blocked(): void
    {
        $s = $this->shop();
        $offer = $this->postJson($s['base'].'/basket-offers', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Agreed save ten', 'offer_kind' => 'basket', 'discount_mode' => 'fixed', 'discount_value' => '10', 'minimum_spend' => '0', 'enabled' => true, 'cashier_allowed' => true])->assertCreated()->json('data.id');
        $input = ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'basket_offer_id' => $offer, 'lines' => [['item_id' => $s['item'], 'qty' => '2', 'unit_price' => '100']]];
        $proof = $this->postJson($s['base'].'/workflows/preview', $input)->assertOk()->json('data');
        $order = $this->postJson($s['base'].'/workflows', [...$input, 'expected_total_paisa' => '19000', 'expected_fingerprint' => $proof['fingerprint']])->assertCreated()->json('data');
        $own = [...$s, 'order' => $order];
        $stage = $this->stage($own, [['position' => 1, 'qty' => '2']]);
        DB::table('basket_offers')->where('id', $offer)->update(['enabled' => false]);
        DB::table('items')->where('id', $s['item'])->update(['sale_price_paisa' => 99900, 'unit_label' => 'Renamed kg', 'archived_at' => now()]);
        $doc = $this->bill($own, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1']], '9500');
        $this->assertSame('Agreed save ten', $doc['source_order_snapshot']['offer']['name']);
        $this->assertSame('500', $doc['invoice_discount_paisa']);
        $this->assertSame('10000', $doc['lines'][0]['unit_price_paisa']);
        $this->assertSame('kg', $doc['lines'][0]['unit_snapshot']);
        $wrongOrder = $this->current($s);
        $this->postJson($s['base'].'/workflow/'.$wrongOrder['id'].'/staged-bills/preview', ['version' => $wrongOrder['version'], 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '1']]])->assertNotFound();
        $other = $this->postJson('/api/businesses', ['name' => 'Foreign bill links'])->assertCreated()->json('data');
        $this->getJson('/api/app/'.$other['slug'].'/document/'.$doc['id'])->assertNotFound();
        try {
            DB::table('workflow_bill_allocations')->where('document_line_id', $doc['lines'][0]['id'])->update(['tenant_id' => $other['id']]);
            $this->fail('Foreign staged-bill ownership FK accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
    }

    public function test_copy_of_allocated_partial_bill_clears_source_discounts_for_fresh_review(): void
    {
        $s = $this->shop(lines: [['qty' => '1', 'unit_price' => '0.02', 'discount' => '0.01'], ['qty' => '1.5', 'unit_price' => '0.02']], total: '2', discount: '0.02');
        $stage = $this->stage($s, [['position' => 1, 'qty' => '1'], ['position' => 2, 'qty' => '1.5']]);
        $bill = $this->bill($s, [['fulfilment_line_id' => $stage['lines'][0]['id'], 'qty' => '0.5'], ['fulfilment_line_id' => $stage['lines'][1]['id'], 'qty' => '0.75']], '1');
        $this->postJson($s['base'].'/document/'.$bill['id'].'/clone', ['mutation_uuid' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_paisa', '3')->assertJsonPath('data.line_discount_paisa', '0')->assertJsonPath('data.invoice_discount_paisa', '0')->assertJsonPath('data.source_order_snapshot', null);
        $this->assertSame(3, DB::table('stock_movements')->count());
    }
}
