<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Workflow shop'])->assertCreated()->json('data');
        $base = '/api/app/'.$business['slug'];
        $cash = $this->getJson($base.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $party = $this->postJson($base.'/contacts', ['name' => 'Buyer/supplier', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Service', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '100'])->assertCreated()->json('data.id');

        return compact('owner', 'business', 'base', 'cash', 'party', 'item');
    }

    private function entry(array $s, string $kind = 'quote'): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'kind' => $kind, 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'valid_until_bs' => 20900101, 'due_date_bs' => 20831201, 'niche' => 'repair', 'title' => 'Screen repair', 'reference' => 'Device ABC', 'specifications' => 'Cracked screen; no device password recorded.', 'lines' => [['item_id' => $s['item'], 'qty' => '2', 'unit_price' => '100', 'discount' => '10', 'tax_bps' => 0, 'tax_category' => 'outside_scope']], 'invoice_discount' => '5', 'expected_total_paisa' => '18500'];
    }

    private function state(array $s, array $row, string $status): array
    {
        return $this->postJson($s['base'].'/workflow/'.$row['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $row['version'], 'status' => $status, 'reason' => 'Customer requested'])->assertCreated()->json('data');
    }

    private function checkout(array $s, array $row): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'version' => $row['version'], 'business_date_bs' => 20830103, 'paid_now' => '185', 'money_account_id' => $s['cash'], 'expected_total_paisa' => '18500'];
    }

    public function test_approved_quote_order_bill_retains_exact_prices_and_retries_once(): void
    {
        $s = $this->shop();
        $input = $this->entry($s);
        $quote = $this->postJson($s['base'].'/workflows', $input)->assertCreated()->assertJsonPath('data.total_paisa', '18500')->json('data');
        $this->postJson($s['base'].'/workflows', $input)->assertOk()->assertJsonPath('data.id', $quote['id']);
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->postJson($s['base'].'/workflow/'.$quote['id'].'/order', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1])->assertConflict();
        $quote = $this->state($s, $quote, 'sent');
        $quote = $this->state($s, $quote, 'accepted');
        DB::table('items')->where('id', $s['item'])->update(['sale_price_paisa' => 99999]);
        $convert = ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version']];
        $order = $this->postJson($s['base'].'/workflow/'.$quote['id'].'/order', $convert)->assertCreated()->assertJsonPath('data.kind', 'sales_order')->assertJsonPath('data.source_id', $quote['id'])->json('data');
        $this->postJson($s['base'].'/workflow/'.$quote['id'].'/order', $convert)->assertOk()->assertJsonPath('data.id', $order['id']);
        $this->patchJson($s['base'].'/workflow/'.$order['id'], [...$input, 'version' => 1])->assertConflict();
        $order = $this->state($s, $order, 'in_progress');
        $order = $this->state($s, $order, 'ready');
        $order = $this->state($s, $order, 'fulfilled');
        $bill = $this->checkout($s, $order);
        $doc = $this->postJson($s['base'].'/workflow/'.$order['id'].'/bill', $bill)->assertCreated()->assertJsonPath('data.lines.0.unit_price_paisa', '10000')->assertJsonPath('data.total_paisa', '18500')->json('data');
        $this->assertStringContainsString('Device ABC', $doc['notes']);
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/bill', $bill)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/bill', [...$bill, 'mutation_uuid' => (string) Str::uuid()])->assertConflict();
        $this->assertSame(1, DB::table('documents')->count());
        $this->postJson($s['base'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830104, 'reason' => 'Original-price return', 'lines' => [['source_line_id' => $doc['lines'][0]['id'], 'qty' => '1']], 'expected_total_paisa' => '9250'])->assertCreated()->assertJsonPath('data.total_paisa', '9250');
    }

    public function test_revision_expiry_cancel_and_trust_boundary_leave_no_financial_effect(): void
    {
        $s = $this->shop();
        $input = $this->entry($s);
        $this->postJson($s['base'].'/workflows', [...$input, 'expected_total_paisa' => '1'])->assertConflict();
        $this->assertSame(0, DB::table('business_workflows')->count());
        $quote = $this->postJson($s['base'].'/workflows', $input)->assertCreated()->json('data');
        $quote = $this->state($s, $quote, 'sent');
        $this->patchJson($s['base'].'/workflow/'.$quote['id'], [...$input, 'mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version']])->assertConflict();
        $quote = $this->state($s, $quote, 'draft');
        $quote = $this->patchJson($s['base'].'/workflow/'.$quote['id'], [...$input, 'mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'valid_until_bs' => 20830103])->assertCreated()->json('data');
        $quote = $this->state($s, $quote, 'sent');
        $this->postJson($s['base'].'/workflow/'.$quote['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'status' => 'accepted'])->assertUnprocessable();
        $this->postJson($s['base'].'/workflow/'.$quote['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'status' => 'cancelled'])->assertUnprocessable();
        $quote = $this->state($s, $quote, 'cancelled');
        $this->postJson($s['base'].'/workflow/'.$quote['id'].'/bill', $this->checkout($s, $quote))->assertConflict();
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_purchase_order_posts_only_on_bill_and_failed_stock_sale_rolls_back(): void
    {
        $s = $this->shop();
        $s['item'] = $this->postJson($s['base'].'/items', ['name' => 'Part', 'kind' => 'stock', 'unit_label' => 'piece', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $po = $this->postJson($s['base'].'/workflows', $this->entry($s, 'purchase_order'))->assertCreated()->json('data');
        $bill = [...$this->checkout($s, $po), 'paid_now' => '0'];
        $this->postJson($s['base'].'/workflow/'.$po['id'].'/bill', [...$bill, 'business_date_bs' => 20900101])->assertUnprocessable();
        $this->assertSame(0, DB::table('documents')->count());
        $this->postJson($s['base'].'/workflow/'.$po['id'].'/bill', $bill)->assertCreated()->assertJsonPath('data.type', 'purchase');
        $this->assertSame(2000, (int) DB::table('inventory_balances')->where('item_id', $s['item'])->value('qty_milli'));
        $order = $this->postJson($s['base'].'/workflows', $this->entry($s, 'sales_order'))->assertCreated()->json('data');
        $input = [...$this->entry($s, 'sales_order'), 'version' => $order['version'], 'lines' => [['item_id' => $s['item'], 'qty' => '3', 'unit_price' => '100']], 'invoice_discount' => '0', 'expected_total_paisa' => '30000'];
        $order = $this->patchJson($s['base'].'/workflow/'.$order['id'], $input)->assertCreated()->json('data');
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/bill', [...$this->checkout($s, $order), 'expected_total_paisa' => '30000', 'paid_now' => '300'])->assertUnprocessable();
        $this->assertSame(1, DB::table('documents')->count());
        $this->getJson($s['base'].'/workflow/'.$order['id'])->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.document_id', null);
    }

    public function test_tenant_and_cashier_ownership_apply_to_reads_mutations_and_replay(): void
    {
        $s = $this->shop();
        $input = $this->entry($s);
        $quote = $this->postJson($s['base'].'/workflows', $input)->assertCreated()->json('data');
        $other = $this->shop();
        $this->getJson($other['base'].'/workflow/'.$quote['id'])->assertNotFound();
        $this->postJson($other['base'].'/workflows', $input)->assertNotFound();
        $this->actingAs($s['owner'], 'tenant');
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->getJson($s['base'].'/workflows')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($s['base'].'/workflow/'.$quote['id'])->assertForbidden();
        $this->postJson($s['base'].'/workflows', $this->entry($s, 'purchase_order'))->assertForbidden();
        $ownInput = $this->entry($s);
        $own = $this->postJson($s['base'].'/workflows', $ownInput)->assertCreated()->json('data');
        $this->postJson($s['base'].'/workflows', $ownInput)->assertOk()->assertJsonPath('data.id',$own['id']);
        $this->postJson($s['base'].'/workflows',[...$ownInput, 'title' => 'Changed'])->assertConflict();
        DB::table('tenant_user')->where('user_id',$cashier->id)->update(['active' => false]);
        $this->postJson($s['base'].'/workflows',$ownInput)->assertNotFound();
    }
}
