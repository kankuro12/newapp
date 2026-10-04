<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PriceListImportTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'CSV price shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$tenant['slug'];
        $item = $this->postJson($url.'/items', ['name' => '=Milk', 'sku' => '00012', 'kind' => 'stock', 'unit_label' => 'l', 'pos_unit' => 'l', 'sale_price' => '100'])->assertCreated()->json('data.id');

        return compact('owner', 'tenant', 'url', 'item');
    }

    private function preview(array $s, string $csv, bool $replace = false): array
    {
        return $this->postJson($s['url'].'/imports/preview', ['resource' => 'price_lists', 'csv' => $csv, 'replace_rules' => $replace])->assertOk()->json('data');
    }

    private function applyCsv(array $s, string $csv, array $review, bool $replace = false, ?string $uuid = null): TestResponse
    {
        return $this->postJson($s['url'].'/imports', ['resource' => 'price_lists', 'csv' => $csv, 'replace_rules' => $replace, 'digest' => $review['digest'], 'version' => $review['version'], 'mutation_uuid' => $uuid ?? (string) Str::uuid()]);
    }

    public function test_review_atomic_lists_exact_tiers_export_and_original_retry(): void
    {
        $s = $this->shop();
        $csv = "name,channel,adjustment_mode,adjustment_percent,item_sku,min_qty,price\n=Wholesale,sale,decrease,5,00012,0,१००.२५\n=Wholesale,sale,decrease,5,00012,2.500,90.35\nSupplier,purchase,increase,2.5,,,\n";
        $review = $this->preview($s, $csv);
        $this->assertTrue($review['valid']);
        $this->assertSame(['create' => 2, 'update' => 0, 'categories' => 0], $review['counts']);
        $this->assertDatabaseCount('price_lists', 0);
        $uuid = (string) Str::uuid();
        $batch = $this->applyCsv($s, $csv, $review, false, $uuid)->assertCreated()->assertJsonPath('data.created_count', 2)->json('data.id');
        $this->applyCsv($s, $csv, $review, false, $uuid)->assertOk()->assertJsonPath('data.id', $batch);
        $this->assertDatabaseCount('price_lists', 2);
        $this->assertDatabaseCount('price_list_rates', 2);
        $this->assertDatabaseHas('price_list_rates', ['item_id' => $s['item'], 'min_qty_milli' => 2500, 'price_paisa' => 9035, 'unit_snapshot' => 'l']);
        $this->assertDatabaseHas('price_lists', ['name' => 'Supplier', 'adjustment_bps' => 250]);
        $export = $this->get($s['url'].'/imports/price_lists/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=Wholesale", $export);
        $this->assertStringContainsString("'=Milk", $export);
        $this->assertStringContainsString('00012', $export);
        $roundTrip = $this->preview($s, $export);
        $this->assertTrue($roundTrip['valid']);
        $this->assertSame(2, $roundTrip['counts']['update']);
        $this->applyCsv($s, $export, $roundTrip)->assertCreated();
        $this->assertDatabaseCount('price_list_rates', 2);
        foreach (['documents', 'stock_movements', 'payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertStringContainsString('unit_snapshot', $this->get($s['url'].'/imports/price_lists/template')->assertOk()->streamedContent());
    }

    public function test_merge_keeps_omitted_tiers_replace_removes_only_included_lists(): void
    {
        $s = $this->shop();
        $csv = "name,channel,item_id,min_qty,price\nWholesale,sale,{$s['item']},0,100\nWholesale,sale,{$s['item']},2,90\nOther,sale,{$s['item']},0,95\n";
        $this->applyCsv($s, $csv, $this->preview($s, $csv))->assertCreated();
        $id = DB::table('price_lists')->where('name', 'Wholesale')->value('id');
        $change = "id,item_id,min_qty,price\n$id,{$s['item']},2,80.25\n";
        $review = $this->preview($s, $change);
        $this->assertTrue($review['valid']);
        $this->applyCsv($s, $change, $review)->assertCreated();
        $this->assertSame(2, DB::table('price_list_rates')->where('price_list_id', $id)->count());
        $replace = $this->preview($s, $change, true);
        $rules = collect($replace['rows'][0]['changes'])->firstWhere('column', 'rules');
        $this->assertCount(2, $rules['before']);
        $this->assertCount(1, $rules['after']);
        $this->applyCsv($s, $change, $replace, true)->assertCreated();
        $this->assertSame(1, DB::table('price_list_rates')->where('price_list_id', $id)->count());
        $this->assertDatabaseCount('price_list_rates', 2);
        $clear = "id,enabled\n$id,0\n";
        $this->applyCsv($s, $clear, $this->preview($s, $clear, true), true)->assertCreated();
        $this->assertSame(0, DB::table('price_list_rates')->where('price_list_id', $id)->count());
    }

    public function test_conflicts_bad_values_and_tier_limits_leave_batch_unchanged(): void
    {
        $s = $this->shop();
        foreach ([
            "name,channel,item_id,min_qty,price\nA,sale,{$s['item']},0,100\nA,sale,{$s['item']},0,90\n",
            "name,channel,enabled\nA,sale,1\nA,sale,0\n",
            "name,channel,adjustment_mode,adjustment_percent\nA,sale,decrease,100\n",
            "name,channel,starts_bs,ends_bs\nA,sale,20830103,20830101\n",
            "name,channel,item_id,item_sku,min_qty,price\nA,sale,{$s['item']},wrong,0,100\n",
            "name,channel,item_id,min_qty,price\nA,sale,{$s['item']},1,0\n",
            "name,channel\nA,invalid\n",
            "name,channel,stock\nA,sale,100\n",
        ] as $csv) {
            $review = $this->preview($s, $csv);
            $this->assertFalse($review['valid'], $csv);
            $this->applyCsv($s, $csv, [...$review, 'digest' => str_repeat('0', 64)])->assertUnprocessable();
        }
        $this->assertDatabaseCount('price_lists', 0);
        $this->assertDatabaseCount('master_import_batches', 0);
        $tiers = "name,channel,item_id,min_qty,price\n";
        for ($i = 0; $i < 11; $i++) {
            $tiers .= "A,sale,{$s['item']},$i,100\n";
        }
        $this->assertFalse($this->preview($s, $tiers)['valid']);
    }

    public function test_scopes_roles_stale_units_and_batch_limits(): void
    {
        $s = $this->shop();
        $foreign = $this->shop();
        $this->actingAs($s['owner'], 'tenant');
        $csv = "name,channel,item_id,min_qty,price\nA,sale,{$foreign['item']},0,100\n";
        $this->assertFalse($this->preview($s, $csv)['valid']);
        $csv = "name,channel,item_id,min_qty,price\nA,sale,{$s['item']},0,100\n";
        $review = $this->preview($s, $csv);
        DB::table('items')->where('id', $s['item'])->update(['unit_label' => 'can']);
        $this->applyCsv($s, $csv, $review)->assertConflict();
        $this->assertDatabaseCount('price_lists', 0);
        $many = "name,channel\n";
        for ($i = 0; $i < 51; $i++) {
            $many .= "List$i,sale\n";
        }
        $this->assertFalse($this->preview($s, $many)['valid']);
        $this->postJson($s['url'].'/imports/preview', ['resource' => 'price_lists', 'csv' => "name,channel\n".str_repeat("A,sale\n", 1001)])->assertUnprocessable();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->get($s['url'].'/imports/price_lists/export')->assertForbidden();
        $this->get($s['url'].'/imports/price_lists/template')->assertForbidden();
        $this->postJson($s['url'].'/imports/preview', ['resource' => 'price_lists', 'csv' => $csv])->assertForbidden();
        $this->applyCsv($s, $csv, $review)->assertForbidden();
    }

    public function test_list_mapping_collisions_and_changed_business_require_new_review(): void
    {
        $s = $this->shop();
        $csv = "name,channel\nFirst,sale\nSecond,sale\n";
        $this->applyCsv($s, $csv, $this->preview($s, $csv))->assertCreated();
        $ids = DB::table('price_lists')->orderBy('id')->pluck('id');
        $collision = "id,name\n{$ids[0]},Same\n{$ids[1]},same\n";
        $this->assertFalse($this->preview($s, $collision)['valid']);
        $foreign = $this->shop();
        $csv = "name,channel\nForeign,purchase\n";
        $this->applyCsv($foreign, $csv, $this->preview($foreign, $csv))->assertCreated();
        $foreignId = DB::table('price_lists')->where('name', 'Foreign')->value('id');
        $this->actingAs($s['owner'], 'tenant');
        $this->assertFalse($this->preview($s, "id,enabled\n$foreignId,0\n")['valid']);
        $csv = "Title,For,Ignore\nMapped,sale,unused\n";
        $input = ['resource' => 'price_lists', 'csv' => $csv, 'mapping' => ['name', 'channel', '']];
        $review = $this->postJson($s['url'].'/imports/preview', $input)->assertOk()->json('data');
        $this->assertTrue($review['valid']);
        $this->assertSame(['ignore'], $review['ignored']);
        $this->postJson($s['url'].'/contacts', ['name' => 'New buyer', 'is_customer' => true, 'is_supplier' => false])->assertCreated();
        $this->postJson($s['url'].'/imports', [...$input, 'digest' => $review['digest'], 'version' => $review['version'], 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseMissing('price_lists', ['name' => 'Mapped']);
    }
}
