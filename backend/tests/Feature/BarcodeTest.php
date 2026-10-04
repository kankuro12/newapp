<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BarcodeTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Barcode shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$tenant['slug'];
        $cash = $this->getJson($url.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();

        return compact('owner', 'tenant', 'url', 'cash');
    }

    private function item(array $s, string $kind = 'service'): string
    {
        return $this->postJson($s['url'].'/items', ['name' => 'Weighted goods', 'sku' => '00501', 'aliases' => ['00000123', 'ALT-00501'], 'kind' => $kind, 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->assertJsonPath('data.aliases.0', '00000123')->json('data.id');
    }

    private function rules(): array
    {
        return [['name' => 'Weight', 'prefix' => '20', 'total_length' => 13, 'product_digits' => 5, 'value_digits' => 5, 'decimals' => 3, 'mode' => 'quantity', 'unit' => 'kg'], ['name' => 'Price', 'prefix' => '21', 'total_length' => 13, 'product_digits' => 5, 'value_digits' => 5, 'decimals' => 2, 'mode' => 'amount', 'unit' => 'unit']];
    }

    private function configure(array $s): array
    {
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $input = ['version' => $config['version'], 'rules' => $this->rules(), 'mutation_uuid' => (string) Str::uuid()];
        $this->patchJson($s['url'].'/barcodes/config', $input)->assertCreated();
        $this->patchJson($s['url'].'/barcodes/config', $input)->assertOk();

        return $input;
    }

    public function test_aliases_zeroes_collisions_scoping_privacy_and_csv(): void
    {
        $s = $this->shop();
        $id = $this->item($s);
        foreach (['00501', '00000123', 'ALT-00501'] as $code) {
            $this->postJson($s['url'].'/pos/scan', compact('code'))->assertOk()->assertJsonPath('data.item_id', $id)->assertJsonPath('data.measurement.value', '1');
        }
        $this->postJson($s['url'].'/pos/scan', ['code' => '123'])->assertUnprocessable();
        $zero = $this->postJson($s['url'].'/items', ['name' => 'Zero code', 'sku' => '0', 'kind' => 'service', 'unit_label' => 'unit', 'sale_price' => '1'])->assertCreated()->json('data.id');
        $zeroScan = $this->postJson($s['url'].'/pos/scan', ['code' => '0'])->assertOk()->json('data.measurement');
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $zero, 'measurement' => [...$zeroScan, 'value' => '999']]]])->assertOk()->assertJsonPath('data.total_paisa', '100');
        foreach (['sku' => '00000123', 'aliases' => ['00501']] as $key => $value) {
            $this->postJson($s['url'].'/items', ['name' => 'Collision', $key => $value, 'kind' => 'service', 'unit_label' => 'unit', 'sale_price' => '1'])->assertUnprocessable();
        }
        $export = $this->get($s['url'].'/imports/items/export')->assertOk()->streamedContent();
        $review = $this->postJson($s['url'].'/imports/preview', ['resource' => 'items', 'csv' => $export])->assertOk()->assertJsonPath('data.valid', true)->json('data');
        foreach ($review['rows'] as $row) {
            $this->assertSame([], $row['changes']);
        }
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $input = ['version' => $config['version'], 'aliases' => ['00000123', 'ALT-00501', 'NEW-00501'], 'mutation_uuid' => (string) Str::uuid()];
        $this->postJson($s['url'].'/barcodes/items/'.$id, $input)->assertCreated()->assertJsonPath('data.aliases.2', 'NEW-00501');
        $this->postJson($s['url'].'/barcodes/items/'.$id, $input)->assertOk();
        $this->postJson($s['url'].'/barcodes/items/'.$id, [...$input, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->getJson($s['url'].'/items?q=NEW-00501')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson($s['url'].'/lookup?q=NEW-00501')->assertOk()->assertJsonPath('data.items.0.id', $id);
        $csv = "name,sku,kind,unit_label,sale_price,aliases\nFirst,NEW-CODE,service,unit,1,[]\nSecond,,service,unit,1,\"[\"\"NEW-CODE\"\"]\"";
        $this->postJson($s['url'].'/imports/preview', ['resource' => 'items', 'csv' => $csv])->assertOk()->assertJsonPath('data.valid', false);
        $foreign = $this->shop();
        $this->postJson($foreign['url'].'/pos/scan', ['code' => '00501'])->assertUnprocessable();
        $foreignConfig = $this->getJson($foreign['url'].'/barcodes/config')->assertOk()->json('data');
        $this->postJson($foreign['url'].'/barcodes/items/'.$id, ['version' => $foreignConfig['version'], 'aliases' => ['FOREIGN'], 'mutation_uuid' => (string) Str::uuid()])->assertNotFound();
        try {
            DB::table('item_codes')->insert(['tenant_id' => $foreign['tenant']['id'], 'item_id' => $id, 'code' => 'FOREIGN']);
            $this->fail('Cross-tenant code must fail its composite foreign key.');
        } catch (QueryException $error) {
            $this->assertSame('23000', $error->errorInfo[0]);
        }
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->postJson($s['url'].'/pos/scan', ['code' => '00000123'])->assertOk();
        $this->getJson($s['url'].'/items/'.$id)->assertOk()->assertJsonMissingPath('data.last_purchase_price_paisa')->assertJsonPath('data.aliases.0', '00000123');
        $this->patchJson($s['url'].'/barcodes/config', ['rules' => [], 'version' => '1', 'mutation_uuid' => (string) Str::uuid()])->assertForbidden();
        $this->postJson($s['url'].'/barcodes/items/'.$id, $input)->assertForbidden();
        $this->actingAs($s['owner'], 'tenant');
        $this->postJson($s['url'].'/items/'.$id.'/archive')->assertOk();
        $this->postJson($s['url'].'/pos/scan', ['code' => '00000123'])->assertUnprocessable();
    }

    public function test_embedded_scan_trusted_measurement_retry_and_original_return(): void
    {
        $s = $this->shop();
        $id = $this->item($s, 'stock');
        $supplier = $this->postJson($s['url'].'/contacts', ['name' => 'Stock supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $supplier, 'business_date_bs' => 20830102, 'lines' => [['item_id' => $id, 'qty' => '1', 'unit_price' => '60']], 'expected_total_paisa' => '6000', 'paid_now' => '0'])->assertCreated();
        $this->postJson($s['url'].'/pos/scan', ['code' => '2000501003751'])->assertUnprocessable();
        $this->configure($s);
        $m = $this->postJson($s['url'].'/pos/scan', ['code' => '2000501003751'])->assertOk()->assertJsonPath('data.item_id', $id)->assertJsonPath('data.measurement.value', '0.375')->json('data.measurement');
        $this->postJson($s['url'].'/pos/scan', ['code' => '2000501003752'])->assertUnprocessable();
        $input = ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'lines' => [['item_id' => $id, 'measurement' => [...$m, 'value' => '999']]], 'expected_total_paisa' => '3750', 'paid_now' => '37.50', 'money_account_id' => $s['cash']];
        $doc = $this->postJson($s['url'].'/pos/sales', $input)->assertCreated()->assertJsonPath('data.lines.0.qty_milli', '375')->assertJsonPath('data.lines.0.measurement_snapshot.barcode', '2000501003751')->json('data');
        $this->postJson($s['url'].'/pos/sales', $input)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->assertSame(625, (int) DB::table('inventory_balances')->where('item_id', $id)->value('qty_milli'));
        $this->assertSame(3750, (int) DB::table('inventory_balances')->where('item_id', $id)->value('value_paisa'));
        $price = $this->postJson($s['url'].'/pos/scan', ['code' => '2100501012347'])->assertOk()->json('data.measurement');
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => $price]]])->assertOk()->assertJsonPath('data.total_paisa', '1230');
        DB::table('items')->where('id', $id)->update(['sale_price_paisa' => 20000]);
        $this->postJson($s['url'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Barcode return', 'lines' => [['source_line_id' => $doc['lines'][0]['id'], 'qty' => '0.125']], 'expected_total_paisa' => '1250'])->assertCreated()->assertJsonPath('data.lines.0.unit_price_paisa', '10000')->assertJsonPath('data.lines.0.measurement_snapshot.source_measurement.barcode', '2000501003751');
        $this->assertSame(750, (int) DB::table('inventory_balances')->where('item_id', $id)->value('qty_milli'));
        $this->assertSame(4500, (int) DB::table('inventory_balances')->where('item_id', $id)->value('value_paisa'));
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $this->patchJson($s['url'].'/barcodes/config', ['version' => $config['version'], 'rules' => [], 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => $m]]])->assertUnprocessable();
        $this->postJson($s['url'].'/pos/sales', $input)->assertOk()->assertJsonPath('data.id', $doc['id']);
    }

    public function test_rules_reject_overlap_stale_edits_and_wrong_units(): void
    {
        $s = $this->shop();
        $id = $this->item($s);
        $old = $this->configure($s);
        $m = $this->postJson($s['url'].'/pos/scan', ['code' => '2000501003751'])->assertOk()->json('data.measurement');
        $this->patchJson($s['url'].'/barcodes/config', [...$old, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $rules = $this->rules();
        $rules[1]['prefix'] = '20';
        $this->patchJson($s['url'].'/barcodes/config', ['version' => $config['version'], 'rules' => $rules, 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $rules = $this->rules();
        $rules[0]['unit'] = 'g';
        $rules[0]['decimals'] = 0;
        $this->patchJson($s['url'].'/barcodes/config', ['version' => $config['version'], 'rules' => $rules, 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => $m]]])->assertConflict();
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $rules = $this->rules();
        $rules[0]['unit'] = 'l';
        $this->patchJson($s['url'].'/barcodes/config', ['version' => $config['version'], 'rules' => $rules, 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->postJson($s['url'].'/pos/scan', ['code' => '2000501003751'])->assertUnprocessable();
    }

    public function test_integer_grams_and_upc_labels_keep_exact_quantity(): void
    {
        $s = $this->shop();
        $id = $this->item($s);
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $rules = $this->rules();
        $rules = [$rules[0]];
        $rules[0]['decimals'] = 0;
        $rules[0]['unit'] = 'g';
        $this->patchJson($s['url'].'/barcodes/config', ['version' => $config['version'], 'rules' => $rules, 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $m = $this->postJson($s['url'].'/pos/scan', ['code' => '2000501003751'])->assertOk()->assertJsonPath('data.measurement.value', '375')->json('data.measurement');
        $this->postJson($s['url'].'/pos/preview', ['lines' => [['item_id' => $id, 'measurement' => $m]]])->assertOk()->assertJsonPath('data.lines.0.qty_milli', '375');
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $rules[0]['prefix'] = '2';
        $rules[0]['total_length'] = 12;
        $rules[0]['decimals'] = 3;
        $rules[0]['unit'] = 'kg';
        $this->patchJson($s['url'].'/barcodes/config', ['version' => $config['version'], 'rules' => $rules, 'mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->postJson($s['url'].'/pos/scan', ['code' => '200501003757'])->assertOk()->assertJsonPath('data.measurement.value', '0.375');
    }

    public function test_numeric_rule_fields_normalize_and_object_payloads_fail_validation(): void
    {
        $s = $this->shop();
        $id = $this->item($s);
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $rule = $this->rules()[0];
        foreach (['total_length', 'product_digits', 'value_digits', 'decimals'] as $key) {
            $rule[$key] = (string) $rule[$key];
        }
        $this->patchJson($s['url'].'/barcodes/config', ['version' => $config['version'], 'rules' => [$rule], 'mutation_uuid' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.rules.0.total_length', 13);
        $this->postJson($s['url'].'/pos/scan', ['code' => '2000501003751'])->assertOk()->assertJsonPath('data.measurement.value', '0.375');
        $config = $this->getJson($s['url'].'/barcodes/config')->assertOk()->json('data');
        $this->patchJson($s['url'].'/barcodes/config', ['version' => $config['version'], 'rules' => ['named' => $rule], 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($s['url'].'/barcodes/items/'.$id, ['version' => $config['version'], 'aliases' => ['named' => 'OBJECT-CODE'], 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_labels_refresh_scoped_prices_codes_tax_and_never_post(): void
    {
        $s = $this->shop();
        $id = $this->item($s, 'stock');
        DB::table('tenants')->where('id', $s['tenant']['id'])->update(['tax_recording_enabled' => true]);
        DB::table('items')->where('id', $id)->update(['sale_price_paisa' => 12345, 'default_tax_category' => 'standard', 'default_tax_bps' => 1300]);
        $version = DB::table('tenants')->where('id', $s['tenant']['id'])->value('data_version');
        $input = ['include_tax' => true, 'rows' => [['item_id' => $id, 'code' => '00000123', 'copies' => 2]]];
        $this->postJson($s['url'].'/barcodes/labels', $input)->assertOk()->assertJsonPath('data.rows.0.price_paisa', '13950')->assertJsonPath('data.rows.0.code', '00000123')->assertJsonPath('data.rows.0.copies', 2)->assertJsonMissingPath('data.rows.0.last_purchase_price_paisa');
        $this->postJson($s['url'].'/barcodes/labels', [...$input, 'include_tax' => false])->assertOk()->assertJsonPath('data.rows.0.price_paisa', '12345');
        DB::table('items')->where('id', $id)->update(['sale_price_paisa' => 20000]);
        $this->postJson($s['url'].'/barcodes/labels', $input)->assertOk()->assertJsonPath('data.rows.0.price_paisa', '22600');
        $this->postJson($s['url'].'/barcodes/labels', [...$input, 'rows' => [['item_id' => $id, 'code' => null, 'copies' => 1]]])->assertOk()->assertJsonPath('data.rows.0.code', null);
        foreach ([['item_id' => $id, 'code' => 'OTHER', 'copies' => 1], ['item_id' => $id, 'code' => '00000123', 'copies' => 1001]] as $row) {
            $this->postJson($s['url'].'/barcodes/labels', [...$input, 'rows' => [$row]])->assertUnprocessable();
        }
        $this->postJson($s['url'].'/barcodes/labels', [...$input, 'rows' => [['item_id' => $id, 'code' => '00000123', 'copies' => 600], ['item_id' => $id, 'code' => '00501', 'copies' => 600]]])->assertUnprocessable();
        $this->assertSame($version, DB::table('tenants')->where('id', $s['tenant']['id'])->value('data_version'));
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $foreign = $this->shop();
        $this->postJson($foreign['url'].'/barcodes/labels', $input)->assertNotFound();
        $this->actingAs($s['owner'], 'tenant');
        $this->postJson($s['url'].'/items/'.$id.'/archive')->assertOk();
        $this->postJson($s['url'].'/barcodes/labels', $input)->assertUnprocessable();
    }

    public function test_label_bulk_selection_scopes_category_supplier_and_cashier_privacy(): void
    {
        $s = $this->shop();
        $id = $this->item($s);
        $category = $this->postJson($s['url'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Weighted'])->assertCreated()->json('data.id');
        $supplier = $this->postJson($s['url'].'/contacts', ['name' => 'Label supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        DB::table('items')->where('id', $id)->update(['category_id' => $category, 'preferred_supplier_id' => $supplier]);
        $this->getJson($s['url'].'/barcodes/items?category_id='.$category.'&supplier_id='.$supplier)->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('data.0.preferred_supplier_id')->assertJsonMissingPath('data.0.value_paisa');
        $this->getJson($s['url'].'/barcodes/items?category_id=0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($s['url'].'/barcodes/items')->assertUnprocessable();
        $foreign = $this->shop();
        $this->getJson($foreign['url'].'/barcodes/items?category_id='.$category)->assertNotFound();
        $this->getJson($foreign['url'].'/barcodes/items?supplier_id='.$supplier)->assertNotFound();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->getJson($s['url'].'/barcodes/items?category_id='.$category)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson($s['url'].'/barcodes/items?supplier_id='.$supplier)->assertForbidden();
        $this->postJson($s['url'].'/barcodes/labels', ['include_tax' => false, 'rows' => [['item_id' => $id, 'code' => '00501', 'copies' => 1]]])->assertOk();
        $item = (array) DB::table('items')->where('id', $id)->first();
        unset($item['id']);
        for ($i = 0; $i < 100; $i++) {
            DB::table('items')->insert([...$item, 'name' => 'Limit '.$i, 'sku' => 'LIMIT-'.$i]);
        }
        $this->getJson($s['url'].'/barcodes/items?category_id='.$category)->assertUnprocessable();
    }
}
