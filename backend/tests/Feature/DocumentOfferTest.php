<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentOfferTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Manual offer shop'])->assertCreated()->json('data');
        $base = '/api/app/'.$tenant['slug'];
        DB::table('tenants')->where('id', $tenant['id'])->update(['tax_recording_enabled' => true]);
        $party = $this->postJson($base.'/contacts', ['name' => 'Buyer/vendor', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Stock kg', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [['item_id' => $item, 'qty' => '10', 'value' => '500', 'zero_cost' => false]], 'parties' => []])->assertCreated();
        $offer = $this->postJson($base.'/basket-offers', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Save ten', 'offer_kind' => 'basket', 'discount_mode' => 'fixed', 'discount_value' => '10', 'minimum_spend' => '0', 'enabled' => true, 'cashier_allowed' => true])->assertCreated()->json('data.id');

        return compact('owner', 'tenant', 'base', 'party', 'item', 'offer');
    }

    private function entry(array $s): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'contact_id' => $s['party'], 'basket_offer_id' => $s['offer'], 'lines' => [['item_id' => $s['item'], 'qty' => '2.501', 'unit_price' => '100', 'discount' => '5', 'tax_category' => 'standard', 'tax_bps' => 1300]], 'invoice_discount' => '0', 'paid_now' => '0', 'expected_total_paisa' => '26566'];
    }

    private function reviewed(array $s, array $input, string $suffix = 'documents/sale/preview'): array
    {
        $preview = $this->postJson($s['base'].'/'.$suffix, $input)->assertOk()->json('data');

        return [...$input, 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']];
    }

    public function test_manual_offer_requires_current_review_and_preserves_stock_tax_return_and_replay(): void
    {
        $s = $this->shop();
        $entry = $this->entry($s);
        $this->postJson($s['base'].'/documents/sale', $entry)->assertUnprocessable()->assertJsonValidationErrors('expected_fingerprint');
        $input = $this->reviewed($s, $entry);
        $changed = $input;
        $changed['lines'][0]['qty'] = '2.500';
        $this->postJson($s['base'].'/documents/sale', $changed)->assertConflict();
        $this->assertDatabaseCount('documents', 0);
        $doc = $this->postJson($s['base'].'/documents/sale', $input)->assertCreated()->assertJsonPath('data.total_paisa', '26566')->assertJsonPath('data.tax_paisa', '3056')->assertJsonPath('data.lines.0.line_discount_paisa', '500')->assertJsonPath('data.invoice_discount_paisa', '1000')->assertJsonPath('data.basket_offer_snapshot.discount_paisa', '1000')->json('data');
        DB::table('basket_offers')->where('id', $s['offer'])->update(['enabled' => false]);
        $this->postJson($s['base'].'/documents/sale', $input)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 7499]);
        $this->postJson($s['base'].'/document/'.$doc['id'].'/returns', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Original discounted return', 'lines' => [['source_line_id' => $doc['lines'][0]['id'], 'qty' => '2.501']]])->assertCreated()->assertJsonPath('data.total_paisa', '26566');
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000, 'value_paisa' => 50000]);
    }

    public function test_draft_rechecks_same_total_terms_and_preserves_pre_offer_edit_values(): void
    {
        $s = $this->shop();
        $input = $this->reviewed($s, $this->entry($s));
        $doc = $this->postJson($s['base'].'/documents/sale/drafts', $input)->assertCreated()->assertJsonPath('data.draft_input.lines.0.discount', '5')->json('data');
        $path = 'document/'.$doc['id'];
        $proof = $this->postJson($s['base'].'/'.$path.'/post/preview', ['version' => 1])->assertOk()->json('data');
        $this->assertNotSame($input['expected_fingerprint'], $proof['fingerprint']);
        DB::table('basket_offers')->where('id', $s['offer'])->update(['name' => 'Same total new terms', 'version' => 2]);
        $this->postJson($s['base'].'/'.$path.'/post/preview', ['version' => 1])->assertConflict();
        $post = ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'paid_now' => '0', 'expected_total_paisa' => '26566', 'expected_fingerprint' => $proof['fingerprint']];
        $this->postJson($s['base'].'/'.$path.'/post', $post)->assertConflict();
        $edit = $this->reviewed($s, [...$this->entry($s), 'version' => 1], $path.'/draft/preview');
        $this->patchJson($s['base'].'/'.$path.'/draft', $edit)->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.basket_offer_snapshot.name', 'Same total new terms')->assertJsonPath('data.lines.0.line_discount_paisa', '500')->assertJsonPath('data.total_paisa', '26566');
        $this->postJson($s['base'].'/'.$path.'/post/preview', ['version' => 1])->assertConflict();
        $proof = $this->postJson($s['base'].'/'.$path.'/post/preview', ['version' => 2])->assertOk()->json('data');
        $post = [...$post, 'mutation_uuid' => (string) Str::uuid(), 'version' => 2];
        unset($post['expected_fingerprint']);
        $this->postJson($s['base'].'/'.$path.'/post', $post)->assertUnprocessable()->assertJsonValidationErrors('expected_fingerprint');
        $post['expected_fingerprint'] = $proof['fingerprint'];
        $this->postJson($s['base'].'/'.$path.'/post', $post)->assertCreated()->assertJsonPath('data.status', 'posted');
        DB::table('basket_offers')->where('id', $s['offer'])->update(['enabled' => false]);
        $this->postJson($s['base'].'/'.$path.'/post', $post)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->postJson($s['base'].'/'.$path.'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'reason' => 'Unpaid discounted bill cancelled'])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000]);
    }

    public function test_expired_draft_can_remove_offer_and_clone_does_not_copy_saving(): void
    {
        $s = $this->shop();
        $doc = $this->postJson($s['base'].'/documents/sale/drafts', $this->reviewed($s, $this->entry($s)))->assertCreated()->json('data');
        DB::table('basket_offers')->where('id', $s['offer'])->update(['enabled' => false]);
        $this->postJson($s['base'].'/document/'.$doc['id'].'/post/preview', ['version' => 1])->assertUnprocessable();
        $this->postJson($s['base'].'/document/'.$doc['id'].'/post', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'paid_now' => '0', 'expected_total_paisa' => '26566', 'expected_fingerprint' => str_repeat('a', 64)])->assertUnprocessable();
        $this->postJson($s['base'].'/document/'.$doc['id'].'/clone', ['mutation_uuid' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.basket_offer_id', null)->assertJsonPath('data.invoice_discount_paisa', '0')->assertJsonPath('data.lines.0.line_discount_paisa', '500')->assertJsonPath('data.total_paisa', '27696');
        $this->patchJson($s['base'].'/document/'.$doc['id'].'/draft', [...$this->entry($s), 'version' => 1, 'basket_offer_id' => null, 'expected_total_paisa' => '27696'])->assertCreated()->assertJsonPath('data.basket_offer_snapshot', null)->assertJsonPath('data.total_paisa', '27696');
        $this->assertDatabaseHas('inventory_balances', ['item_id' => $s['item'], 'qty_milli' => 10000]);
    }

    public function test_accepted_quote_order_freezes_reviewed_offer_on_conversion(): void
    {
        $s = $this->shop();
        $input = [...$this->entry($s), 'kind' => 'quote', 'valid_until_bs' => 20900101, 'title' => 'Saved offer quote'];
        $this->postJson($s['base'].'/workflows', $input)->assertUnprocessable()->assertJsonValidationErrors('expected_fingerprint');
        $quote = $this->postJson($s['base'].'/workflows', $this->reviewed($s, $input, 'workflows/preview'))->assertCreated()->assertJsonPath('data.bill_input.basket_offer_snapshot.name', 'Save ten')->assertJsonPath('data.bill_input.offer_input.lines.0.discount', '5')->json('data');
        foreach (['sent', 'accepted'] as $status) {
            $quote = $this->postJson($s['base'].'/workflow/'.$quote['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'status' => $status])->assertCreated()->json('data');
        }
        $this->postJson($s['base'].'/workflow/'.$quote['id'].'/preview', [...$input, 'version' => $quote['version']])->assertConflict();
        DB::table('basket_offers')->where('id', $s['offer'])->update(['enabled' => false, 'discount_value' => 2000, 'version' => 2]);
        DB::table('items')->where('id', $s['item'])->update(['sale_price_paisa' => 50000]);
        $order = $this->postJson($s['base'].'/workflow/'.$quote['id'].'/order', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version']])->assertCreated()->assertJsonPath('data.bill_input.basket_offer_snapshot.discount_paisa', '1000')->json('data');
        $bill = ['mutation_uuid' => (string) Str::uuid(), 'version' => $order['version'], 'business_date_bs' => 20830103, 'paid_now' => '0', 'expected_total_paisa' => '26566', 'basket_offer_snapshot' => ['discount_paisa' => '1'], 'lines' => []];
        $doc = $this->postJson($s['base'].'/workflow/'.$order['id'].'/bill', $bill)->assertCreated()->assertJsonPath('data.total_paisa', '26566')->assertJsonPath('data.lines.0.unit_price_paisa', '10000')->assertJsonPath('data.basket_offer_snapshot.discount_paisa', '1000')->assertJsonPath('data.basket_offer_snapshot.workflow_source.id', (string) $order['id'])->json('data');
        $this->postJson($s['base'].'/workflow/'.$order['id'].'/bill', $bill)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_offer_preview_enforces_tenant_and_cashier_ownership_and_permission(): void
    {
        $s = $this->shop();
        $draft = $this->postJson($s['base'].'/documents/sale/drafts', $this->reviewed($s, $this->entry($s)))->assertCreated()->json('data');
        $input = [...$this->entry($s), 'kind' => 'quote'];
        $quote = $this->postJson($s['base'].'/workflows', $this->reviewed($s, $input, 'workflows/preview'))->assertCreated()->json('data');
        $foreign = $this->shop();
        $foreignDraft = $this->postJson($foreign['base'].'/documents/sale/drafts', $this->reviewed($foreign, $this->entry($foreign)))->assertCreated()->json('data');
        $this->actingAs($s['owner'], 'tenant');
        $this->postJson($s['base'].'/documents/sale/preview', [...$this->entry($s), 'basket_offer_id' => $foreign['offer']])->assertNotFound();
        $this->postJson($s['base'].'/document/'.$foreignDraft['id'].'/post/preview', ['version' => 1])->assertNotFound();
        $this->postJson($s['base'].'/document/'.$foreignDraft['id'].'/draft/preview', [...$this->entry($s), 'version' => 1])->assertNotFound();
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->postJson($s['base'].'/document/'.$draft['id'].'/post/preview', ['version' => 1])->assertForbidden();
        $this->postJson($s['base'].'/document/'.$draft['id'].'/draft/preview', [...$this->entry($s), 'version' => 1])->assertForbidden();
        $this->postJson($s['base'].'/workflow/'.$quote['id'].'/preview', [...$input, 'version' => 1])->assertForbidden();
        DB::table('basket_offers')->where('id', $s['offer'])->update(['cashier_allowed' => false]);
        $this->postJson($s['base'].'/documents/sale/preview', $this->entry($s))->assertForbidden();
    }

    public function test_offer_rejects_bill_discount_stacking_and_non_sale_paths(): void
    {
        $s = $this->shop();
        foreach ([['invoice_discount' => '1'], ['invoice_discount_bps' => 1]] as $discount) {
            $this->postJson($s['base'].'/documents/sale/preview', [...$this->entry($s), ...$discount])->assertUnprocessable();
        }
        foreach (['purchase', 'expense'] as $type) {
            $this->postJson($s['base'].'/documents/'.$type.'/preview', $this->entry($s))->assertUnprocessable();
            $this->postJson($s['base'].'/documents/'.$type.'/drafts', $this->entry($s))->assertUnprocessable();
        }
        $this->postJson($s['base'].'/workflows/preview', [...$this->entry($s), 'kind' => 'purchase_order'])->assertUnprocessable();
        $this->assertDatabaseCount('documents', 0);
    }
}
