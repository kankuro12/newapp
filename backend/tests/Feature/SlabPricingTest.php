<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlabPricingTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Slab shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$tenant['slug'];
        $cash = $this->getJson($url.'/lookup')->json('data.accounts.0.id');
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $party = $this->postJson($url.'/contacts', ['name' => 'Buyer/vendor', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($url.'/items', ['name' => 'Meat', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100.25'])->assertCreated()->json('data.id');
        $this->postJson($url.'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'contact_id' => $party, 'lines' => [['item_id' => $item, 'qty' => '10', 'unit_price' => '50']], 'expected_total_paisa' => '50000', 'paid_now' => '0'])->assertCreated();

        return compact('owner', 'tenant', 'url', 'cash', 'party', 'item');
    }

    private function listInput(array $s): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Graduated', 'channel' => 'sale', 'pricing_scheme' => 'slab', 'enabled' => true, 'adjustment_mode' => 'increase', 'adjustment_percent' => '0', 'rules' => [['item_id' => $s['item'], 'min_qty' => '0', 'price' => '100.25'], ['item_id' => $s['item'], 'min_qty' => '2.500', 'price' => '90.35']]];
    }

    public function test_fractional_segments_combine_cart_then_post_exact_stock_and_cancel(): void
    {
        $s = $this->shop();
        $list = $this->postJson($s['url'].'/price-lists', $this->listInput($s))->assertCreated()->assertJsonPath('data.pricing_scheme', 'slab')->json('data');
        $query = $s['url'].'/price-suggestions?'.http_build_query(['channel' => 'sale', 'items' => [$s['item']], 'quantities' => [$s['item'] => '2.501'], 'price_list_id' => $list['id']]);
        $this->getJson($query)->assertOk()->assertJsonPath('data.0.pricing_scheme', 'slab')->assertJsonPath('data.0.gross_paisa', '25072')->assertJsonPath('data.0.segments.0.qty_milli', '2500')->assertJsonPath('data.0.segments.1.qty_milli', '1');
        $cart = ['contact_id' => $s['party'], 'price_list_id' => $list['id'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'quantity', 'value' => '1.25'], 'note' => 'Cut small'], ['item_id' => $s['item'], 'measurement' => ['mode' => 'quantity', 'value' => '1.251'], 'note' => 'Keep chilled']]];
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.total_paisa', '25072')->assertJsonCount(2, 'data.lines')->json('data');
        $post = [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint'], 'paid_now' => '0'];
        $doc = $this->postJson($s['url'].'/pos/sales', $post)->assertCreated()->assertJsonCount(2, 'data.lines')->json('data');
        $this->postJson($s['url'].'/pos/sales', $post)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 7499]);
        $this->assertSame('2500', $doc['lines'][0]['measurement_snapshot']['qty_milli']);
        $this->assertSame('1', $doc['lines'][1]['measurement_snapshot']['qty_milli']);
        $this->assertSame('slab', $doc['lines'][1]['measurement_snapshot']['pricing_scheme']);
        $this->assertSame('Cut small ; Keep chilled', $doc['lines'][1]['measurement_snapshot']['note']);
        $this->postJson($s['url'].'/document/'.$doc['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Wrong slab sale'])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
    }

    public function test_guards_scheme_changes_units_amount_entry_and_party_price_priority(): void
    {
        $s = $this->shop();
        $input = $this->listInput($s);
        $bad = $input;
        $bad['rules'] = [$bad['rules'][1]];
        $this->postJson($s['url'].'/price-lists', $bad)->assertUnprocessable();
        $list = $this->postJson($s['url'].'/price-lists', $input)->assertCreated()->json('data');
        $cart = ['price_list_id' => $list['id'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'quantity', 'value' => '2.501']]]];
        $old = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->json('data');
        $this->postJson($s['url'].'/pos/preview', [...$cart, 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'amount', 'value' => '300']]]])->assertUnprocessable();
        $input['mutation_uuid'] = (string) Str::uuid();
        $input['version'] = $list['version'];
        $input['pricing_scheme'] = 'volume';
        $this->patchJson($s['url'].'/price-lists/'.$list['id'], $input)->assertCreated();
        $this->postJson($s['url'].'/pos/sales', [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'expected_fingerprint' => $old['fingerprint'], 'expected_total_paisa' => $old['total_paisa'], 'paid_now' => '250.72', 'money_account_id' => $s['cash']])->assertConflict();
        $this->postJson($s['url'].'/contacts/'.$s['party'].'/rates', ['mutation_uuid' => (string) Str::uuid(), 'item_id' => $s['item'], 'channel' => 'sale', 'price' => '80', 'enabled' => true])->assertCreated();
        $this->postJson($s['url'].'/pos/preview', [...$cart, 'contact_id' => $s['party']])->assertOk()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.total_paisa', '20008');
        $foreign = $this->shop();
        $foreignList = $this->postJson($foreign['url'].'/price-lists', $this->listInput($foreign))->assertCreated()->json('data.id');
        $this->actingAs($s['owner'], 'tenant');
        $this->postJson($s['url'].'/pos/preview', [...$cart, 'price_list_id' => $foreignList])->assertNotFound();
    }

    public function test_csv_round_trip_preserves_slab_scheme_and_legacy_update(): void
    {
        $s = $this->shop();
        $input = $this->listInput($s);
        $list = $this->postJson($s['url'].'/price-lists', $input)->assertCreated()->json('data');
        unset($input['pricing_scheme']);
        $input['version'] = $list['version'];
        $input['mutation_uuid'] = (string) Str::uuid();
        $this->patchJson($s['url'].'/price-lists/'.$list['id'], $input)->assertCreated()->assertJsonPath('data.pricing_scheme', 'slab');
        $csv = $this->get($s['url'].'/imports/price_lists/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('pricing_scheme', $csv);
        $review = $this->postJson($s['url'].'/imports/preview', ['resource' => 'price_lists', 'csv' => $csv])->assertOk()->json('data');
        $this->assertTrue($review['valid']);
        $this->postJson($s['url'].'/imports', ['resource' => 'price_lists', 'csv' => $csv, 'digest' => $review['digest'], 'version' => $review['version'], 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->assertDatabaseHas('price_lists', ['id' => $list['id'], 'pricing_scheme' => 'slab']);
    }

    public function test_accepted_slab_quote_and_returns_preserve_original_segment_totals(): void
    {
        $s = $this->shop();
        $input = $this->listInput($s);
        $list = $this->postJson($s['url'].'/price-lists', $input)->assertCreated()->json('data');
        $query = $s['url'].'/price-suggestions?'.http_build_query(['channel' => 'sale', 'items' => [$s['item']], 'quantities' => [$s['item'] => '2.501'], 'price_list_id' => $list['id']]);
        $pricing = $this->getJson($query)->assertOk()->json('data.0');
        $lines = array_map(fn ($segment) => ['item_id' => $s['item'], 'qty' => Money::format($segment['qty_milli'], 3), 'unit_price' => Money::format($segment['price_paisa'])], $pricing['segments']);
        $quote = $this->postJson($s['url'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'quote', 'contact_id' => $s['party'], 'business_date_bs' => 20830103, 'lines' => $lines, 'expected_total_paisa' => '25072'])->assertCreated()->json('data');
        foreach (['sent', 'accepted'] as $status) {
            $quote = $this->postJson($s['url'].'/workflow/'.$quote['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'status' => $status, 'reason' => 'Customer approved'])->assertCreated()->json('data');
        }
        $input['version'] = $list['version'];
        $input['mutation_uuid'] = (string) Str::uuid();
        $input['rules'][0]['price'] = '200';
        $this->patchJson($s['url'].'/price-lists/'.$list['id'], $input)->assertCreated();
        $doc = $this->postJson($s['url'].'/workflow/'.$quote['id'].'/bill', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'business_date_bs' => 20830103, 'paid_now' => '0', 'expected_total_paisa' => '25072'])->assertCreated()->assertJsonPath('data.lines.0.unit_price_paisa', '10025')->json('data');
        foreach (array_reverse($doc['lines']) as $line) {
            $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Returned slab goods', 'lines' => [['source_line_id' => $line['id'], 'qty' => Money::format($line['qty_milli'], 3)]]])->assertCreated()->assertJsonPath('data.total_paisa', $line['total_paisa']);
        }
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
        $this->getJson($s['url'].'/document/'.$doc['id'])->assertOk()->assertJsonPath('data.returned_paisa', '25072')->assertJsonPath('data.credit_paisa', '0');
        $this->postJson($s['url'].'/documents/sale', ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $s['party'], 'business_date_bs' => 20830104, 'lines' => [['item_id' => $s['item'], 'qty' => '1000000', 'unit_price' => '1'], ['item_id' => $s['item'], 'qty' => '0.001', 'unit_price' => '1']], 'paid_now' => '0'])->assertUnprocessable();
    }
}
