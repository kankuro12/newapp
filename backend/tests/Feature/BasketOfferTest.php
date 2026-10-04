<?php

namespace Tests\Feature;

use App\Models\User;
use App\NepaliDate;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BasketOfferTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Basket shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$tenant['slug'];
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $party = $this->postJson($url.'/contacts', ['name' => 'Buyer/vendor', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($url.'/items', ['name' => 'Basket item', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100.25'])->assertCreated()->json('data.id');
        $this->postJson($url.'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'contact_id' => $party, 'lines' => [['item_id' => $item, 'qty' => '10', 'unit_price' => '50']], 'expected_total_paisa' => '50000', 'paid_now' => '0'])->assertCreated();

        return compact('owner', 'tenant', 'url', 'party', 'item');
    }

    public function test_choice_bundle_resolves_overlap_without_reusing_quantity_and_returns_source_values(): void
    {
        $s = $this->shop();
        $category = $this->postJson($s['url'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Weighted choices'])->assertCreated()->json('data.id');
        DB::table('items')->where('id', $s['item'])->update(['category_id' => $category]);
        $other = $this->postJson($s['url'].'/items', ['name' => 'Other kg', 'kind' => 'service', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '80', 'category_id' => $category])->assertCreated()->json('data.id');
        $litre = $this->postJson($s['url'].'/items', ['name' => 'Litre excluded', 'kind' => 'service', 'unit_label' => 'l', 'pos_unit' => 'l', 'sale_price' => '40', 'category_id' => $category])->assertCreated()->json('data.id');
        $offer = $this->postJson($s['url'].'/basket-offers', $this->offer(['offer_kind' => 'bundle', 'minimum_spend' => '0', 'discount_value' => '150', 'rules' => [['role' => 'component', 'item_ids' => [$s['item']], 'category_ids' => [$category], 'pos_unit' => 'kg', 'qty' => '1'], ['role' => 'component', 'item_id' => $s['item'], 'qty' => '1']]]))->assertCreated()->json('data');
        $cart = $this->cart($s, $offer['id']);
        $cart['lines'][] = ['item_id' => $other, 'measurement' => ['mode' => 'quantity', 'value' => '2']];
        $cart['lines'][] = ['item_id' => $litre, 'measurement' => ['mode' => 'quantity', 'value' => '1']];
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.applications', 2)->assertJsonPath('data.basket_offer.discount_paisa', '7065')->assertJsonPath('data.total_paisa', '38008')->json('data');
        $this->assertSame('2501', $preview['basket_offer']['allocations'][0]['matched_qty_milli']);
        $this->assertSame('1499', $preview['basket_offer']['allocations'][1]['matched_qty_milli']);
        $this->assertCount(2, $preview['basket_offer']['allocations']);
        $this->assertSame(4000, array_sum(array_map(fn ($a) => (int) $a['qty_milli'], $preview['basket_offer']['assignments'])));
        $sale = [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']];
        $doc = $this->postJson($s['url'].'/pos/sales', $sale)->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/sales', $sale)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $returned = $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Choice set returned', 'lines' => array_map(fn ($line) => ['source_line_id' => $line['id'], 'qty' => Money::format((int) $line['qty_milli'], 3)], $doc['lines'])])->assertCreated()->json('data');
        $this->assertSame('38008', $returned['total_paisa']);
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
    }

    public function test_choice_buy_get_uses_cheapest_feasible_reward_and_preserves_buy_quota(): void
    {
        $s = $this->shop();
        $cheap = $this->postJson($s['url'].'/items', ['name' => 'Cheap kg', 'kind' => 'service', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '80.35'])->assertCreated()->json('data.id');
        $group = ['item_ids' => [$s['item'], $cheap], 'category_ids' => [], 'pos_unit' => 'kg', 'qty' => '1'];
        $values = $this->offer(['offer_kind' => 'buy_get', 'discount_mode' => 'percent', 'discount_value' => '100', 'minimum_spend' => '0', 'rules' => [[...$group, 'role' => 'buy'], [...$group, 'role' => 'get']]]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
        $cart = $this->cart($s, $offer['id'], '1.501');
        $cart['lines'][] = ['item_id' => $cheap, 'measurement' => ['mode' => 'quantity', 'value' => '1.499']];
        $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.applications', 1)->assertJsonPath('data.basket_offer.discount_paisa', '8035')->assertJsonPath('data.total_paisa', '19057');
        $updated = $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $offer['version'], 'rules' => [['item_id' => $cheap, 'role' => 'buy', 'qty' => '1'], [...$group, 'role' => 'get']]])->assertCreated()->json('data');
        $cart['lines'][0]['measurement']['value'] = '1';
        $cart['lines'][1]['measurement']['value'] = '1';
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.discount_paisa', '10025')->assertJsonPath('data.total_paisa', '8035')->json('data');
        $doc = $this->postJson($s['url'].'/pos/sales', [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']])->assertCreated()->json('data');
        $this->postJson($s['url'].'/document/'.$doc['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Choice reward cancelled'])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000]);
        $this->postJson($s['url'].'/pos/preview', [...$cart, 'lines' => [$cart['lines'][1]]])->assertUnprocessable();
        $this->assertSame(2, $updated['version']);
    }

    public function test_quantity_groups_validate_units_ownership_and_freeze_live_membership_proof(): void
    {
        $s = $this->shop();
        $category = $this->postJson($s['url'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Live kg'])->assertCreated()->json('data.id');
        DB::table('items')->where('id', $s['item'])->update(['category_id' => $category]);
        $group = ['role' => 'component', 'item_ids' => [$s['item']], 'category_ids' => [$category], 'pos_unit' => 'kg', 'qty' => '1'];
        $values = $this->offer(['offer_kind' => 'bundle', 'discount_value' => '150', 'minimum_spend' => '0', 'rules' => [$group, $group]]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
        $old = $this->postJson($s['url'].'/pos/preview', $this->cart($s, $offer['id'], '2'))->assertOk()->json('data');
        DB::table('items')->where('id', $s['item'])->update(['category_id' => null]);
        $current = $this->postJson($s['url'].'/pos/preview', $this->cart($s, $offer['id'], '2'))->assertOk()->assertJsonPath('data.total_paisa', $old['total_paisa'])->json('data');
        $this->assertNotSame($old['fingerprint'], $current['fingerprint']);
        $this->postJson($s['url'].'/pos/sales', [...$this->cart($s, $offer['id'], '2'), 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $old['total_paisa'], 'expected_fingerprint' => $old['fingerprint']])->assertConflict();
        $this->postJson($s['url'].'/basket-offers', [...$values, 'mutation_uuid' => (string) Str::uuid(), 'name' => 'Wrong base', 'rules' => [[...$group, 'pos_unit' => 'l'], $group]])->assertUnprocessable();
        $foreign = $this->shop();
        $this->actingAs($s['owner'], 'tenant');
        $this->postJson($s['url'].'/basket-offers', [...$values, 'mutation_uuid' => (string) Str::uuid(), 'name' => 'Foreign group', 'rules' => [[...$group, 'item_ids' => [$foreign['item']]], $group]])->assertNotFound();
        DB::table('items')->where('id', $s['item'])->update(['unit_label' => 'changed']);
        $this->postJson($s['url'].'/pos/preview', $this->cart($s, $offer['id'], '2'))->assertUnprocessable();
    }

    public function test_choice_groups_handle_large_repetitions_without_expanding_units(): void
    {
        $s = $this->shop();
        $service = $this->postJson($s['url'].'/items', ['name' => 'Many portions', 'kind' => 'service', 'unit_label' => 'portion', 'pos_unit' => 'unit', 'sale_price' => '1'])->assertCreated()->json('data.id');
        $group = ['item_ids' => [$service], 'category_ids' => [], 'pos_unit' => 'unit', 'qty' => '0.001'];
        $offer = $this->postJson($s['url'].'/basket-offers', $this->offer(['offer_kind' => 'buy_get', 'discount_mode' => 'percent', 'discount_value' => '100', 'minimum_spend' => '0', 'rules' => [[...$group, 'role' => 'buy'], [...$group, 'role' => 'get']]]))->assertCreated()->json('data');
        $cart = [...$this->cart($s, $offer['id']), 'lines' => [['item_id' => $service, 'measurement' => ['mode' => 'quantity', 'value' => '1000000']]]];
        $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.applications', 500000000)->assertJsonPath('data.basket_offer.discount_paisa', '50000000')->assertJsonPath('data.total_paisa', '50000000');
    }

    public function test_twenty_overlapping_groups_share_one_hundred_cart_lines(): void
    {
        $s = $this->shop();
        $category = $this->postJson($s['url'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Many choices'])->assertCreated()->json('data.id');
        $template = (array) DB::table('items')->where('id', $s['item'])->first();
        unset($template['id']);
        $template = [...$template, 'kind' => 'service', 'unit_label' => 'portion', 'pos_unit' => 'unit', 'sale_price_paisa' => 100, 'category_id' => $category];
        $lines = [];
        for ($i = 0; $i < 100; $i++) {
            $id = DB::table('items')->insertGetId([...$template, 'name' => 'Choice '.$i]);
            $lines[] = ['item_id' => $id, 'measurement' => ['mode' => 'quantity', 'value' => '1']];
        }
        $group = ['role' => 'component', 'category_ids' => [$category], 'pos_unit' => 'unit', 'qty' => '1'];
        $offer = $this->postJson($s['url'].'/basket-offers', $this->offer(['offer_kind' => 'bundle', 'discount_value' => '10', 'minimum_spend' => '0', 'rules' => array_fill(0, 20, $group)]))->assertCreated()->json('data');
        $cart = [...$this->cart($s, $offer['id']), 'lines' => $lines];
        $proof = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.applications', 5)->assertJsonPath('data.total_paisa', '5000')->json('data.basket_offer');
        $this->assertCount(100, $proof['allocations']);
        foreach ($proof['allocations'] as $line) {
            $this->assertSame('1000', $line['matched_qty_milli']);
            $this->assertSame('50', $line['discount_paisa']);
        }
        $this->assertSame(100000, array_sum(array_map(fn ($row) => (int) $row['qty_milli'], $proof['assignments'])));
    }

    private function offer(array $changes = []): array
    {
        return [...['mutation_uuid' => (string) Str::uuid(), 'name' => 'Spend and save', 'enabled' => true, 'discount_mode' => 'fixed', 'discount_value' => '30.01', 'minimum_spend' => '200', 'maximum_discount' => null, 'starts_bs' => 20830101, 'ends_bs' => 20830130, 'cashier_allowed' => true], ...$changes];
    }

    private function cart(array $s, string $offer, string $qty = '2.501'): array
    {
        return ['basket_offer_id' => $offer, 'business_date_bs' => 20830103, 'contact_id' => $s['party'], 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'quantity', 'value' => $qty]]]];
    }

    public function test_fixed_offer_posts_discount_and_returns_original_value(): void
    {
        $s = $this->shop();
        $offer = $this->postJson($s['url'].'/basket-offers', $this->offer())->assertCreated()->json('data');
        $cart = $this->cart($s, $offer['id']);
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.invoice_discount_paisa', '3001')->assertJsonPath('data.total_paisa', '22072')->json('data');
        $sale = [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']];
        $doc = $this->postJson($s['url'].'/pos/sales', $sale)->assertCreated()->assertJsonPath('data.basket_offer_snapshot.name', 'Spend and save')->json('data');
        $this->postJson($s['url'].'/pos/sales', $sale)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], $this->offer(['version' => $offer['version'], 'discount_value' => '50']))->assertCreated();
        $line = $doc['lines'][0];
        $returned = $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Whole basket returned', 'lines' => [['source_line_id' => $line['id'], 'qty' => '2.501']]])->assertCreated()->json('data');
        $this->assertSame('22072', $returned['total_paisa']);
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
        $this->assertSame(1, DB::table('documents')->where('type', 'sale')->count());
    }

    public function test_caps_thresholds_dates_versions_and_same_total_fingerprint(): void
    {
        $s = $this->shop();
        $values = $this->offer(['discount_mode' => 'percent', 'discount_value' => '12.50', 'maximum_discount' => '20.03']);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
        $cart = $this->cart($s, $offer['id']);
        $old = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.invoice_discount_paisa', '2003')->assertJsonPath('data.total_paisa', '23070')->json('data');
        $this->postJson($s['url'].'/pos/preview', $this->cart($s, $offer['id'], '1'))->assertUnprocessable();
        $this->postJson($s['url'].'/pos/preview', [...$cart, 'business_date_bs' => 20830201])->assertUnprocessable();
        $updated = $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $offer['version'], 'name' => 'Renamed same saving'])->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/sales', [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $old['total_paisa'], 'expected_fingerprint' => $old['fingerprint']])->assertConflict();
        $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $offer['version']])->assertConflict();
        $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $updated['version'], 'enabled' => false])->assertCreated();
        $this->postJson($s['url'].'/pos/preview', $cart)->assertUnprocessable();
        $this->postJson($s['url'].'/basket-offers', $this->offer(['discount_mode' => 'percent', 'discount_value' => '100.01']))->assertUnprocessable();
        $this->postJson($s['url'].'/basket-offers', $this->offer(['starts_bs' => 20830201, 'ends_bs' => 20830101]))->assertUnprocessable();
        $this->postJson($s['url'].'/basket-offers', $this->offer(['discount_mode' => 'percent', 'discount_value' => '10', 'maximum_discount' => '0']))->assertUnprocessable();
        $uncapped = $this->postJson($s['url'].'/basket-offers', $this->offer(['name' => 'Exact percentage', 'discount_mode' => 'percent', 'discount_value' => '12.50']))->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/preview', $this->cart($s, $uncapped['id']))->assertOk()->assertJsonPath('data.invoice_discount_paisa', '3134')->assertJsonPath('data.total_paisa', '21939');
        $this->postJson($s['url'].'/pos/preview', $this->cart($s, $uncapped['id'], '1.995'))->assertOk();
        $this->postJson($s['url'].'/pos/preview', $this->cart($s, $uncapped['id'], '1.994'))->assertUnprocessable();
    }

    public function test_mixed_tax_allocation_cancel_and_returns_keep_exact_source_discount(): void
    {
        $s = $this->shop();
        DB::table('tenants')->where('id', $s['tenant']['id'])->update(['tax_recording_enabled' => true]);
        DB::table('items')->where('id', $s['item'])->update(['default_tax_category' => 'standard', 'default_tax_bps' => 1300]);
        $service = $this->postJson($s['url'].'/items', ['name' => 'Exempt work', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '50.02', 'default_tax_category' => 'exempt', 'default_tax_bps' => 0])->assertCreated()->json('data.id');
        $offer = $this->postJson($s['url'].'/basket-offers', $this->offer())->assertCreated()->json('data');
        $cart = $this->cart($s, $offer['id']);
        $cart['lines'][] = ['item_id' => $service, 'measurement' => ['mode' => 'quantity', 'value' => '1']];
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.lines.0.invoice_discount_paisa', '2502')->assertJsonPath('data.lines.1.invoice_discount_paisa', '499')->assertJsonPath('data.tax_paisa', '2934')->assertJsonPath('data.total_paisa', '30008')->json('data');
        $sale = [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']];
        $doc = $this->postJson($s['url'].'/pos/sales', $sale)->assertCreated()->json('data');
        $this->postJson($s['url'].'/document/'.$doc['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Cancel test basket'])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
        $cart['business_date_bs'] = 20830104;
        $fresh = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->json('data');
        $doc = $this->postJson($s['url'].'/pos/sales', [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $fresh['total_paisa'], 'expected_fingerprint' => $fresh['fingerprint']])->assertCreated()->json('data');
        $returned = 0;
        foreach (array_reverse($doc['lines']) as $line) {
            $return = $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Return mixed basket', 'lines' => [['source_line_id' => $line['id'], 'qty' => Money::format((int) $line['qty_milli'], 3)]]])->assertCreated()->json('data');
            $returned += (int) $return['total_paisa'];
        }
        $this->assertSame(30008, $returned);
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
    }

    public function test_item_bundle_repeats_leave_unmatched_items_and_returns_unchanged(): void
    {
        $s = $this->shop();
        $work = $this->postJson($s['url'].'/items', ['name' => 'Bundle work', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '50.02'])->assertCreated()->json('data.id');
        $other = $this->postJson($s['url'].'/items', ['name' => 'Other work', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '10.01'])->assertCreated()->json('data.id');
        $values = $this->offer(['offer_kind' => 'bundle', 'discount_value' => '200', 'minimum_spend' => '0', 'maximum_applications' => null, 'rules' => [['item_id' => $s['item'], 'role' => 'component', 'qty' => '2'], ['item_id' => $work, 'role' => 'component', 'qty' => '1']]]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->assertJsonPath('data.offer_kind', 'bundle')->assertJsonPath('data.rules.0.qty_milli', '2000')->json('data');
        $cart = $this->cart($s, $offer['id'], '5.001');
        $cart['lines'][] = ['item_id' => $work, 'measurement' => ['mode' => 'quantity', 'value' => '2']];
        $cart['lines'][] = ['item_id' => $other, 'measurement' => ['mode' => 'quantity', 'value' => '1']];
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.applications', 2)->assertJsonPath('data.basket_offer.discount_paisa', '10104')->assertJsonPath('data.lines.2.line_discount_paisa', '0')->assertJsonPath('data.invoice_discount_paisa', '0')->assertJsonPath('data.total_paisa', '51036')->json('data');
        $sale = [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']];
        $doc = $this->postJson($s['url'].'/pos/sales', $sale)->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/sales', $sale)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $offer['version'], 'maximum_applications' => 1])->assertCreated();
        $capped = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.applications', 1)->assertJsonPath('data.total_paisa', '56088')->json('data');
        $this->assertNotSame($preview['fingerprint'], $capped['fingerprint']);
        $sum = 0;
        foreach ($doc['lines'] as $line) {
            $returned = $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Return original bundle', 'lines' => [['source_line_id' => $line['id'], 'qty' => Money::format((int) $line['qty_milli'], 3)]]])->assertCreated()->json('data');
            $sum += (int) $returned['total_paisa'];
        }
        $this->assertSame(51036, $sum);
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
    }

    public function test_same_item_buy_get_matches_combined_quantity_and_cheapest_slab_first(): void
    {
        $s = $this->shop();
        $list = $this->postJson($s['url'].'/price-lists', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Reward tiers', 'channel' => 'sale', 'pricing_scheme' => 'slab', 'enabled' => true, 'adjustment_mode' => 'increase', 'adjustment_percent' => '0', 'rules' => [['item_id' => $s['item'], 'min_qty' => '0', 'price' => '100.25'], ['item_id' => $s['item'], 'min_qty' => '1.5', 'price' => '80.35']]])->assertCreated()->json('data');
        $values = $this->offer(['offer_kind' => 'buy_get', 'discount_mode' => 'percent', 'discount_value' => '100', 'minimum_spend' => '0', 'rules' => [['item_id' => $s['item'], 'role' => 'buy', 'qty' => '1'], ['item_id' => $s['item'], 'role' => 'get', 'qty' => '1']]]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
        $cart = [...$this->cart($s, $offer['id']), 'price_list_id' => $list['id']];
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.applications', 1)->assertJsonPath('data.basket_offer.discount_paisa', '8035')->assertJsonPath('data.lines.0.line_discount_paisa', '0')->assertJsonPath('data.lines.1.line_discount_paisa', '8035')->assertJsonPath('data.total_paisa', '15046')->json('data');
        $this->postJson($s['url'].'/pos/preview', [...$cart, 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'quantity', 'value' => '1.999']]]])->assertUnprocessable();
        $doc = $this->postJson($s['url'].'/pos/sales', [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']])->assertCreated()->json('data');
        $this->postJson($s['url'].'/document/'.$doc['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Cancel reward sale'])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
        DB::table('items')->where('id', $s['item'])->update(['unit_label' => 'changed']);
        $this->postJson($s['url'].'/pos/preview', $cart)->assertUnprocessable();
    }

    public function test_distinct_free_reward_consumes_stock_and_zero_value_return_restores_it(): void
    {
        $s = $this->shop();
        $gift = $this->postJson($s['url'].'/items', ['name' => 'Gift', 'kind' => 'stock', 'unit_label' => 'bottle', 'sale_price' => '50.02'])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'contact_id' => $s['party'], 'lines' => [['item_id' => $gift, 'qty' => '10', 'unit_price' => '20']], 'expected_total_paisa' => '20000', 'paid_now' => '0'])->assertCreated();
        $values = $this->offer(['offer_kind' => 'buy_get', 'discount_mode' => 'percent', 'discount_value' => '100', 'minimum_spend' => '0', 'maximum_applications' => 1, 'rules' => [['item_id' => $s['item'], 'role' => 'buy', 'qty' => '2'], ['item_id' => $gift, 'role' => 'get', 'qty' => '1']]]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
        $cart = $this->cart($s, $offer['id'], '4.5');
        $this->postJson($s['url'].'/pos/preview', $cart)->assertUnprocessable();
        $cart['lines'][] = ['item_id' => $gift, 'measurement' => ['mode' => 'quantity', 'value' => '1']];
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.discount_paisa', '5002')->assertJsonPath('data.lines.1.total_paisa', '0')->assertJsonPath('data.total_paisa', '45113')->json('data');
        $doc = $this->postJson($s['url'].'/pos/sales', [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']])->assertCreated()->json('data');
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $gift, 'qty_milli' => 9000, 'value_paisa' => 18000]);
        $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Return free gift', 'lines' => [['source_line_id' => $doc['lines'][1]['id'], 'qty' => '1']]])->assertCreated()->assertJsonPath('data.total_paisa', '0');
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $gift, 'qty_milli' => 10000, 'value_paisa' => 20000]);
        $bad = $values;
        $bad['rules'][1]['role'] = 'buy';
        $this->postJson($s['url'].'/basket-offers', [...$bad, 'name' => 'Bad reward', 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($s['url'].'/basket-offers', [...$values, 'name' => 'Too much reward', 'discount_value' => '100.01', 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_reward_cap_changes_only_matched_line_and_preserves_tax_and_omitted_rules(): void
    {
        $s = $this->shop();
        DB::table('tenants')->where('id', $s['tenant']['id'])->update(['tax_recording_enabled' => true]);
        DB::table('items')->where('id', $s['item'])->update(['default_tax_category' => 'standard', 'default_tax_bps' => 1300]);
        $gift = $this->postJson($s['url'].'/items', ['name' => 'Exempt reward work', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '50.02', 'default_tax_category' => 'exempt', 'default_tax_bps' => 0])->assertCreated()->json('data.id');
        $values = $this->offer(['offer_kind' => 'buy_get', 'discount_mode' => 'percent', 'discount_value' => '50', 'minimum_spend' => '0', 'maximum_discount' => '10.03', 'maximum_applications' => 1, 'rules' => [['item_id' => $s['item'], 'role' => 'buy', 'qty' => '2'], ['item_id' => $gift, 'role' => 'get', 'qty' => '1']]]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
        $cart = $this->cart($s, $offer['id'], '2');
        $cart['lines'][] = ['item_id' => $gift, 'measurement' => ['mode' => 'quantity', 'value' => '2']];
        $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.discount_paisa', '1003')->assertJsonPath('data.basket_offer.allocations.0.matched_qty_milli', '1000')->assertJsonPath('data.lines.0.line_discount_paisa', '0')->assertJsonPath('data.lines.1.line_discount_paisa', '1003')->assertJsonPath('data.lines.1.before_offer_paisa', '10004')->assertJsonPath('data.tax_paisa', '2607')->assertJsonPath('data.total_paisa', '31658');
        unset($values['rules']);
        $updated = $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $offer['version'], 'maximum_applications' => null])->assertCreated()->assertJsonCount(2, 'data.rules')->assertJsonPath('data.maximum_applications', null)->json('data');
        $this->assertSame($offer['rules'], $updated['rules']);
        $bundle = $this->postJson($s['url'].'/basket-offers', $this->offer(['name' => 'Expensive bundle', 'offer_kind' => 'bundle', 'discount_value' => '1000', 'minimum_spend' => '0', 'rules' => [['item_id' => $s['item'], 'role' => 'component', 'qty' => '1'], ['item_id' => $gift, 'role' => 'component', 'qty' => '1']]]))->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/preview', [...$cart, 'basket_offer_id' => $bundle['id']])->assertUnprocessable();
    }

    public function test_selected_categories_and_items_union_discounts_only_matching_rows_and_freezes_returns(): void
    {
        $s = $this->shop();
        $category = $this->postJson($s['url'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Selected goods'])->assertCreated()->json('data.id');
        DB::table('items')->where('id', $s['item'])->update(['category_id' => $category, 'default_tax_category' => 'standard', 'default_tax_bps' => 1300]);
        DB::table('tenants')->where('id', $s['tenant']['id'])->update(['tax_recording_enabled' => true]);
        $work = $this->postJson($s['url'].'/items', ['name' => 'Unmatched work', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '50.02', 'default_tax_category' => 'exempt', 'default_tax_bps' => 0])->assertCreated()->json('data.id');
        $values = $this->offer(['offer_kind' => 'items', 'discount_mode' => 'percent', 'discount_value' => '50', 'minimum_spend' => '0', 'rules' => [['role' => 'target', 'item_ids' => [$s['item']], 'category_ids' => [$category]]]]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->assertJsonPath('data.rules.0.item_ids.0', $s['item'])->json('data');
        $cart = $this->cart($s, $offer['id']);
        $cart['lines'][] = ['item_id' => $work, 'measurement' => ['mode' => 'quantity', 'value' => '1']];
        $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.basket_offer.discount_paisa', '12537')->assertJsonPath('data.lines.0.line_discount_paisa', '12537')->assertJsonPath('data.lines.1.line_discount_paisa', '0')->assertJsonPath('data.tax_paisa', '1630')->assertJsonPath('data.total_paisa', '19168')->json('data');
        $doc = $this->postJson($s['url'].'/pos/sales', [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']])->assertCreated()->assertJsonPath('data.basket_offer_snapshot.matched_items.0.item_id', $s['item'])->json('data');
        $next = $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $offer['version'], 'discount_value' => '100'])->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.total_paisa', '5002');
        $returned = $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Original selected goods return', 'lines' => [['source_line_id' => $doc['lines'][0]['id'], 'qty' => '2.501']]])->assertCreated()->json('data');
        $this->assertSame('14166', $returned['total_paisa']);
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
        $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $next['version'], 'discount_mode' => 'fixed', 'discount_value' => '300'])->assertCreated();
        $this->postJson($s['url'].'/pos/preview', $cart)->assertUnprocessable();
    }

    public function test_live_category_membership_scope_and_empty_targets_are_reviewed(): void
    {
        $s = $this->shop();
        $category = $this->postJson($s['url'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Live category'])->assertCreated()->json('data.id');
        DB::table('items')->where('id', $s['item'])->update(['category_id' => $category]);
        $values = $this->offer(['offer_kind' => 'items', 'discount_mode' => 'percent', 'discount_value' => '10', 'minimum_spend' => '0', 'rules' => [['role' => 'target', 'category_ids' => [$category]]]]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
        $cart = $this->cart($s, $offer['id']);
        $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.total_paisa', '22566');
        DB::table('items')->where('id', $s['item'])->update(['category_id' => null]);
        $this->postJson($s['url'].'/pos/preview', $cart)->assertUnprocessable();
        $this->postJson($s['url'].'/basket-offers', [...$values, 'name' => 'Empty selection', 'mutation_uuid' => (string) Str::uuid(), 'rules' => [['role' => 'target', 'item_ids' => [], 'category_ids' => []]]])->assertUnprocessable();
        $other = $this->postJson('/api/businesses', ['name' => 'Other category business'])->assertCreated()->json('data');
        $foreign = $this->postJson('/api/app/'.$other['slug'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Foreign category'])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/basket-offers', [...$values, 'name' => 'Foreign selected category', 'mutation_uuid' => (string) Str::uuid(), 'rules' => [['role' => 'target', 'category_ids' => [$foreign]]]])->assertNotFound();
        DB::table('item_categories')->where('id', $category)->update(['archived_at' => now()]);
        $this->postJson($s['url'].'/pos/preview', $cart)->assertUnprocessable();
    }

    public function test_nepal_time_window_rechecks_at_posting_and_refuses_backdates(): void
    {
        $s = $this->shop();
        try {
            $this->travelTo(Carbon::parse('2026-04-16 17:00:00', 'Asia/Kathmandu'));
            $date = NepaliDate::fromAd('2026-04-16');
            $values = $this->offer(['starts_time' => '17:00', 'ends_time' => '19:00', 'weekdays' => [4]]);
            $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->assertJsonPath('data.starts_minute', 1020)->assertJsonPath('data.available_now', true)->json('data');
            $cart = [...$this->cart($s, $offer['id']), 'business_date_bs' => $date];
            $preview = $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->json('data');
            $this->travelTo(Carbon::parse('2026-04-16 18:59:59', 'Asia/Kathmandu'));
            $this->postJson($s['url'].'/pos/preview', $cart)->assertOk()->assertJsonPath('data.fingerprint', $preview['fingerprint']);
            $this->postJson($s['url'].'/pos/preview', [...$cart, 'business_date_bs' => 20830102])->assertUnprocessable();
            $sale = [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']];
            $doc = $this->postJson($s['url'].'/pos/sales', $sale)->assertCreated()->json('data');
            $this->travelTo(Carbon::parse('2026-04-16 19:00:00', 'Asia/Kathmandu'));
            $this->getJson($s['url'].'/basket-offers/'.$offer['id'])->assertOk()->assertJsonPath('data.available_now', false);
            $this->postJson($s['url'].'/pos/sales', [...$cart, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '0', 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']])->assertUnprocessable();
            $this->postJson($s['url'].'/pos/sales', $sale)->assertOk()->assertJsonPath('data.id', $doc['id']);
            $this->assertDatabaseCount('documents', 2);
            $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 7499]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_overnight_schedule_uses_start_weekday_and_can_be_cleared(): void
    {
        $s = $this->shop();
        try {
            $values = $this->offer(['starts_time' => '22:00', 'ends_time' => '02:00', 'weekdays' => [4]]);
            $this->travelTo(Carbon::parse('2026-04-16 22:00:00', 'Asia/Kathmandu'));
            $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
            $cart = $this->cart($s, $offer['id']);
            $this->postJson($s['url'].'/pos/preview', [...$cart, 'business_date_bs' => NepaliDate::fromAd('2026-04-16')])->assertOk();
            $this->travelTo(Carbon::parse('2026-04-17 01:59:59', 'Asia/Kathmandu'));
            $cart['business_date_bs'] = NepaliDate::fromAd('2026-04-17');
            $this->postJson($s['url'].'/pos/preview', $cart)->assertOk();
            $this->travelTo(Carbon::parse('2026-04-17 02:00:00', 'Asia/Kathmandu'));
            $this->postJson($s['url'].'/pos/preview', $cart)->assertUnprocessable();
            $this->travelTo(Carbon::parse('2026-04-18 01:00:00', 'Asia/Kathmandu'));
            $this->postJson($s['url'].'/pos/preview', [...$cart, 'business_date_bs' => NepaliDate::fromAd('2026-04-18')])->assertUnprocessable();
            $this->patchJson($s['url'].'/basket-offers/'.$offer['id'], [...$values, 'mutation_uuid' => (string) Str::uuid(), 'version' => $offer['version'], 'starts_time' => null, 'ends_time' => null, 'weekdays' => []])->assertCreated()->assertJsonPath('data.starts_minute', null)->assertJsonPath('data.ends_minute', null);
            $this->postJson($s['url'].'/pos/preview', $cart)->assertOk();
            $this->postJson($s['url'].'/basket-offers', [...$values, 'mutation_uuid' => (string) Str::uuid(), 'name' => 'Equal clock', 'starts_time' => '02:00'])->assertUnprocessable();
            $this->postJson($s['url'].'/basket-offers', [...$values, 'mutation_uuid' => (string) Str::uuid(), 'name' => 'Missing clock', 'ends_time' => null])->assertUnprocessable();
        } finally {
            $this->travelBack();
        }
    }

    public function test_tenant_cashier_and_uuid_payload_guards(): void
    {
        $s = $this->shop();
        $values = $this->offer(['cashier_allowed' => false]);
        $offer = $this->postJson($s['url'].'/basket-offers', $values)->assertCreated()->json('data');
        $this->postJson($s['url'].'/basket-offers', $values)->assertOk()->assertJsonPath('data.id', $offer['id']);
        $this->postJson($s['url'].'/basket-offers', [...$values, 'name' => 'Changed retry'])->assertConflict();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->getJson($s['url'].'/basket-offers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($s['url'].'/basket-offers', $this->offer())->assertForbidden();
        $this->postJson($s['url'].'/pos/preview', $this->cart($s, $offer['id']))->assertForbidden();
        $this->actingAs($s['owner'], 'tenant');
        $other = $this->postJson('/api/businesses', ['name' => 'Other basket shop'])->assertCreated()->json('data');
        $this->getJson('/api/app/'.$other['slug'].'/basket-offers/'.$offer['id'])->assertNotFound();
        $otherItem = $this->postJson('/api/app/'.$other['slug'].'/items', ['name' => 'Own other item', 'kind' => 'service', 'unit_label' => 'unit', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/basket-offers', $this->offer(['name' => 'Foreign bundle rule', 'offer_kind' => 'bundle', 'rules' => [['item_id' => $s['item'], 'role' => 'component', 'qty' => '1'], ['item_id' => $otherItem, 'role' => 'component', 'qty' => '1']]]))->assertNotFound();
        $this->postJson('/api/app/'.$other['slug'].'/pos/preview', ['basket_offer_id' => $offer['id'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $otherItem, 'measurement' => ['mode' => 'quantity', 'value' => '3']]]])->assertNotFound();
    }
}
