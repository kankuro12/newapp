<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfilmentTest extends TestCase
{
    use RefreshDatabase;

    private function shop(bool $stock = true, bool $zeroCost = false): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Staged shop'])->assertCreated()->json('data');
        $base = '/api/app/'.$business['slug'];
        $party = $this->postJson($base.'/contacts', ['name' => 'Buyer supplier', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Measured stock', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => $stock ? [['item_id' => $item, 'qty' => '10', 'value' => $zeroCost ? '0' : '50', 'zero_cost_confirmed' => $zeroCost]] : [], 'parties' => []])->assertCreated();

        return compact('owner', 'business', 'base', 'party', 'item');
    }

    private function order(array $s, string $kind = 'sales_order', bool $tax = false): array
    {
        if ($tax) {
            DB::table('tenants')->where('id', $s['business']['id'])->update(['tax_recording_enabled' => true]);
        }
        $input = ['mutation_uuid' => (string) Str::uuid(), 'kind' => $kind, 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '3.501', 'unit_price' => $kind === 'purchase_order' ? '50' : '100', 'tax_category' => $tax ? 'standard' : 'outside_scope', 'tax_bps' => $tax ? 1300 : 0]], 'expected_total_paisa' => $kind === 'purchase_order' ? ($tax ? '19781' : '17505') : '35010'];

        return $this->postJson($s['base'].'/workflows', $input)->assertCreated()->json('data');
    }

    private function input(array $order, string $qty, bool $vat = false): array
    {
        return ['version' => $order['version'], 'business_date_bs' => 20830102, 'vat_recoverable' => $vat, 'reference' => 'Reviewed stage', 'lines' => [['position' => 1, 'qty' => $qty]]];
    }

    private function stage(array $s, array $order, array $input): array
    {
        $path = $s['base'].'/workflow/'.$order['id'].'/fulfilments';
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $save = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        $data = $this->postJson($path, $save)->assertCreated()->json('data');
        $this->postJson($path, $save)->assertOk()->assertJsonPath('data.id', $data['id']);

        return $data;
    }

    private function balance(array $s, string $key): int
    {
        $account = DB::table('accounts')->where('tenant_id', $s['business']['id'])->where('system_key', $key)->value('id');
        $this->assertNotNull($account);

        return (int) DB::table('journal_lines')->where('tenant_id', $s['business']['id'])->where('account_id', $account)->selectRaw('COALESCE(SUM(debit_paisa-credit_paisa),0) AS value')->value('value');
    }

    private function current(array $s, array $order): array
    {
        return $this->getJson($s['base'].'/workflow/'.$order['id'])->assertOk()->json('data');
    }

    private function cancel(array $s, array $stage, array $order): void
    {
        $input = ['mutation_uuid' => (string) Str::uuid(), 'version' => $stage['version'], 'workflow_version' => $order['version'], 'business_date_bs' => 20830103, 'reason' => 'Reverse reviewed action'];
        $path = $s['base'].'/fulfilment/'.$stage['id'].'/cancel';
        $this->postJson($path, $input)->assertCreated()->assertJsonPath('data.status', 'cancelled');
        $this->postJson($path, $input)->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_fractional_delivery_returns_cancellation_and_stock_cleanup(): void
    {
        $s = $this->shop();
        $order = $this->order($s);
        $first = $this->stage($s, $order, $this->input($order, '1.251'));
        $this->assertSame('626', $first['clearing_value_paisa']);
        $order = $this->current($s, $order);
        $this->assertSame('1251', $order['fulfilment']['lines'][0]['completed_qty_milli']);
        $this->assertSame('2250', $order['fulfilment']['lines'][0]['remaining_qty_milli']);
        $second = $this->stage($s, $order, $this->input($order, '2.250'));
        $order = $this->current($s, $order);
        $this->assertSame('0', $order['fulfilment']['lines'][0]['remaining_qty_milli']);
        $this->assertSame(1751, $this->balance($s, 'delivered_unbilled'));
        $this->assertSame(0, $this->balance($s, 'cogs'));
        $this->assertSame(0, $this->balance($s, 'receivables'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '6499')->assertJsonPath('data.value_paisa', '3249');
        $return = $this->stage($s, $order, [...$this->input($order, '0.251'), 'source_id' => $first['id']]);
        $this->assertSame('126', $return['clearing_value_paisa']);
        $order = $this->current($s, $order);
        $this->assertSame('251', $order['fulfilment']['lines'][0]['remaining_qty_milli']);
        $this->assertSame(1625, $this->balance($s, 'delivered_unbilled'));
        $this->cancel($s, $return, $order);
        $order = $this->current($s, $order);
        $this->postJson($s['base'].'/fulfilment/'.$first['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'workflow_version' => $order['version'], 'business_date_bs' => 20830103, 'reason' => 'Earlier activity'])->assertConflict();
        $this->cancel($s, $second, $order);
        $order = $this->current($s, $order);
        $this->cancel($s, $first, $order);
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '5000');
        $this->assertSame(0, $this->balance($s, 'delivered_unbilled'));
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_receipts_freeze_tax_cost_and_supplier_return_variance(): void
    {
        $s = $this->shop();
        $order = $this->order($s, 'purchase_order', true);
        $first = $this->stage($s, $order, $this->input($order, '1.251', true));
        $this->assertSame('6255', $first['clearing_value_paisa']);
        $order = $this->current($s, $order);
        $this->assertSame(-6255, $this->balance($s, 'received_unbilled'));
        $this->assertSame(0, $this->balance($s, 'payables'));
        $this->assertSame(0, $this->balance($s, 'input_vat'));
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/fulfilments/preview', $this->input($order, '1', false))->assertConflict();
        $return = $this->stage($s, $order, [...$this->input($order, '0.251', true), 'source_id' => $first['id']]);
        $this->assertSame('1255', $return['clearing_value_paisa']);
        $this->assertSame('251', $return['inventory_value_paisa']);
        $this->assertSame(-5000, $this->balance($s, 'received_unbilled'));
        $this->assertSame(-1004, $this->balance($s, 'inventory_gain'));
        $order = $this->current($s, $order);
        $this->cancel($s, $return, $order);
        $order = $this->current($s, $order);
        $this->cancel($s, $first, $order);
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '5000');
        $this->assertSame(0, $this->balance($s, 'received_unbilled'));
        $this->assertSame(0, $this->balance($s, 'inventory_gain'));
    }

    public function test_review_limits_original_retry_and_workflow_protection(): void
    {
        $s = $this->shop();
        $order = $this->order($s);
        $path = $s['base'].'/workflow/'.$order['id'].'/fulfilments';
        $input = $this->input($order, '1');
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->postJson($path, [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($path, [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint'], 'lines' => [['position' => 1, 'qty' => '1.001']]])->assertConflict();
        foreach (['0', '-1', '3.502', '1e3'] as $qty) {
            $this->postJson($path.'/preview', $this->input($order, $qty))->assertUnprocessable();
        }
        $save = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        $stage = $this->postJson($path, $save)->assertCreated()->json('data');
        $this->postJson($path, $save)->assertOk()->assertJsonPath('data.id', $stage['id']);
        $this->postJson($path.'/preview', $input)->assertConflict();
        $order = $this->current($s, $order);
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/bill', ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'business_date_bs' => 20830103, 'paid_now' => '0', 'expected_total_paisa' => '35010'])->assertConflict();
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'status' => 'cancelled', 'reason' => 'Pending delivery'])->assertConflict();
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'status' => 'fulfilled'])->assertConflict();
        $this->assertSame(0, DB::table('documents')->count());
        $foreign = $this->postJson('/api/businesses', ['name' => 'Foreign shop'])->assertCreated()->json('data');
        $this->getJson('/api/app/'.$foreign['slug'].'/fulfilment/'.$stage['id'])->assertNotFound();
        $this->postJson('/api/app/'.$foreign['slug'].'/workflow/'.$order['id'].'/fulfilments/preview', $input)->assertNotFound();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->getJson($s['base'].'/fulfilment/'.$stage['id'])->assertForbidden();
        $this->postJson($path, $save)->assertConflict();
        $this->postJson($path, [...$save, 'mutation_uuid' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_service_completion_and_zero_cost_stock_have_no_empty_journal(): void
    {
        $s = $this->shop(false);
        $service = $this->postJson($s['base'].'/items', ['name' => 'Completed job', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $order = $this->order([...$s, 'item' => $service]);
        $stage = $this->stage($s, $order, $this->input($order, '3.501'));
        $this->assertNull($stage['journal_id']);
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
        $order = $this->current($s, $order);
        $this->cancel($s, $stage, $order);
        $this->assertSame(0, DB::table('journal_entries')->count());
        $zero = $this->shop(true, true);
        $zeroOrder = $this->order($zero);
        $zeroStage = $this->stage($zero, $zeroOrder, $this->input($zeroOrder, '3.501'));
        $this->assertSame('0', $zeroStage['clearing_value_paisa']);
        $this->assertNull($zeroStage['journal_id']);
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->cancel($zero, $zeroStage, $this->current($zero, $zeroOrder));
        $this->getJson($zero['base'].'/items/'.$zero['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000')->assertJsonPath('data.value_paisa', '0');
    }

    public function test_inventory_change_invalidates_review_and_client_cost_or_duplicate_lines_are_rejected(): void
    {
        $s = $this->shop();
        $order = $this->order($s);
        $path = $s['base'].'/workflow/'.$order['id'].'/fulfilments';
        $input = $this->input($order, '1');
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $other = $this->order($s);
        $this->stage($s, $other, $this->input($other, '1'));
        $this->postJson($path, [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']])->assertConflict();
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->postJson($path.'/preview', [...$input, 'lines' => [['position' => 1, 'qty' => '1', 'clearing_value_paisa' => '0']]])->assertUnprocessable();
        $this->postJson($path.'/preview', [...$input, 'lines' => [['position' => 1, 'qty' => '1'], ['position' => 1, 'qty' => '1']]])->assertUnprocessable();
        $this->stage($s, $order, $input);
        $migration = require database_path('migrations/2026_10_04_015829_create_workflow_fulfilments.php');
        try {
            $migration->down();
            $this->fail('Rollback erased fulfilment history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Preserve fulfilment history', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('workflow_fulfilments')->count());
    }

    public function test_nonrecoverable_tax_receipts_and_source_returns_consume_exact_final_paisa(): void
    {
        $s = $this->shop(false);
        $order = $this->order($s, 'purchase_order', true);
        $first = $this->stage($s, $order, $this->input($order, '1.251'));
        $this->assertSame('7068', $first['clearing_value_paisa']);
        $order = $this->current($s, $order);
        $second = $this->stage($s, $order, $this->input($order, '2.250'));
        $this->assertSame('12713', $second['clearing_value_paisa']);
        $this->assertSame(-19781, $this->balance($s, 'received_unbilled'));
        $this->assertSame(0, $this->balance($s, 'input_vat'));
        foreach (['0.251', '1'] as $qty) {
            $order = $this->current($s, $order);
            $this->stage($s, $order, [...$this->input($order, $qty), 'source_id' => $first['id']]);
        }
        $order = $this->current($s, $order);
        $this->assertSame('1251', $order['fulfilment']['lines'][0]['remaining_qty_milli']);
        $this->assertSame(-12713, $this->balance($s, 'received_unbilled'));
        $last = $this->stage($s, $order, $this->input($order, '1.251'));
        $this->assertSame('7068', $last['clearing_value_paisa']);
        $this->assertSame(-19781, $this->balance($s, 'received_unbilled'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '3501')->assertJsonPath('data.value_paisa', '19781');
    }

    public function test_source_returns_survive_archiving_and_preserve_original_unit(): void
    {
        $s = $this->shop();
        $order = $this->order($s);
        $stage = $this->stage($s, $order, $this->input($order, '1'));
        $order = $this->current($s, $order);
        DB::table('items')->where('id', $s['item'])->update(['archived_at' => now(), 'unit_label' => 'Renamed kg']);
        DB::table('contacts')->where('id', $s['party'])->update(['archived_at' => now(), 'is_customer' => false]);
        $return = $this->stage($s, $order, [...$this->input($order, '1'), 'source_id' => $stage['id']]);
        $this->assertSame('kg', $return['lines'][0]['item_snapshot']['unit_snapshot']);
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000');
    }

    public function test_receipt_after_fractional_source_return_never_allocates_negative_paisa(): void
    {
        $s = $this->shop(false);
        $order = $this->postJson($s['base'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'purchase_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '0.01']], 'expected_total_paisa' => '1'])->assertCreated()->json('data');
        $first = $this->stage($s, $order, $this->input($order, '0.6'));
        $this->assertSame('1', $first['clearing_value_paisa']);
        $order = $this->current($s, $order);
        $return = $this->stage($s, $order, [...$this->input($order, '0.2'), 'source_id' => $first['id']]);
        $this->assertSame('0', $return['clearing_value_paisa']);
        $order = $this->current($s, $order);
        $tiny = $this->stage($s, $order, $this->input($order, '0.001'));
        $this->assertSame('0', $tiny['clearing_value_paisa']);
        $order = $this->current($s, $order);
        $this->stage($s, $order, $this->input($order, '0.599'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '1000')->assertJsonPath('data.value_paisa', '1');
        $this->assertSame(-1, $this->balance($s, 'received_unbilled'));
        $this->assertSame('0', $this->current($s, $order)['fulfilment']['lines'][0]['remaining_qty_milli']);
    }

    public function test_zero_cost_cancellation_cannot_change_closed_period(): void
    {
        $s = $this->shop(true, true);
        $order = $this->order($s);
        $stage = $this->stage($s, $order, $this->input($order, '1'));
        $order = $this->current($s, $order);
        DB::table('tenants')->where('id', $s['business']['id'])->update(['closed_through_bs' => 20830102]);
        $this->postJson($s['base'].'/fulfilment/'.$stage['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'workflow_version' => $order['version'], 'business_date_bs' => 20830103, 'reason' => 'Closed zero-cost action'])->assertUnprocessable();
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '9000');
        $this->getJson($s['base'].'/fulfilment/'.$stage['id'])->assertOk()->assertJsonPath('data.status', 'posted');
    }

    public function test_source_receipt_return_keeps_recorded_tax_treatment_after_setting_disabled(): void
    {
        $s = $this->shop();
        $order = $this->order($s, 'purchase_order', true);
        $receipt = $this->stage($s, $order, $this->input($order, '1.251', true));
        $order = $this->current($s, $order);
        DB::table('tenants')->where('id', $s['business']['id'])->update(['tax_recording_enabled' => false]);
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/fulfilments/preview', $this->input($order, '1', true))->assertUnprocessable();
        $return = $this->stage($s, $order, [...$this->input($order, '1.251', true), 'source_id' => $receipt['id']]);
        $this->assertTrue($return['vat_recoverable']);
        $this->assertSame('6255', $return['clearing_value_paisa']);
        $this->assertSame(0, $this->balance($s, 'received_unbilled'));
        $this->assertSame(0, $this->balance($s, 'input_vat'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '10000');
    }

    public function test_service_dates_ignore_unrelated_stock_chronology(): void
    {
        $s = $this->shop();
        $service = $this->postJson($s['base'].'/items', ['name' => 'Earlier service', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $serviceOrder = $this->order([...$s, 'item' => $service]);
        DB::table('tenants')->where('id', $s['business']['id'])->update(['last_stock_date_bs' => 20830103]);
        $completion = $this->stage($s, $serviceOrder, $this->input($serviceOrder, '1'));
        $this->assertNull($completion['journal_id']);
    }

    public function test_cashier_own_stage_hides_cost_and_checks_revocation_and_database_ownership(): void
    {
        $s = $this->shop();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $order = $this->order($s);
        $input = $this->input($order, '1');
        $path = $s['base'].'/workflow/'.$order['id'].'/fulfilments';
        $preview = $this->postJson($path.'/preview', $input)->assertOk()->json('data');
        $this->assertArrayNotHasKey('clearing_value_paisa', $preview);
        $this->assertArrayNotHasKey('inventory_value_paisa', $preview['lines'][0]);
        $save = [...$input, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $preview['fingerprint']];
        $stage = $this->postJson($path, $save)->assertCreated()->json('data');
        $this->assertArrayNotHasKey('clearing_value_paisa', $stage);
        $this->assertArrayNotHasKey('inventory_value_paisa', $stage['lines'][0]);
        $this->postJson($path, $save)->assertOk()->assertJsonPath('data.id', $stage['id']);
        $this->postJson($s['base'].'/fulfilment/'.$stage['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'workflow_version' => 2, 'business_date_bs' => 20830103, 'reason' => 'Cashier cancellation'])->assertForbidden();
        DB::table('tenant_user')->where('tenant_id', $s['business']['id'])->where('user_id', $cashier->id)->update(['active' => false]);
        $this->postJson($path, $save)->assertNotFound();
        $this->actingAs($s['owner'], 'tenant');
        $foreign = $this->postJson('/api/businesses', ['name' => 'FK other shop'])->assertCreated()->json('data');
        try {
            DB::table('workflow_fulfilments')->where('id', $stage['id'])->update(['tenant_id' => $foreign['id']]);
            $this->fail('Composite workflow ownership FK accepted a foreign tenant.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
    }
}
