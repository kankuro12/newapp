<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Import shop'])->assertCreated()->json('data');

        return ['owner' => $owner, 'tenant' => $tenant, 'url' => '/api/app/'.$tenant['slug']];
    }

    private function csv(array $rows): string
    {
        $file = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($file, $row, ',', '"', '');
        }
        rewind($file);
        $csv = stream_get_contents($file);
        fclose($file);

        return $csv;
    }

    private function review(array $shop, string $resource, string $csv): array
    {
        return $this->postJson($shop['url'].'/imports/preview', compact('resource', 'csv'))->assertOk()->json('data');
    }

    private function apply(array $shop, string $resource, string $csv, array $review, ?string $uuid = null): TestResponse
    {
        return $this->postJson($shop['url'].'/imports', ['resource' => $resource, 'csv' => $csv, 'digest' => $review['digest'], 'version' => $review['version'], 'mutation_uuid' => $uuid ?? (string) Str::uuid()]);
    }

    public function test_preview_then_atomic_party_import_and_original_retry(): void
    {
        $s = $this->shop();
        $before = DB::table('contacts')->count();
        $csv = "\xEF\xBB\xBF".$this->csv([['name', 'phone', 'address', 'is_customer', 'is_supplier', 'is_employee', 'is_rent'], ['राम, पसल', '9800000000', "Kathmandu\nWard 1", 'yes', '1', 'true', 'Y'], ['=SUM(1+1)', '9810000000', '', '1', '0', '0', '0']]);
        $review = $this->review($s, 'contacts', $csv);
        $this->assertTrue($review['valid']);
        $this->assertSame(2, $review['counts']['create']);
        $this->assertSame($before, DB::table('contacts')->count());
        $uuid = (string) Str::uuid();
        $batch = $this->apply($s, 'contacts', $csv, $review, $uuid)->assertCreated()->assertJsonPath('data.created_count', 2)->json('data');
        $this->apply($s, 'contacts', $csv, $review, $uuid)->assertOk()->assertJsonPath('data.id', $batch['id']);
        $this->postJson($s['url'].'/imports', ['resource' => 'contacts', 'csv' => $csv.'changed', 'digest' => $review['digest'], 'version' => $review['version'], 'mutation_uuid' => $uuid])->assertConflict();
        $party = DB::table('contacts')->where('name', 'राम, पसल')->first();
        $this->assertSame("Kathmandu\nWard 1", $party->address);
        foreach (['is_customer', 'is_supplier', 'is_employee', 'is_rent'] as $role) {
            $this->assertTrue((bool) $party->$role);
        }
        $this->assertSame($before + 2, DB::table('contacts')->count());
        $this->assertSame(1, DB::table('master_import_batches')->count());
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $export = $this->get($s['url'].'/imports/contacts/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM", $export);
        $this->assertStringContainsString('राम, पसल', $export);
        $this->assertTrue($this->review($s, 'contacts', $export)['valid']);
        $this->getJson($s['url'].'/imports')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_exact_items_categories_supplier_and_partial_updates(): void
    {
        $s = $this->shop();
        $supplier = $this->postJson($s['url'].'/contacts', ['name' => 'Supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $csv = $this->csv([['name', 'sku', 'kind', 'unit_label', 'sale_price', 'category', 'preferred_supplier_id', 'low_stock_qty', 'reorder_target_qty', 'pos_unit', 'pos_methods', 'pos_custom_units'], ['दूध', 'MILK', 'stock', 'l', '१००.२५', 'Drinks', $supplier, '2.125', '10.5', 'l', 'quantity|amount|pack', json_encode([['label' => 'Can', 'qty' => '12.5']])]]);
        $review = $this->review($s, 'items', $csv);
        $this->assertTrue($review['valid']);
        $this->assertSame(1, $review['counts']['categories']);
        $this->assertSame(0, DB::table('item_categories')->count());
        $this->apply($s, 'items', $csv, $review)->assertCreated();
        $item = DB::table('items')->where('sku', 'MILK')->first();
        $this->assertSame(10025, (int) $item->sale_price_paisa);
        $this->assertSame(2125, (int) $item->low_stock_qty_milli);
        $this->assertSame(10500, (int) $item->reorder_target_qty_milli);
        $this->assertSame('Drinks', DB::table('item_categories')->where('id', $item->category_id)->value('name'));
        $this->assertSame((int) $supplier, (int) $item->preferred_supplier_id);
        $this->assertSame([['label' => 'Can', 'qty' => '12.5']], json_decode($item->pos_custom_units, true));
        $update = "id,sale_price\n{$item->id},120.35\n";
        $review = $this->review($s, 'items', $update);
        $this->assertTrue($review['valid']);
        $this->assertSame(1, $review['counts']['update']);
        $this->assertSame([['column' => 'sale_price', 'before' => '100.25', 'after' => '120.35']], $review['rows'][0]['changes']);
        $this->apply($s, 'items', $update, $review)->assertCreated()->assertJsonPath('data.updated_count', 1);
        $row = DB::table('items')->where('id', $item->id)->first();
        $this->assertSame(12035, (int) $row->sale_price_paisa);
        $this->assertSame($item->pos_methods, $row->pos_methods);
        $this->assertSame($item->category_id, $row->category_id);
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $export = $this->get($s['url'].'/imports/items/export')->assertOk()->streamedContent();
        $review = $this->review($s, 'items', $export);
        $this->assertTrue($review['valid']);
        $this->assertSame(1, $review['counts']['update']);
        $this->assertSame([], $review['rows'][0]['changes']);
        DB::table('contacts')->where('id', $supplier)->update(['name' => '+Supplier']);
        DB::table('item_categories')->where('id', $item->category_id)->update(['name' => '=Drinks']);
        $export = $this->get($s['url'].'/imports/items/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'+Supplier", $export);
        $this->assertStringContainsString("'=Drinks", $export);
        $review = $this->review($s, 'items', $export);
        $this->assertTrue($review['valid']);
        $this->assertSame(0, $review['counts']['categories']);
        $this->assertSame([], $review['rows'][0]['changes']);
    }

    public function test_invalid_duplicates_and_stale_review_never_partially_apply(): void
    {
        $s = $this->shop();
        $csv = "name,sku,kind,unit_label,sale_price\nGood,ONE,stock,unit,1.25\nBad,TWO,stock,unit,1e3\n";
        $review = $this->review($s, 'items', $csv);
        $this->assertFalse($review['valid']);
        $this->assertNotEmpty($review['rows'][1]['errors']);
        $this->postJson($s['url'].'/imports', ['resource' => 'items', 'csv' => $csv, 'digest' => str_repeat('0', 64), 'version' => $review['version'], 'mutation_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame(0, DB::table('items')->count());
        $csv = "name,sku,kind,unit_label,sale_price\nA,ONE,stock,unit,1\nB,one,stock,unit,2\n";
        $this->assertFalse($this->review($s, 'items', $csv)['valid']);
        $csv = "name,sku,kind,unit_label,sale_price\nA,ONE,stock,unit,1\n";
        $review = $this->review($s, 'items', $csv);
        $this->postJson($s['url'].'/items', ['name' => 'Existing', 'sku' => 'ONE', 'kind' => 'stock', 'unit_label' => 'unit', 'sale_price' => '2'])->assertCreated();
        $this->apply($s, 'items', $csv, $review)->assertConflict();
        $this->assertFalse($this->review($s, 'items', $csv)['valid']);
        $this->assertSame(1, DB::table('items')->count());
        $this->assertSame(0, DB::table('master_import_batches')->count());
        foreach (["name,name\nA,B\n", "name\n\"unterminated\n"] as $bad) {
            $this->postJson($s['url'].'/imports/preview', ['resource' => 'contacts', 'csv' => $bad])->assertUnprocessable();
        }
        $this->assertFalse($this->review($s, 'contacts', "name,qty_milli\nA,100\n")['valid']);
    }

    public function test_stock_roles_archives_and_foreign_references_stay_protected(): void
    {
        $s = $this->shop();
        $this->postJson($s['url'].'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $supplier = $this->postJson($s['url'].'/contacts', ['name' => 'Vendor', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($s['url'].'/items', ['name' => 'Stock', 'sku' => 'STOCK', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '10'])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'contact_id' => $supplier, 'lines' => [['item_id' => $item, 'qty' => '1', 'unit_price' => '10']], 'expected_total_paisa' => '1000', 'paid_now' => '0'])->assertCreated();
        $this->assertFalse($this->review($s, 'items', "id,unit_label\n$item,l\n")['valid']);
        $this->assertFalse($this->review($s, 'contacts', "id,is_supplier,is_customer\n$supplier,0,1\n")['valid']);
        $foreign = $this->shop();
        $foreignId = $this->postJson($foreign['url'].'/contacts', ['name' => 'Foreign', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $this->actingAs($s['owner'], 'tenant');
        $this->assertFalse($this->review($s, 'contacts', "id,name\n$foreignId,Stolen\n")['valid']);
        $this->assertFalse($this->review($s, 'items', "name,sku,preferred_supplier_id\nBad,BAD,$foreignId\n")['valid']);
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->postJson($s['url'].'/imports/preview', ['resource' => 'items', 'csv' => "name,sku\nA,A\n"])->assertForbidden();
        $this->get($s['url'].'/imports/items/export')->assertForbidden();
        $this->getJson($s['url'].'/imports')->assertForbidden();
    }

    public function test_column_mapping_zero_sku_and_late_failure_roll_back_whole_batch(): void
    {
        $s = $this->shop();
        $csv = "Product,Code,Price,Legacy stock,Category\nFirst,0,1.25,999,0\nSecond,SECOND,2.35,999,0\n";
        $this->assertFalse($this->review($s, 'items', $csv)['valid']);
        $mapping = ['name', 'sku', 'sale_price', '', 'category'];
        $review = $this->postJson($s['url'].'/imports/preview', compact('csv', 'mapping') + ['resource' => 'items'])->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.ignored.0', 'legacy stock')->json('data');
        $input = ['resource' => 'items', 'csv' => $csv, 'mapping' => $mapping, 'digest' => $review['digest'], 'version' => $review['version'], 'mutation_uuid' => (string) Str::uuid()];
        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts) {
            if (str_starts_with($query->sql, 'insert into `items`') && ++$inserts === 2) {
                throw new \RuntimeException('Simulated storage failure');
            }
        });
        $this->postJson($s['url'].'/imports', $input)->assertServerError();
        foreach (['items', 'item_categories', 'master_import_batches', 'mutation_requests', 'stock_movements', 'documents'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame((string) $review['version'], (string) DB::table('tenants')->where('id', $s['tenant']['id'])->value('data_version'));
        $this->postJson($s['url'].'/imports', $input)->assertCreated()->assertJsonPath('data.created_count', 2)->assertJsonPath('data.category_count', 1);
        $this->assertSame(0, DB::table('inventory_balances')->count());
        $this->assertSame(125, (int) DB::table('items')->where('sku', '0')->value('sale_price_paisa'));
        $this->assertSame('0', DB::table('item_categories')->value('name'));
        $this->assertFalse($this->review($s, 'items', "id,name\n0,Invalid\n")['valid']);
        $this->postJson($s['url'].'/imports/preview', ['resource' => 'items', 'csv' => "name,sku\nA,A\n", 'mapping' => ['name', 'name']])->assertOk()->assertJsonPath('data.valid', false);
        $this->postJson($s['url'].'/imports/preview', ['resource' => 'items', 'csv' => "name,sku\n".str_repeat("A,A\n", 501)])->assertUnprocessable();
    }

    public function test_database_collation_duplicates_are_rejected_before_apply(): void
    {
        $s = $this->shop();
        $review = $this->review($s, 'items', "name,sku\nFirst,CAFE\nSecond,CAFÉ\n");
        $this->assertFalse($review['valid']);
        $this->assertNotEmpty($review['rows'][1]['errors']);
        $review = $this->review($s, 'contacts', "name,phone\nCafé,9800000000\nCafe,9800000000\n");
        $this->assertFalse($review['valid']);
        $csv = "name,sku,category\nFirst,FIRST,Café\nSecond,SECOND,Cafe\n";
        $review = $this->review($s, 'items', $csv);
        $this->assertTrue($review['valid']);
        $this->assertSame(1, $review['counts']['categories']);
        $this->apply($s, 'items', $csv, $review)->assertCreated();
        $this->assertSame(1, DB::table('items')->distinct()->count('category_id'));
    }

    public function test_niche_defaults_explicit_units_and_archived_system_records(): void
    {
        $s = $this->shop();
        DB::table('tenants')->where('id', $s['tenant']['id'])->update(['pos_profile' => 'milk']);
        $review = $this->review($s, 'items', "name,sku\nMilk,MILK\n");
        $this->assertTrue($review['valid']);
        $this->assertSame('l', $review['rows'][0]['input']['pos_unit']);
        $review = $this->review($s, 'items', "name,sku,unit_label\nSugar,SUGAR,kg\n");
        $this->assertSame('kg', $review['rows'][0]['input']['pos_unit']);
        DB::table('tenants')->where('id', $s['tenant']['id'])->update(['pos_profile' => 'salon']);
        $review = $this->review($s, 'items', "name,sku\nHaircut,CUT\n");
        $this->assertSame('service', $review['rows'][0]['input']['kind']);
        $item = $this->postJson($s['url'].'/items', ['name' => 'Haircut', 'sku' => 'CUT', 'kind' => 'service', 'unit_label' => 'unit', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/items/'.$item.'/archive')->assertOk();
        $this->assertFalse($this->review($s, 'items', "id,sale_price\n$item,120\n")['valid']);
        $system = DB::table('contacts')->where('tenant_id', $s['tenant']['id'])->where('is_system', true)->value('id');
        $this->assertFalse($this->review($s, 'contacts', "id,name\n$system,Changed\n")['valid']);
        $this->postJson($s['url'].'/imports/preview', ['resource' => 'items', 'csv' => implode(',', range(1, 101))."\n".implode(',', range(1, 101))."\n"])->assertUnprocessable();
    }
}
