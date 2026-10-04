<?php

namespace Tests\Feature;

use App\Models\User;
use App\NepaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartyTradingTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Trading shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$tenant['slug'];
        $cash = $this->getJson($url.'/lookup')->json('data.accounts.0.id');
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $party = $this->postJson($url.'/contacts', ['name' => 'Buyer and supplier', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($url.'/items', ['name' => 'Work', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '100'])->assertCreated()->json('data.id');

        return compact('owner', 'tenant', 'url', 'cash', 'party', 'item');
    }

    private function policy(array $s, ?string $limit = '100'): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'credit_limit' => $limit, 'sales_terms_days' => 10, 'purchase_terms_days' => 20];
    }

    private function sale(array $s, string $paid = '0', int $date = 20830103): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $s['party'], 'business_date_bs' => $date, 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '100']], 'paid_now' => $paid, 'money_account_id' => $s['cash'], 'expected_total_paisa' => '10000'];
    }

    public function test_terms_limits_partial_payment_and_draft_cannot_bypass_shared_posting(): void
    {
        $s = $this->shop();
        $policy = $this->policy($s);
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/trading', $policy)->assertCreated()->assertJsonPath('data.credit_limit_paisa', '10000');
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/trading', $policy)->assertOk();
        $sale = $this->postJson($s['url'].'/documents/sale', $this->sale($s))->assertCreated()->assertJsonPath('data.due_date_bs', 20830113)->json('data');
        $this->postJson($s['url'].'/documents/sale', $this->sale($s))->assertUnprocessable()->assertJsonValidationErrors('paid_now');
        $this->assertSame(1, DB::table('documents')->count());
        $this->postJson($s['url'].'/documents/sale', $this->sale($s, '100'))->assertCreated();
        $draft = $this->postJson($s['url'].'/documents/sale/drafts', $this->sale($s))->assertCreated()->json('data');
        $this->postJson($s['url'].'/document/'.$draft['id'].'/post', ['mutation_uuid' => (string) Str::uuid(), 'version' => $draft['version'], 'paid_now' => '99', 'money_account_id' => $s['cash'], 'expected_total_paisa' => '10000'])->assertUnprocessable();
        $this->getJson($s['url'].'/document/'.$draft['id'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson($s['url'].'/payments', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'receipt', 'contact_id' => $s['party'], 'business_date_bs' => 20830104, 'amount' => '50', 'money_account_id' => $s['cash'], 'allocations' => [['document_id' => $sale['id'], 'amount' => '50']]])->assertCreated();
        $this->postJson($s['url'].'/documents/sale', $this->sale($s, '50', 20830105))->assertCreated();
        $this->postJson($s['url'].'/documents/sale', $this->sale($s, '50', 20830103))->assertUnprocessable();
        $this->assertSame(3, DB::table('documents')->where('status', 'posted')->count());
        $this->assertSame(20830401, NepaliDate::addDays(20830332, 1));
        $this->assertSame(20830101, NepaliDate::addDays(20830101, 0));
        $this->postJson($s['url'].'/documents/purchase', $this->sale($s))->assertCreated()->assertJsonPath('data.due_date_bs', 20830123);
        $this->postJson($s['url'].'/documents/sale', $this->sale($s, '0', 20830106))->assertUnprocessable();
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/trading', [...$policy, 'mutation_uuid' => (string) Str::uuid(), 'version' => 2, 'credit_limit' => '0'])->assertCreated();
        $this->postJson($s['url'].'/documents/sale', $this->sale($s, '99', 20830106))->assertUnprocessable();
        $this->postJson($s['url'].'/documents/sale', [...$this->sale($s, '100', 20830106), 'due_date_bs' => 20830120])->assertCreated()->assertJsonPath('data.due_date_bs', 20830120);
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/trading', [...$policy, 'mutation_uuid' => (string) Str::uuid(), 'version' => 3, 'credit_limit' => null])->assertCreated()->assertJsonPath('data.credit_limit_paisa', null);
        $this->postJson($s['url'].'/documents/sale', $this->sale($s, '0', 20830106))->assertCreated();
    }

    public function test_party_rates_scope_pos_measurements_and_frozen_quotes(): void
    {
        $s = $this->shop();
        $rate = ['mutation_uuid' => (string) Str::uuid(), 'item_id' => $s['item'], 'channel' => 'sale', 'price' => '80.25', 'enabled' => true];
        $row = $this->postJson($s['url'].'/contacts/'.$s['party'].'/rates', $rate)->assertCreated()->json('data');
        $this->postJson($s['url'].'/contacts/'.$s['party'].'/rates', $rate)->assertOk()->assertJsonPath('data.id', $row['id']);
        $this->getJson($s['url'].'/lookup?q=Work&contact_id='.$s['party'].'&price_channel=sale')->assertOk()->assertJsonPath('data.items.0.suggested_price_paisa', '8025');
        $pos = ['contact_id' => $s['party'], 'lines' => [['item_id' => $s['item'], 'measurement' => ['mode' => 'amount', 'value' => '160.50']]]];
        $this->postJson($s['url'].'/pos/preview', $pos)->assertOk()->assertJsonPath('data.lines.0.qty_milli', '2000')->assertJsonPath('data.total_paisa', '16050');
        $this->postJson($s['url'].'/pos/sales', [...$pos, 'mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830103, 'expected_total_paisa' => '16050', 'paid_now' => '160.50', 'money_account_id' => $s['cash']])->assertCreated();
        $quote = $this->postJson($s['url'].'/workflows', [...$this->sale($s), 'kind' => 'quote', 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '80.25']], 'expected_total_paisa' => '8025'])->assertCreated()->json('data');
        foreach (['sent', 'accepted'] as $status) {
            $quote = $this->postJson($s['url'].'/workflow/'.$quote['id'].'/status', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'status' => $status])->assertCreated()->json('data');
        }
        $this->postJson($s['url'].'/contacts/'.$s['party'].'/rates', [...$rate, 'mutation_uuid' => (string) Str::uuid(), 'version' => $row['version'], 'price' => '60'])->assertCreated();
        $this->postJson($s['url'].'/workflow/'.$quote['id'].'/bill', ['mutation_uuid' => (string) Str::uuid(), 'version' => $quote['version'], 'business_date_bs' => 20830103, 'expected_total_paisa' => '8025', 'paid_now' => '80.25', 'money_account_id' => $s['cash']])->assertCreated()->assertJsonPath('data.total_paisa', '8025');
        $other = $this->shop();
        $this->getJson($other['url'].'/lookup?contact_id='.$s['party'])->assertNotFound();
        $this->postJson($other['url'].'/contacts/'.$other['party'].'/rates', $rate)->assertNotFound();
        $this->actingAs($s['owner'], 'tenant');
        DB::table('items')->where('id', $s['item'])->update(['unit_label' => 'changed']);
        $this->getJson($s['url'].'/lookup?contact_id='.$s['party'])->assertUnprocessable();
    }

    public function test_followup_history_assignment_roles_and_collection_balances(): void
    {
        $s = $this->shop();
        $bill = $this->postJson($s['url'].'/documents/sale', $this->sale($s))->assertCreated()->json('data');
        $task = ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $s['party'], 'document_id' => $bill['id'], 'title' => 'Collect balance', 'due_date_bs' => 20830105, 'channel' => 'phone', 'assigned_to' => $s['owner']->id, 'notes' => 'Customer requested call'];
        $row = $this->postJson($s['url'].'/followups', $task)->assertCreated()->json('data');
        $this->postJson($s['url'].'/followups', $task)->assertOk()->assertJsonPath('data.id', $row['id']);
        $this->patchJson($s['url'].'/followups/'.$row['id'], ['mutation_uuid' => (string) Str::uuid(), 'version' => $row['version'], 'status' => 'done', 'notes' => 'Call completed; will pay tomorrow'])->assertCreated();
        $this->getJson($s['url'].'/followups/'.$row['id'])->assertOk()->assertJsonCount(2, 'history.data')->assertJsonPath('data.status', 'done');
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->getJson($s['url'].'/collections?channel=receivables')->assertOk()->assertJsonPath('data.0.due_paisa', '10000');
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->postJson($s['url'].'/contacts', ['name' => 'Cashier created party', 'is_customer' => true, 'is_supplier' => false])->assertCreated()->assertJsonMissingPath('data.credit_limit_paisa')->assertJsonMissingPath('data.trading_version');
        $this->postJson($s['url'].'/documents/sale', $this->sale($s, '100'))->assertCreated()->assertJsonMissingPath('data.party_snapshot.credit_limit_paisa')->assertJsonMissingPath('data.party_snapshot.trading_version');
        $this->getJson($s['url'].'/documents/sale')->assertOk()->assertJsonMissingPath('data.0.party_snapshot.credit_limit_paisa');
        $this->postJson($s['url'].'/workflows', [...$this->sale($s), 'kind' => 'quote'])->assertCreated()->assertJsonMissingPath('data.party_snapshot.credit_limit_paisa');
        $this->getJson($s['url'].'/collections')->assertForbidden();
        $this->getJson($s['url'].'/followups')->assertForbidden();
        $this->patchJson($s['url'].'/contacts/'.$s['party'].'/trading', $this->policy($s))->assertForbidden();
        $this->postJson($s['url'].'/followups', $task)->assertForbidden();
        $this->getJson($s['url'].'/lookup?contact_id='.$s['party'].'&price_channel=purchase')->assertForbidden();
        $this->actingAs($s['owner'], 'tenant');
        $this->postJson($s['url'].'/followups', [...$task, 'mutation_uuid' => (string) Str::uuid(), 'assigned_to' => $cashier->id])->assertUnprocessable();
        $other = $this->shop();
        $this->getJson($other['url'].'/followups/'.$row['id'])->assertNotFound();
        $this->postJson($other['url'].'/followups', [...$task, 'contact_id' => $other['party'], 'assigned_to' => $other['owner']->id])->assertNotFound();
    }
}
