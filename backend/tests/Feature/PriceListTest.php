<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PriceListTest extends TestCase
{
    use RefreshDatabase;

    public function test_amount_checkout_rejects_changed_quantity_even_when_total_is_unchanged(): void
    {
        $s = $this->shop();
        $input = $this->listInput($s);
        $list = $this->postJson($s['url'].'/price-lists', $input)->assertCreated()->json('data');
        $cart = ['price_list_id' => $list['id'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'amount', 'value' => '90']]]];
        $old = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->json('data');
        $this->assertSame('9000', $old['total_paisa']);
        $this->assertSame('1000', $old['lines'][0]['qty_milli']);
        $input['mutation_uuid'] = (string) Str::uuid();
        $input['version'] = $list['version'];
        $input['rules'][0]['price'] = '120';
        $this->patchJson($s['url'].'/price-lists/'.$list['id'], $input)->assertCreated();
        $post = [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'expected_total_paisa' => $old['total_paisa'], 'expected_fingerprint' => $old['fingerprint'] ?? str_repeat('0', 64), 'paid_now' => '90', 'money_account_id' => $s['cash']];
        $this->postJson($s['url'].'/pos/sales', $post)->assertConflict();
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('payments', 0);
        $fresh = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->json('data');
        $this->assertSame($old['total_paisa'], $fresh['total_paisa']);
        $this->assertSame('750', $fresh['lines'][0]['qty_milli']);
        $this->assertNotSame($old['fingerprint'], $fresh['fingerprint']);
        $post['expected_fingerprint'] = $fresh['fingerprint'];
        $bill = $this->postJson($s['url'].'/pos/sales', $post)->assertCreated()->json('data');
        $this->assertSame('750', $bill['lines'][0]['qty_milli']);
        $this->postJson($s['url'].'/pos/sales', $post)->assertOk()->assertJsonPath('data.id', $bill['id']);
        $this->assertDatabaseCount('documents', 1);
    }

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Price shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$tenant['slug'];
        $cash = $this->getJson($url.'/lookup')->json('data.accounts.0.id');
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $party = $this->postJson($url.'/contacts', ['name' => 'Trade buyer', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($url.'/items', ['name' => 'Work', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '100.01'])->assertCreated()->json('data.id');
        DB::table('items')->where('id', $item)->update(['last_purchase_price_paisa' => 7000]);

        return compact('owner', 'tenant', 'url', 'cash', 'party', 'item');
    }

    private function listInput(array $s): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Wholesale', 'channel' => 'sale', 'enabled' => true, 'adjustment_mode' => 'decrease', 'adjustment_percent' => '10', 'starts_bs' => null, 'ends_bs' => null, 'rules' => [['item_id' => $s['item'], 'min_qty' => '0', 'price' => '90'], ['item_id' => $s['item'], 'min_qty' => '2.5', 'price' => '80.25']]];
    }

    private function suggest(array $s, int|string $list, string $qty, string $channel = 'sale', int $date = 20830103): string
    {
        return $s['url'].'/price-suggestions?'.http_build_query(['channel' => $channel, 'items' => [$s['item']], 'quantities' => [$s['item'] => $qty], 'price_list_id' => $list, 'business_date_bs' => $date]);
    }

    public function test_shared_lists_exact_thresholds_priority_replay_and_snapshots(): void
    {
        $s = $this->shop();
        $input = $this->listInput($s);
        $list = $this->postJson($s['url'].'/price-lists', $input)->assertCreated()->assertJsonCount(2, 'data.rules')->json('data');
        $this->postJson($s['url'].'/price-lists', $input)->assertOk()->assertJsonPath('data.id', $list['id']);
        foreach (['0.001' => '9000', '2.499' => '9000', '2.5' => '8025', '3' => '8025'] as $qty => $price) {
            $this->getJson($this->suggest($s, $list['id'], (string) $qty))->assertOk()->assertJsonPath('data.0.price_paisa', $price);
        }
        $assignment = ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'sales_price_list_id' => $list['id'], 'purchase_price_list_id' => null];
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/price-lists', $assignment)->assertCreated()->assertJsonPath('data.trading_version', 2);
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/price-lists', $assignment)->assertOk();
        $this->getJson($s['url'].'/lookup?contact_id='.$s['party'])->assertOk()->assertJsonPath('data.items.0.suggested_price_paisa', '9000');
        $pos = ['contact_id' => $s['party'], 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'quantity', 'value' => '1']], ['item_id' => $s['item'], 'measurement' => ['mode' => 'quantity', 'value' => '2']]]];
        $this->postJson($s['url'].'/pos/preview', $pos)->assertOk()->assertJsonPath('data.lines.0.qty_milli', '3000')->assertJsonPath('data.lines.0.unit_price_paisa', '8025')->assertJsonPath('data.total_paisa', '24075');
        $saleInput = [...$pos, 'mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'expected_total_paisa' => '24075', 'paid_now' => '240.75', 'money_account_id' => $s['cash']];
        $sale = $this->postJson($s['url'].'/pos/sales', $saleInput)->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/sales', $saleInput)->assertOk()->assertJsonPath('data.id', $sale['id']);
        $quote = $this->postJson($s['url'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'quote', 'contact_id' => $s['party'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '80.25']], 'expected_total_paisa' => '8025'])->assertCreated()->json('data');
        foreach (['sent', 'accepted'] as $status) {
            $quote = $this->postJson($s['url'].'/workflow/'.$quote['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'status' => $status])->assertCreated()->json('data');
        }
        $list = $this->patchJson($s['url'].'/price-lists/'.$list['id'], [...$input, 'mutation_uuid' => (string) Str::uuid(), 'version' => $list['version'], 'rules' => [['item_id' => $s['item'], 'min_qty' => '0', 'price' => '75']]])->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/sales', [...$saleInput, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(1, DB::table('documents')->where('type', 'sale')->count());
        $this->getJson($s['url'].'/document/'.$sale['id'])->assertOk()->assertJsonPath('data.lines.0.unit_price_paisa', '8025');
        $this->postJson($s['url'].'/document/'.$sale['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'One returned', 'lines' => [['source_line_id' => $sale['lines'][0]['id'], 'qty' => '1']], 'refund_now' => false])->assertCreated()->assertJsonPath('data.total_paisa', '8025');
        $this->postJson($s['url'].'/workflow/'.$quote['id'].'/bill', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'business_date_bs' => 20830104, 'expected_total_paisa' => '8025', 'paid_now' => '80.25', 'money_account_id' => $s['cash']])->assertCreated()->assertJsonPath('data.total_paisa', '8025')->assertJsonMissingPath('data.party_snapshot.purchase_price_list_id');
        $rate = ['mutation_uuid' => (string) Str::uuid(), 'item_id' => $s['item'], 'channel' => 'sale', 'price' => '70', 'enabled' => true];
        $this->postJson($s['url'].'/contacts/'.$s['party'].'/rates', $rate)->assertCreated();
        $this->postJson($s['url'].'/pos/preview', $pos)->assertOk()->assertJsonPath('data.total_paisa', '21000');
        $this->assertSame(1, DB::table('price_list_rates')->count());
    }

    public function test_percent_dates_amount_entry_and_rule_units(): void
    {
        $s = $this->shop();
        $input = [...$this->listInput($s), 'rules' => [], 'starts_bs' => 20830101, 'ends_bs' => 20830105];
        $list = $this->postJson($s['url'].'/price-lists', $input)->assertCreated()->json('data');
        $this->getJson($this->suggest($s, $list['id'], '1'))->assertOk()->assertJsonPath('data.0.price_paisa', '9001');
        $this->getJson($this->suggest($s, $list['id'], '1', 'sale', 20830106))->assertUnprocessable();
        $this->getJson($this->suggest($s, $list['id'], '1', 'sale', 20821230))->assertUnprocessable();
        $purchase = $this->postJson($s['url'].'/price-lists', [...$input, 'mutation_uuid' => (string) Str::uuid(), 'name' => 'Supplier offer', 'channel' => 'purchase', 'adjustment_mode' => 'increase'])->assertCreated()->json('data');
        $this->getJson($this->suggest($s, $purchase['id'], '1', 'purchase'))->assertOk()->assertJsonPath('data.0.price_paisa', '7700');
        $this->getJson($this->suggest($s, $purchase['id'], '1'))->assertUnprocessable();
        $list = $this->patchJson($s['url'].'/price-lists/'.$list['id'], [...$this->listInput($s), 'mutation_uuid' => (string) Str::uuid(), 'version' => $list['version']])->assertCreated()->json('data');
        $amount = ['price_list_id' => $list['id'], 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'amount', 'value' => '90']]]];
        $this->postJson($s['url'].'/pos/preview', $amount)->assertOk()->assertJsonPath('data.total_paisa', '9000');
        $this->postJson($s['url'].'/pos/preview', [...$amount, 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'amount', 'value' => '270']]]])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->postJson($s['url'].'/pos/preview', [...$amount, 'lines' => [...$amount['lines'], ['item_id' => $s['item'], 'measurement' => ['mode' => 'quantity', 'value' => '2']]]])->assertUnprocessable();
        $this->getJson($s['url'].'/lookup?price_list_id='.$list['id'])->assertOk()->assertJsonPath('data.items.0.suggested_price_paisa', '9000');
        DB::table('items')->where('id', $s['item'])->update(['unit_label' => 'changed']);
        $this->getJson($this->suggest($s, $list['id'], '3'))->assertUnprocessable();
    }

    public function test_editor_unit_snapshot_cannot_silently_change_during_save(): void
    {
        $s = $this->shop();
        $input = $this->listInput($s);
        $list = $this->postJson($s['url'].'/price-lists', $input)->assertCreated()->json('data');
        DB::table('items')->where('id', $s['item'])->update(['unit_label' => 'changed']);
        $this->getJson($s['url'].'/price-lists/'.$list['id'])->assertOk()->assertJsonPath('data.rules.0.current_unit_snapshot', 'changed');
        $rules = array_map(fn ($row) => [...$row, 'unit_snapshot' => 'job', 'pos_unit' => 'unit', 'item_kind' => 'service'], $input['rules']);
        $this->patchJson($s['url'].'/price-lists/'.$list['id'], [...$input, 'mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'rules' => $rules])->assertConflict();
        $this->assertSame(1, DB::table('price_lists')->where('id', $list['id'])->value('version'));
        $this->assertSame('job', DB::table('price_list_rates')->where('price_list_id', $list['id'])->value('unit_snapshot'));
    }

    public function test_list_validation_scope_roles_and_disabled_assignment(): void
    {
        $s = $this->shop();
        $input = $this->listInput($s);
        $list = $this->postJson($s['url'].'/price-lists', $input)->assertCreated()->json('data');
        foreach ([['adjustment_mode' => 'decrease', 'adjustment_percent' => '100'], ['adjustment_percent' => '1e3'], ['starts_bs' => 20830110, 'ends_bs' => 20830101], ['rules' => [$input['rules'][0], $input['rules'][0]]], ['rules' => ['named' => $input['rules'][0]]], ['rules' => [['item_id' => $s['item'], 'min_qty' => '1.0001', 'price' => '80']]], ['rules' => [['item_id' => $s['item'], 'min_qty' => '0', 'price' => '0']]]] as $patch) {
            $this->postJson($s['url'].'/price-lists', [...$input, ...$patch, 'name' => 'Invalid', 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->postJson($s['url'].'/price-lists', [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $eleven = array_map(fn ($qty) => ['item_id' => $s['item'], 'min_qty' => (string) $qty, 'price' => '80'], range(0, 10));
        $this->postJson($s['url'].'/price-lists', [...$input, 'name' => 'Too many tiers', 'rules' => $eleven, 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($s['url'].'/price-lists', [...$input, 'name' => 'Too many rows', 'rules' => array_fill(0, 1001, $input['rules'][0]), 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson($this->suggest($s, $list['id'], '0'))->assertUnprocessable();
        $this->patchJson($s['url'].'/price-lists/'.$list['id'], [...$input, 'mutation_uuid' => (string) Str::uuid(), 'version' => 2])->assertConflict();
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/price-lists', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'sales_price_list_id' => $list['id'], 'purchase_price_list_id' => null])->assertCreated();
        $this->patchJson($s['url'].'/price-lists/'.$list['id'], [...$input, 'mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'enabled' => false])->assertCreated();
        $this->getJson($s['url'].'/contacts/'.$s['party'].'/price-suggestions?items[]='.$s['item'].'&channel=sale')->assertUnprocessable();
        $this->patchJson($s['url'].'/price-lists/'.$list['id'], [...$input, 'mutation_uuid' => (string) Str::uuid(), 'version' => 2])->assertCreated();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->getJson($s['url'].'/price-lists?channel=sale')->assertOk();
        $this->getJson($s['url'].'/price-lists?channel=purchase')->assertForbidden();
        $this->postJson($s['url'].'/price-lists', $input)->assertForbidden();
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/price-lists', ['mutation_uuid' => (string) Str::uuid(), 'version' => 2, 'sales_price_list_id' => null, 'purchase_price_list_id' => null])->assertForbidden();
        $other = $this->shop();
        $this->getJson($other['url'].'/price-lists/'.$list['id'])->assertNotFound();
        $this->getJson($this->suggest($other, $list['id'], '1'))->assertNotFound();
        $this->postJson($other['url'].'/price-lists', [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertNotFound();
        $this->patchJson($other['url'].'/contacts/'.$other['party'].'/price-lists', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'sales_price_list_id' => $list['id'], 'purchase_price_list_id' => null])->assertNotFound();

        $this->expectException(QueryException::class);
        DB::table('contacts')->where('id', $other['party'])->update(['sales_price_list_id' => $list['id']]);
    }
}
