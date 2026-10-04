<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndustryPosTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'POS shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$business['slug'];
        $cash = $this->getJson($url.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();

        return compact('owner', 'business', 'url', 'cash');
    }

    private function item(array $s, string $unit = 'unit'): string
    {
        return $this->postJson($s['url'].'/items', ['name' => 'POS '.$unit, 'kind' => 'service', 'unit_label' => $unit, 'pos_unit' => $unit, 'sale_price' => '100', 'pos_methods' => ['quantity', 'amount', 'pack', 'length', 'area', 'volume'], 'pos_custom_units' => [['label' => 'Box', 'qty' => '12']], 'service_minutes' => 30])->assertCreated()->json('data.id');
    }

    private function offer(array $s, array $changes = []): array
    {
        return $this->postJson($s['url'].'/basket-offers', [...['mutation_uuid' => (string) Str::uuid(), 'name' => 'Checkout saving', 'enabled' => true, 'cashier_allowed' => true, 'offer_kind' => 'basket', 'discount_mode' => 'fixed', 'discount_value' => '10', 'minimum_spend' => '0'], ...$changes])->assertCreated()->json('data');
    }

    public function test_restaurant_offer_preview_keeps_ticket_price_tax_stock_and_original_return(): void
    {
        $s = $this->shop();
        DB::table('tenants')->where('id', $s['business']['id'])->update(['tax_recording_enabled' => true]);
        $supplier = $this->postJson($s['url'].'/contacts', ['name' => 'Kitchen supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($s['url'].'/items', ['name' => 'Food stock', 'kind' => 'stock', 'unit_label' => 'portion', 'pos_unit' => 'unit', 'sale_price' => '100', 'default_tax_category' => 'standard', 'default_tax_bps' => 1300])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'contact_id' => $supplier, 'lines' => [['item_id' => $item, 'qty' => '10', 'unit_price' => '50', 'tax_category' => 'outside_scope', 'tax_bps' => 0]], 'expected_total_paisa' => '50000', 'paid_now' => '0'])->assertCreated();
        $offer = $this->offer($s, ['offer_kind' => 'buy_get', 'discount_mode' => 'percent', 'discount_value' => '100', 'rules' => [['item_id' => $item, 'role' => 'buy', 'qty' => '1'], ['item_id' => $item, 'role' => 'get', 'qty' => '1']]]);
        $order = $this->postJson($s['url'].'/restaurant/orders', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'takeaway'])->assertCreated()->json('data');
        $path = $s['url'].'/restaurant/orders/'.$order['id'];
        $order = $this->postJson($path.'/send', ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'lines' => [['item_id' => $item, 'qty' => '2.501', 'note' => 'Saved kitchen price']]])->assertCreated()->json('data');
        $review = ['version' => $order['version'], 'business_date_bs' => 20830102, 'basket_offer_id' => $offer['id']];
        $this->postJson($path.'/checkout/preview', $review)->assertUnprocessable();
        $order = $this->postJson($path.'/send', ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'lines' => [['item_id' => $item, 'qty' => '1', 'note' => 'Cancelled round']]])->assertCreated()->json('data');
        $order = $this->postJson($path.'/tickets/'.$order['tickets'][1]['id'], ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'status' => 'cancelled', 'reason' => 'Wrong kitchen round'])->assertCreated()->json('data');
        foreach (['preparing', 'ready', 'served'] as $status) {
            $order = $this->postJson($path.'/tickets/'.$order['tickets'][0]['id'], ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'status' => $status])->assertCreated()->json('data');
        }
        DB::table('items')->where('id', $item)->update(['sale_price_paisa' => 50000]);
        $review['version'] = $order['version'];
        $proof = $this->postJson($path.'/checkout/preview', $review)->assertOk()->assertJsonPath('data.total_paisa', '16961')->assertJsonPath('data.tax_paisa', '1951')->assertJsonPath('data.lines.0.before_offer_paisa', '25010')->assertJsonPath('data.basket_offer.discount_paisa', '10000')->json('data');
        $checkout = [...$review, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '169.61', 'money_account_id' => $s['cash'], 'expected_total_paisa' => $proof['total_paisa']];
        $this->postJson($path.'/checkout', $checkout)->assertUnprocessable();
        $checkout['expected_fingerprint'] = $proof['fingerprint'];
        $doc = $this->postJson($path.'/checkout', $checkout)->assertCreated()->assertJsonPath('data.basket_offer_snapshot.discount_paisa', '10000')->assertJsonPath('data.lines.0.unit_price_paisa', '10000')->json('data');
        DB::table('basket_offers')->where('id', $offer['id'])->update(['enabled' => false]);
        $this->postJson($path.'/checkout', $checkout)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->assertDatabaseHas('restaurant_orders', ['id' => $order['id'], 'status' => 'closed', 'document_id' => $doc['id']]);
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $item, 'qty_milli' => 7499]);
        $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Original food discount returned', 'lines' => [['source_line_id' => $doc['lines'][0]['id'], 'qty' => '2.501']]])->assertCreated()->assertJsonPath('data.total_paisa', '16961');
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $item, 'qty_milli' => 10000, 'value_paisa' => 50000]);
    }

    public function test_appointment_offer_requires_fresh_proof_even_when_total_unchanged(): void
    {
        $s = $this->shop();
        $item = $this->item($s);
        $staff = $this->postJson($s['url'].'/pos/resources', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'staff', 'name' => 'Review chair', 'start_minute' => 540, 'end_minute' => 1200])->assertCreated()->json('data.id');
        $booking = $this->postJson($s['url'].'/appointments', ['mutation_uuid' => (string) Str::uuid(), 'resource_id' => $staff, 'business_date_bs' => 20830102, 'start_minute' => 600, 'client_name' => 'Client', 'item_ids' => [$item], 'status' => 'booked'])->assertCreated()->json('data');
        $path = $s['url'].'/appointments/'.$booking['id'];
        $offer = $this->offer($s);
        $review = ['version' => $booking['version'], 'business_date_bs' => 20830102, 'basket_offer_id' => $offer['id']];
        $this->postJson($path.'/checkout/preview', $review)->assertUnprocessable();
        $booking = $this->postJson($path, ['mutation_uuid' => (string) Str::uuid(), 'version' => $booking['version'], 'status' => 'arrived'])->assertCreated()->json('data');
        $review['version'] = $booking['version'];
        DB::table('items')->where('id', $item)->update(['sale_price_paisa' => 50000]);
        $old = $this->postJson($path.'/checkout/preview', $review)->assertOk()->assertJsonPath('data.total_paisa', '9000')->json('data');
        $checkout = [...$review, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '90', 'money_account_id' => $s['cash'], 'expected_total_paisa' => '9000', 'expected_fingerprint' => $old['fingerprint']];
        DB::table('basket_offers')->where('id', $offer['id'])->update(['name' => 'Same price reviewed', 'version' => 2]);
        $this->postJson($path.'/checkout', $checkout)->assertConflict();
        $this->assertSame(0, DB::table('documents')->count());
        $new = $this->postJson($path.'/checkout/preview', $review)->assertOk()->assertJsonPath('data.total_paisa', '9000')->json('data');
        $this->assertNotSame($old['fingerprint'], $new['fingerprint']);
        $this->postJson($path.'/checkout/preview', [...$review, 'business_date_bs' => 20830103])->assertUnprocessable();
        $checkout['expected_fingerprint'] = $new['fingerprint'];
        $doc = $this->postJson($path.'/checkout', $checkout)->assertCreated()->assertJsonPath('data.basket_offer_snapshot.name', 'Same price reviewed')->json('data');
        DB::table('basket_offers')->where('id', $offer['id'])->update(['enabled' => false]);
        $this->postJson($path.'/checkout', $checkout)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->postJson($s['url'].'/document/'.$doc['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Cancel reviewed service bill'])->assertUnprocessable();
        $this->postJson($s['url'].'/payments/'.$doc['payments'][0]['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Cancel service payment first'])->assertCreated();
        $this->postJson($s['url'].'/document/'.$doc['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Cancel reviewed service bill'])->assertCreated();
        $this->assertDatabaseHas('appointments', ['id' => $booking['id'], 'status' => 'completed', 'document_id' => $doc['id']]);
    }

    public function test_checkout_preview_rejects_foreign_and_staff_restricted_offers_and_stale_source(): void
    {
        $s = $this->shop();
        $item = $this->item($s);
        $staff = $this->postJson($s['url'].'/pos/resources', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'staff', 'name' => 'Own chair', 'start_minute' => 540, 'end_minute' => 1200])->assertCreated()->json('data.id');
        $booking = $this->postJson($s['url'].'/appointments', ['mutation_uuid' => (string) Str::uuid(), 'resource_id' => $staff, 'business_date_bs' => 20830102, 'start_minute' => 600, 'client_name' => 'Client', 'item_ids' => [$item], 'status' => 'booked'])->assertCreated()->json('data');
        $path = $s['url'].'/appointments/'.$booking['id'];
        $booking = $this->postJson($path, ['mutation_uuid' => (string) Str::uuid(), 'version' => $booking['version'], 'status' => 'arrived'])->assertCreated()->json('data');
        $restricted = $this->offer($s, ['cashier_allowed' => false]);
        $foreign = $this->shop();
        $foreignOffer = $this->offer($foreign);
        $this->actingAs($s['owner'], 'tenant');
        $review = ['version' => $booking['version'], 'business_date_bs' => 20830102];
        $this->postJson($path.'/checkout/preview', [...$review, 'basket_offer_id' => $foreignOffer['id']])->assertNotFound();
        $this->postJson($foreign['url'].'/appointments/'.$booking['id'].'/checkout/preview', $review)->assertNotFound();
        $this->postJson($path.'/checkout/preview', [...$review, 'version' => 1])->assertConflict();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->postJson($path.'/checkout/preview', [...$review, 'basket_offer_id' => $restricted['id']])->assertForbidden();
        $this->postJson($path.'/checkout/preview', $review)->assertOk()->assertJsonPath('data.total_paisa', '10000');
    }

    private function bill(array $s, string $id, array $m, string $total, string $paid): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'lines' => [['item_id' => $id, 'measurement' => $m]], 'expected_total_paisa' => $total, 'paid_now' => $paid, 'money_account_id' => $s['cash']];
    }

    public function test_exact_measurement_retry_and_custom_pack(): void
    {
        $s = $this->shop();
        $id = $this->item($s, 'sq_ft');
        $input = $this->bill($s, $id, ['mode' => 'area', 'length' => '2', 'length_unit' => 'ft', 'width' => '36', 'width_unit' => 'in', 'pieces' => '1'], '60000', '600');
        $doc = $this->postJson($s['url'].'/pos/sales', $input)->assertCreated()->assertJsonPath('data.lines.0.qty_milli', '6000')->json('data');
        $this->assertSame('area', $doc['lines'][0]['measurement_snapshot']['mode']);
        $this->postJson($s['url'].'/pos/sales', $input)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->postJson($s['url'].'/pos/sales', [...$input, 'paid_now' => '0'])->assertConflict();
        $this->assertSame(1, DB::table('documents')->count());
        DB::table('items')->where('id', $id)->update(['sale_price_paisa' => 99999]);
        $returned = $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Partial return QA', 'lines' => [['source_line_id' => $doc['lines'][0]['id'], 'qty' => '3']], 'expected_total_paisa' => '30000'])->assertCreated()->json('data');
        $this->assertSame('10000', $returned['lines'][0]['unit_price_paisa']);
        $this->assertSame('area', $returned['lines'][0]['measurement_snapshot']['source_measurement']['mode']);
        $wood = $this->item($s, 'board_ft');
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $wood, 'measurement' => ['mode' => 'volume', 'length' => '12', 'length_unit' => 'in', 'width' => '12', 'width_unit' => 'in', 'thickness' => '1', 'thickness_unit' => 'in', 'pieces' => '1']]]])->assertOk()->assertJsonPath('data.lines.0.qty_milli', '1000');
        $pack = $this->item($s);
        $this->postJson($s['url'].'/pos/sales', $this->bill($s, $pack, ['mode' => 'pack', 'custom_index' => 0, 'value' => '1'], '120000', '1200'))->assertCreated();
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => ['mode' => 'volume', 'length' => '1', 'width' => '1', 'thickness' => '1', 'length_unit' => 'ft', 'width_unit' => 'ft', 'thickness_unit' => 'ft', 'pieces' => '1']]]])->assertUnprocessable();
    }

    public function test_extended_units_convert_once_with_exact_quantity_rounding(): void
    {
        $s = $this->shop();
        $cases = [
            ['pair', 'dozen', '1', '6000'], ['unit', 'pair', '1.5', '3000'],
            ['kg', 'lb', '2.205', '1000'], ['lb', 'oz', '16', '1000'],
            ['mg', 'g', '1.001', '1001000'], ['tonne', 'kg', '1000', '1000'],
            ['l', 'cl', '25', '250'], ['l', 'us_gal', '1', '3785'], ['l', 'imp_gal', '1', '4546'],
            ['ft', 'yd', '1', '3000'], ['sq_ft', 'sq_in', '144', '1000'],
            ['sq_cm', 'sq_mm', '100', '1000'], ['sq_m', 'sq_yd', '1', '836'],
            ['cu_ft', 'cu_yd', '1', '27000'], ['cu_in', 'cu_cm', '16.387', '1000'],
            ['board_ft', 'cu_in', '144', '1000'], ['cu_mm', 'cu_cm', '1', '1000000'],
        ];
        $config = $this->getJson($s['url'].'/pos/config')->assertOk()->json('data.units');
        foreach ($cases as [$base, $unit, $value, $expected]) {
            $this->assertArrayHasKey($base, $config);
            $this->assertArrayHasKey($unit, $config);
            $id = $this->item($s, $base);
            $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => ['mode' => 'quantity', 'value' => $value, 'unit' => $unit]]]])->assertOk()->assertJsonPath('data.lines.0.qty_milli', $expected);
        }
        $id = $this->item($s, 'sq_ft');
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => ['mode' => 'area', 'length' => '1', 'width' => '1', 'length_unit' => 'yd', 'width_unit' => 'yd', 'pieces' => '1']]]])->assertOk()->assertJsonPath('data.lines.0.qty_milli', '9000');
        foreach ([['mode' => 'quantity', 'value' => '1', 'unit' => 'us_gal'], ['mode' => 'area', 'length' => '1', 'width' => '1', 'length_unit' => 'sq_in', 'width_unit' => 'yd']] as $measurement) {
            $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => $measurement]]])->assertUnprocessable();
        }
        $tiny = $this->item($s, 'cu_m');
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $tiny, 'measurement' => ['mode' => 'quantity', 'value' => '1', 'unit' => 'cu_mm']]]])->assertUnprocessable();
    }

    public function test_extended_glass_measurement_preserves_stock_return_and_retry(): void
    {
        $s = $this->shop();
        $supplier = $this->postJson($s['url'].'/contacts', ['name' => 'Glass supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $id = $this->postJson($s['url'].'/items', ['name' => 'Glass by square centimetre', 'kind' => 'stock', 'unit_label' => 'cm²', 'pos_unit' => 'sq_cm', 'sale_price' => '10'])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'contact_id' => $supplier, 'lines' => [['item_id' => $id, 'qty' => '1000', 'unit_price' => '2']], 'expected_total_paisa' => '200000', 'paid_now' => '0'])->assertCreated();
        $input = $this->bill($s, $id, ['mode' => 'area', 'length' => '32.5', 'length_unit' => 'cm', 'width' => '200', 'width_unit' => 'mm', 'pieces' => '1'], '650000', '6500');
        $doc = $this->postJson($s['url'].'/pos/sales', $input)->assertCreated()->assertJsonPath('data.lines.0.qty_milli', '650000')->json('data');
        $this->postJson($s['url'].'/pos/sales', $input)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->getJson($s['url'].'/items/'.$id)->assertOk()->assertJsonPath('data.qty_milli', '350000')->assertJsonPath('data.value_paisa', '70000');
        $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Full glass return', 'lines' => [['source_line_id' => $doc['lines'][0]['id'], 'qty' => '650']], 'expected_total_paisa' => '650000'])->assertCreated()->assertJsonPath('data.lines.0.measurement_snapshot.source_measurement.base_unit', 'sq_cm');
        $this->getJson($s['url'].'/items/'.$id)->assertOk()->assertJsonPath('data.qty_milli', '1000000')->assertJsonPath('data.value_paisa', '200000');
    }

    public function test_branches_enforce_membership_and_separate_books(): void
    {
        $s = $this->shop();
        $id = $this->item($s);
        $input = ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Second branch', 'pos_profile' => 'milk'];
        $branch = $this->postJson($s['url'].'/pos/branches', $input)->assertCreated()->json('data');
        $this->postJson($s['url'].'/pos/branches', $input)->assertOk()->assertJsonPath('data.id', $branch['id']);
        $this->getJson('/api/app/'.$branch['slug'].'/items/'.$id)->assertNotFound();
        $this->postJson('/api/app/'.$branch['slug'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => ['mode' => 'quantity', 'value' => '1']]]])->assertNotFound();
        $this->getJson('/api/businesses')->assertOk()->assertJsonFragment(['parent_tenant_id' => $s['business']['id']]);
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->getJson('/api/app/'.$branch['slug'].'/pos/config')->assertNotFound();
        $this->postJson($s['url'].'/pos/branches', [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertForbidden();
        $sale = $this->bill($s, $id, ['mode' => 'quantity', 'value' => '1'], '10000', '100');
        $this->postJson($s['url'].'/pos/sales', $sale)->assertCreated();
        $this->postJson($s['url'].'/pos/sales', $sale)->assertOk();
    }

    public function test_kitchen_versions_and_checkout_once(): void
    {
        $s = $this->shop();
        $item = $this->item($s);
        $table = $this->postJson($s['url'].'/pos/resources', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'table', 'name' => 'Table 1', 'start_minute' => 0, 'end_minute' => 1440])->assertCreated()->json('data.id');
        $order = $this->postJson($s['url'].'/restaurant/orders', ['mutation_uuid' => (string) Str::uuid(), 'resource_id' => $table, 'kind' => 'dine_in'])->assertCreated()->json('data');
        $input = ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'lines' => [['item_id' => $item, 'qty' => '1', 'note' => 'No chilli']]];
        $order = $this->postJson($s['url'].'/restaurant/orders/'.$order['id'].'/send', $input)->assertCreated()->json('data');
        $this->postJson($s['url'].'/restaurant/orders/'.$order['id'].'/send', $input)->assertOk();
        $this->assertSame(0, DB::table('documents')->count());
        $this->postJson($s['url'].'/restaurant/orders/'.$order['id'].'/send', [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $ticket = $order['tickets'][0];
        foreach (['preparing', 'ready', 'served'] as $status) {
            $order = $this->postJson($s['url'].'/restaurant/orders/'.$order['id'].'/tickets/'.$ticket['id'], ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'status' => $status])->assertCreated()->json('data');
        }
        $checkout = ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'business_date_bs' => 20830102, 'expected_total_paisa' => '10000', 'paid_now' => '100', 'money_account_id' => $s['cash']];
        $this->postJson($s['url'].'/restaurant/orders/'.$order['id'].'/checkout', $checkout)->assertCreated()->assertJsonPath('data.total_paisa', '10000');
        $this->postJson($s['url'].'/restaurant/orders/'.$order['id'].'/checkout', $checkout)->assertOk();
        $this->postJson($s['url'].'/restaurant/orders/'.$order['id'].'/checkout', [...$checkout, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(1, DB::table('documents')->count());
    }

    public function test_schedule_overlap_adjacent_cancel_and_hours(): void
    {
        $s = $this->shop();
        $item = $this->item($s);
        $staff = $this->postJson($s['url'].'/pos/resources', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'staff', 'name' => 'Chair 1', 'start_minute' => 540, 'end_minute' => 1200])->assertCreated()->json('data.id');
        $input = ['mutation_uuid' => (string) Str::uuid(), 'resource_id' => $staff, 'business_date_bs' => 20830102, 'start_minute' => 600, 'client_name' => 'Ram', 'item_ids' => [$item], 'status' => 'booked'];
        $booking = $this->postJson($s['url'].'/appointments', $input)->assertCreated()->assertJsonPath('data.end_minute', 630)->json('data');
        $this->postJson($s['url'].'/appointments', $input)->assertOk();
        $this->postJson($s['url'].'/appointments', [...$input, 'mutation_uuid' => (string) Str::uuid(), 'start_minute' => 615])->assertUnprocessable();
        $this->postJson($s['url'].'/appointments', [...$input, 'mutation_uuid' => (string) Str::uuid(), 'start_minute' => 630])->assertCreated();
        $this->postJson($s['url'].'/appointments/'.$booking['id'], ['mutation_uuid' => (string) Str::uuid(), 'version' => $booking['version'], 'status' => 'cancelled'])->assertCreated();
        $this->postJson($s['url'].'/appointments', [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->postJson($s['url'].'/appointments', [...$input, 'mutation_uuid' => (string) Str::uuid(), 'start_minute' => 1200])->assertUnprocessable();
    }

    public function test_restaurant_rounding_matches_aggregated_bill_across_rounds(): void
    {
        $s = $this->shop();
        $item = $this->item($s);
        DB::table('items')->where('id', $item)->update(['sale_price_paisa' => 5]);
        $order = $this->postJson($s['url'].'/restaurant/orders', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'takeaway'])->assertCreated()->json('data');
        for ($i = 0; $i < 2; $i++) {
            $order = $this->postJson($s['url'].'/restaurant/orders/'.$order['id'].'/send', ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'lines' => [['item_id' => $item, 'qty' => '0.1']]])->assertCreated()->json('data');
        }
        $this->assertSame('1', $order['total_paisa']);
    }

    public function test_appointment_checkout_keeps_booked_price_and_posts_once(): void
    {
        $s = $this->shop();
        $item = $this->item($s);
        $staff = $this->postJson($s['url'].'/pos/resources', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'staff', 'name' => 'Barber', 'start_minute' => 540, 'end_minute' => 1200])->assertCreated()->json('data.id');
        $booking = $this->postJson($s['url'].'/appointments', ['mutation_uuid' => (string) Str::uuid(), 'resource_id' => $staff, 'business_date_bs' => 20830102, 'start_minute' => 600, 'client_name' => 'Client', 'item_ids' => [$item], 'status' => 'booked'])->assertCreated()->json('data');
        $path = $s['url'].'/appointments/'.$booking['id'];
        $checkout = ['mutation_uuid' => (string) Str::uuid(), 'version' => $booking['version'], 'business_date_bs' => 20830102, 'expected_total_paisa' => '10000', 'paid_now' => '100', 'money_account_id' => $s['cash']];
        $this->postJson($path.'/checkout', $checkout)->assertUnprocessable();
        $booking = $this->postJson($path, ['mutation_uuid' => (string) Str::uuid(), 'version' => $booking['version'], 'status' => 'arrived'])->assertCreated()->json('data');
        DB::table('items')->where('id', $item)->update(['sale_price_paisa' => 50000]);
        $checkout['version'] = $booking['version'];
        $doc = $this->postJson($path.'/checkout', $checkout)->assertCreated()->assertJsonPath('data.total_paisa', '10000')->json('data');
        $this->postJson($path.'/checkout', $checkout)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->postJson($path.'/checkout', [...$checkout, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame('completed', DB::table('appointments')->value('status'));
    }

    public function test_measurement_limits_invalid_units_disabled_methods_and_untrusted_fields(): void
    {
        $s = $this->shop();
        $item = $this->item($s, 'kg');
        $path = $s['url'].'/pos/preview';
        $input = ['lines' => [['item_id' => $item, 'measurement' => ['mode' => 'quantity', 'value' => '500', 'unit' => 'g']]]];
        $this->postJson($path, $input)->assertOk()->assertJsonPath('data.lines.0.qty_milli', '500');
        foreach ([['mode' => 'quantity', 'value' => '-1'], ['mode' => 'quantity', 'value' => '1', 'unit' => 'ft'], ['mode' => 'quantity'], ['mode' => 'pack', 'custom_index' => 40, 'value' => '1'], ['mode' => 'quantity', 'value' => '1', 'qty_milli' => '999']] as $measurement) {
            $this->postJson($path, ['lines' => [['item_id' => $item, 'measurement' => $measurement]]])->assertUnprocessable();
        }
        DB::table('items')->where('id', $item)->update(['pos_methods' => json_encode(['quantity'])]);
        $this->postJson($path, ['lines' => [['item_id' => $item, 'measurement' => ['mode' => 'amount', 'value' => '100']]]])->assertUnprocessable();
        $this->postJson($s['url'].'/pos/sales', $this->bill($s, $item, ['mode' => 'quantity', 'value' => '1'], '1', '0'))->assertConflict();
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'document')->count());
    }

    public function test_weighted_stock_sale_return_and_base_unit_freeze(): void
    {
        $s = $this->shop();
        $supplier = $this->postJson($s['url'].'/contacts', ['name' => 'Meat supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($s['url'].'/items', ['name' => 'Weighted stock', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'contact_id' => $supplier, 'lines' => [['item_id' => $item, 'qty' => '10', 'unit_price' => '50']], 'expected_total_paisa' => '50000', 'paid_now' => '0'])->assertCreated();
        $sale = $this->postJson($s['url'].'/pos/sales', $this->bill($s, $item, ['mode' => 'amount', 'value' => '250'], '25000', '250'))->assertCreated()->assertJsonPath('data.lines.0.qty_milli', '2500')->json('data');
        $this->assertSame(7500, (int) DB::table('inventory_balances')->where('item_id', $item)->value('qty_milli'));
        $this->assertSame(37500, (int) DB::table('inventory_balances')->where('item_id', $item)->value('value_paisa'));
        $this->patchJson($s['url'].'/items/'.$item, ['name' => 'Weighted stock', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'g', 'sale_price' => '100'])->assertUnprocessable();
        $this->postJson($s['url'].'/document/'.$sale['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Partial meat return', 'lines' => [['source_line_id' => $sale['lines'][0]['id'], 'qty' => '1.25']], 'expected_total_paisa' => '12500'])->assertCreated();
        $this->assertSame(8750, (int) DB::table('inventory_balances')->where('item_id', $item)->value('qty_milli'));
        $this->assertSame(43750, (int) DB::table('inventory_balances')->where('item_id', $item)->value('value_paisa'));
    }

    public function test_cancel_cannot_move_booking_to_foreign_resource(): void
    {
        $s = $this->shop();
        $item = $this->item($s);
        $staff = $this->postJson($s['url'].'/pos/resources', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'staff', 'name' => 'Own chair', 'start_minute' => 540, 'end_minute' => 1200])->assertCreated()->json('data.id');
        $booking = $this->postJson($s['url'].'/appointments', ['mutation_uuid' => (string) Str::uuid(), 'resource_id' => $staff, 'business_date_bs' => 20830102, 'start_minute' => 600, 'client_name' => 'Client', 'item_ids' => [$item], 'status' => 'booked'])->assertCreated()->json('data');
        $other = $this->postJson('/api/businesses', ['name' => 'Other branch'])->assertCreated()->json('data');
        $foreign = $this->postJson('/api/app/'.$other['slug'].'/pos/resources', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'staff', 'name' => 'Foreign chair', 'start_minute' => 540, 'end_minute' => 1200])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/appointments/'.$booking['id'], ['mutation_uuid' => (string) Str::uuid(), 'version' => $booking['version'], 'status' => 'cancelled', 'resource_id' => $foreign])->assertNotFound();
        $this->assertSame('booked', DB::table('appointments')->where('id', $booking['id'])->value('status'));
    }
}
