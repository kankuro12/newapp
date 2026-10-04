<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Catalog shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$tenant['slug'];
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $category = $this->postJson($url.'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Drinks'])->assertCreated()->json('data');
        $supplier = $this->postJson($url.'/contacts', ['name' => 'Wholesale supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $input = ['name' => 'Juice', 'sku' => 'JUICE', 'kind' => 'stock', 'unit_label' => 'bottle', 'sale_price' => '100', 'category_id' => $category['id'], 'preferred_supplier_id' => $supplier, 'low_stock_qty' => '4', 'reorder_target_qty' => '10'];
        $item = $this->postJson($url.'/items', $input)->assertCreated()->json('data.id');

        return compact('owner', 'tenant', 'url', 'category', 'supplier', 'input', 'item');
    }

    public function test_categories_archive_filters_and_cashier_privacy(): void
    {
        $s = $this->shop();
        $this->postJson($s['url'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => ' drinks '])->assertUnprocessable();
        $this->getJson($s['url'].'/items?category_id='.$s['category']['id'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.category_name', 'Drinks');
        $this->getJson($s['url'].'/items?category_id=0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($s['url'].'/lookup?q=Dr&item_categories=1')->assertOk()->assertJsonPath('data.item_categories.0.name', 'Drinks');
        $archive = ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'name' => 'Drinks', 'archived' => true];
        $this->patchJson($s['url'].'/item-categories/'.$s['category']['id'], $archive)->assertCreated()->assertJsonPath('data.version', 2);
        $this->patchJson($s['url'].'/item-categories/'.$s['category']['id'], $archive)->assertOk();
        $this->patchJson($s['url'].'/item-categories/'.$s['category']['id'], [...$archive, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->getJson($s['url'].'/lookup?q=Dr&item_categories=1')->assertOk()->assertJsonCount(0, 'data.item_categories');
        $this->patchJson($s['url'].'/items/'.$s['item'], [...$s['input'], 'sale_price' => '120'])->assertOk();
        $this->postJson($s['url'].'/items', [...$s['input'], 'name' => 'Other', 'sku' => 'OTHER'])->assertUnprocessable();
        $this->getJson($s['url'].'/items?category_id='.$s['category']['id'])->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson($s['url'].'/item-categories/'.$s['category']['id'], [...$archive, 'mutation_uuid' => (string) Str::uuid(), 'version' => 2, 'archived' => false])->assertCreated();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'active' => true, 'role' => 'cashier']);
        $this->actingAs($cashier, 'tenant');
        $this->getJson($s['url'].'/items?category_id='.$s['category']['id'])->assertOk()->assertJsonMissingPath('data.0.preferred_supplier_id')->assertJsonMissingPath('data.0.reorder_target_qty_milli');
        $this->getJson($s['url'].'/item-categories')->assertOk();
        $this->postJson($s['url'].'/item-categories', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'No'])->assertForbidden();
        $this->getJson($s['url'].'/reorders')->assertForbidden();
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_reorder_subtracts_open_orders_and_guards_stale_duplicate_requests(): void
    {
        $s = $this->shop();
        $this->postJson($s['url'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $s['supplier'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'qty' => '3', 'unit_price' => '100']], 'expected_total_paisa' => '30000', 'paid_now' => '0'])->assertCreated();
        $this->postJson($s['url'].'/contacts/'.$s['supplier'].'/rates', ['mutation_uuid' => (string) Str::uuid(), 'item_id' => $s['item'], 'channel' => 'purchase', 'price' => '80.25', 'enabled' => true])->assertCreated();
        $row = $this->getJson($s['url'].'/reorders')->assertOk()->assertJsonPath('data.0.qty_milli', '3000')->assertJsonPath('data.0.suggested_qty_milli', '7000')->assertJsonPath('data.0.unit_price_paisa', '8025')->json('data.0');
        $manual = $this->postJson($s['url'].'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'purchase_order', 'contact_id' => $s['supplier'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'qty' => '2', 'unit_price' => '80.25']], 'expected_total_paisa' => '16050'])->assertCreated()->json('data');
        $request = ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'purchase_order', 'contact_id' => $s['supplier'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'qty' => '5', 'unit_price' => '80.25']], 'expected_total_paisa' => '40125', 'reorder' => [$s['item'] => $row['fingerprint']]];
        $this->postJson($s['url'].'/workflows', $request)->assertConflict();
        $fresh = $this->getJson($s['url'].'/reorders')->assertOk()->assertJsonPath('data.0.pending_qty_milli', '2000')->assertJsonPath('data.0.suggested_qty_milli', '5000')->json('data.0');
        $this->postJson($s['url'].'/workflows', [...$request, 'mutation_uuid' => (string) Str::uuid(), 'reorder' => [$s['item'].'x' => $fresh['fingerprint']]])->assertUnprocessable();
        $this->postJson($s['url'].'/workflows', [...$request, 'mutation_uuid' => (string) Str::uuid(), 'contact_id' => null, 'reorder' => [$s['item'] => $fresh['fingerprint']]])->assertUnprocessable();
        $request['reorder'][$s['item']] = $fresh['fingerprint'];
        $order = $this->postJson($s['url'].'/workflows', $request)->assertCreated()->assertJsonPath('data.total_paisa', '40125')->json('data');
        $this->postJson($s['url'].'/workflows', $request)->assertOk()->assertJsonPath('data.id', $order['id']);
        $this->postJson($s['url'].'/workflows', [...$request, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->getJson($s['url'].'/reorders')->assertOk()->assertJsonPath('data.0.pending_qty_milli', '7000')->assertJsonPath('data.0.suggested_qty_milli', '0');
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(3000, (int) DB::table('inventory_balances')->value('qty_milli'));
        $this->assertSame(2, DB::table('business_workflows')->count());
        $this->postJson($s['url'].'/workflow/'.$order['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'status' => 'cancelled', 'reason' => 'Duplicate demand removed'])->assertCreated();
        $this->getJson($s['url'].'/reorders')->assertOk()->assertJsonPath('data.0.suggested_qty_milli', '5000');
        $this->postJson($s['url'].'/workflow/'.$manual['id'].'/bill', ['mutation_uuid' => (string) Str::uuid(), 'version' => $manual['version'], 'business_date_bs' => 20830103, 'expected_total_paisa' => '16050', 'paid_now' => '0'])->assertCreated();
        $this->getJson($s['url'].'/reorders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_category_supplier_ownership_and_target_validation(): void
    {
        $s = $this->shop();
        $other = $this->shop();
        $this->actingAs($s['owner'], 'tenant');
        $this->patchJson($s['url'].'/items/'.$s['item'], [...$s['input'], 'category_id' => $other['category']['id']])->assertNotFound();
        $this->patchJson($s['url'].'/items/'.$s['item'], [...$s['input'], 'preferred_supplier_id' => $other['supplier']])->assertNotFound();
        $this->patchJson($s['url'].'/items/'.$s['item'], [...$s['input'], 'reorder_target_qty' => '2'])->assertUnprocessable();
        $this->patchJson($s['url'].'/item-categories/'.$other['category']['id'], ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'name' => 'No', 'archived' => false])->assertNotFound();
        $this->getJson($s['url'].'/reorders?supplier_id='.$other['supplier'])->assertNotFound();
        $this->getJson($s['url'].'/reorders?category_id='.$other['category']['id'])->assertNotFound();
        try {
            DB::table('items')->where('id', $s['item'])->update(['category_id' => $other['category']['id']]);
            $this->fail('Database accepted foreign category.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('foreign key', strtolower($error->getMessage()));
        }
        $this->assertSame((int) $s['category']['id'], (int) DB::table('items')->where('id', $s['item'])->value('category_id'));
    }

    public function test_review_must_be_refreshed_after_price_stock_or_supplier_changes(): void
    {
        $s = $this->shop();
        $row = $this->getJson($s['url'].'/reorders')->assertOk()->json('data.0');
        $request = ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'purchase_order', 'contact_id' => $s['supplier'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'qty' => '10', 'unit_price' => '100']], 'expected_total_paisa' => '100000', 'reorder' => [$s['item'] => $row['fingerprint']]];
        $this->postJson($s['url'].'/contacts/'.$s['supplier'].'/rates', ['mutation_uuid' => (string) Str::uuid(), 'item_id' => $s['item'], 'channel' => 'purchase', 'price' => '90', 'enabled' => true])->assertCreated();
        $this->postJson($s['url'].'/workflows', $request)->assertConflict();
        $fresh = $this->getJson($s['url'].'/reorders')->assertOk()->json('data.0');
        $request['reorder'][$s['item']] = $fresh['fingerprint'];
        $this->postJson($s['url'].'/documents/purchase', ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $s['supplier'], 'business_date_bs' => 20830103, 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '90']], 'expected_total_paisa' => '9000', 'paid_now' => '0'])->assertCreated();
        $this->postJson($s['url'].'/workflows', $request)->assertConflict();
        $request['reorder'][$s['item']] = $this->getJson($s['url'].'/reorders')->assertOk()->json('data.0.fingerprint');
        $otherSupplier = $this->postJson($s['url'].'/contacts', ['name' => 'Another supplier', 'is_customer' => false, 'is_supplier' => true])->assertCreated()->json('data.id');
        $this->postJson($s['url'].'/workflows', [...$request, 'contact_id' => $otherSupplier])->assertConflict();
        $this->patchJson($s['url'].'/items/'.$s['item'], [...$s['input'], 'preferred_supplier_id' => $otherSupplier])->assertOk();
        $this->postJson($s['url'].'/workflows', $request)->assertConflict();
        $this->assertSame(0, DB::table('business_workflows')->count());
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(0, DB::table('payments')->count());
    }
}
