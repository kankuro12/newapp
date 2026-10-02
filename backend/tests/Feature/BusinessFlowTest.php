<?php

namespace Tests\Feature;

use App\Models\SuperAdmin;
use App\Models\User;
use App\NepaliDate;
use App\Support\Money;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BusinessFlowTest extends TestCase
{
    use RefreshDatabase;

    private function postAction(string $path, array $input): TestResponse
    {
        return $this->postJson($path, ['mutation_uuid' => (string) Str::uuid(), ...$input]);
    }

    private function business(): string
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $slug = $this->postJson('/api/businesses', ['name' => 'Test shop'])->assertCreated()->json('data.slug');

        return '/api/app/'.$slug;
    }

    public function test_purchase_sale_expense_reconcile_and_retry_once(): void
    {
        $url = $this->business();
        $lookup = $this->getJson($url.'/lookup')->assertOk()->json('data');
        $cash = $lookup['accounts'][0]['id'];
        $date = NepaliDate::today();
        $this->postAction($url.'/settings/opening-balances/finalize', ['business_date_bs' => $date, 'money' => [['account_id' => $cash, 'amount' => '5000']], 'stock' => [], 'parties' => []])->assertCreated();
        $party = $this->postJson($url.'/contacts', ['name' => 'Both party', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($url.'/items', ['name' => 'Rice', 'kind' => 'stock', 'unit_label' => 'kg', 'sale_price' => '150'])->assertCreated()->json('data.id');
        $purchase = $this->postAction($url.'/documents/purchase', ['contact_id' => $party, 'business_date_bs' => $date, 'lines' => [['item_id' => $item, 'qty' => '10', 'unit_price' => '100']], 'paid_now' => '600', 'money_account_id' => $cash, 'expected_total_paisa' => '100000'])->assertCreated()->json('data');
        $saleInput = ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $party, 'business_date_bs' => $date, 'lines' => [['item_id' => $item, 'qty' => '3', 'unit_price' => '150']], 'paid_now' => '200', 'money_account_id' => $cash, 'expected_total_paisa' => '45000'];
        $sale = $this->postJson($url.'/documents/sale', $saleInput)->assertCreated()->json('data');
        $this->postJson($url.'/documents/sale', $saleInput)->assertOk()->assertJsonPath('data.id', $sale['id']);
        $this->postJson($url.'/documents/sale', [...$saleInput, 'paid_now' => '201'])->assertConflict();
        $this->postAction($url.'/documents/expense', ['business_date_bs' => $date, 'lines' => [['expense_category_id' => $lookup['categories'][0]['id'], 'qty' => '1', 'unit_price' => '50']], 'paid_now' => '50', 'money_account_id' => $cash, 'expected_total_paisa' => '5000'])->assertCreated();
        $report = $this->getJson($url.'/reports/overview')->assertOk()->json('data');
        $this->assertSame('455000', $report['cash_paisa']);
        $this->assertSame('70000', $report['inventory_paisa']);
        $this->assertSame('25000', $report['receivables_paisa']);
        $this->assertSame('40000', $report['payables_paisa']);
        $this->assertSame('10000', $report['profit_paisa']);
        $this->assertSame($report['debit_paisa'], $report['credit_paisa']);
        $this->assertSame('40000', $purchase['due_paisa']);
        foreach ($this->getJson($url.'/reports/reconciliation')->assertOk()->json('data') as $row) {
            $this->assertSame('0', $row['difference_paisa']);
        }
        $this->getJson($url.'/reports/customer-aging')->assertOk()->assertJsonPath('data.0.due_paisa', '25000');
        $before = DB::table('journal_entries')->count();
        $this->postAction($url.'/documents/sale', [...$saleInput, 'mutation_uuid' => (string) Str::uuid(), 'lines' => [['item_id' => $item, 'qty' => '100', 'unit_price' => '150']], 'expected_total_paisa' => '1500000'])->assertUnprocessable();
        $this->assertSame($before, DB::table('journal_entries')->count());
    }

    public function test_tenants_and_super_admin_guards_are_isolated(): void
    {
        $url = $this->business();
        $this->getJson('/api/platform/tenants')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->getJson($url.'/items')->assertNotFound();
        auth('tenant')->logout();
        auth()->forgetGuards();
        $admin = SuperAdmin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-pass-123']);
        $this->actingAs($admin, 'superadmin');
        $this->getJson('/api/platform/tenants')->assertOk();
        $this->getJson('/api/businesses')->assertUnauthorized();
    }

    private function shop(): array
    {
        $url = $this->business();
        $owner = auth('tenant')->user();
        $tenant = DB::table('tenants')->where('slug', basename($url))->value('id');
        $cash = $this->getJson($url.'/lookup')->json('data.accounts.0.id');
        $party = $this->postJson($url.'/contacts', ['name' => 'Named party', 'is_customer' => true, 'is_supplier' => true])->assertCreated()->json('data.id');
        $item = $this->postJson($url.'/items', ['name' => 'Stock item', 'kind' => 'stock', 'unit_label' => 'kg', 'sale_price' => '150'])->assertCreated()->json('data.id');
        $date = NepaliDate::today();
        $this->postAction($url.'/settings/opening-balances/finalize', ['business_date_bs' => $date, 'money' => [['account_id' => $cash, 'amount' => '5000']], 'stock' => [], 'parties' => []])->assertCreated();

        return compact('url', 'owner', 'tenant', 'cash', 'party', 'item', 'date');
    }

    private function bill(array $s, string $type, string $qty, string $price, string $paid = '0', array $extra = []): array
    {
        return $this->postAction($s['url'].'/documents/'.$type, ['contact_id' => $s['party'], 'business_date_bs' => $s['date'], 'lines' => [['item_id' => $s['item'], 'qty' => $qty, 'unit_price' => $price]], 'paid_now' => $paid, 'money_account_id' => $s['cash'], 'expected_total_paisa' => (string) Money::multiplyDivide(Money::quantity($qty), Money::parse($price), 1000), ...$extra])->assertCreated()->json('data');
    }

    public function test_partial_returns_refunds_and_linked_cancellations_restore_exact_totals(): void
    {
        $s = $this->shop();
        $purchase = $this->bill($s, 'purchase', '3', '100');
        $sale = $this->bill($s, 'sale', '3', '150', '450');
        $returns = [];
        for ($i = 0; $i < 3; $i++) {
            $returns[] = $this->postAction($s['url'].'/document/'.$sale['id'].'/returns', ['business_date_bs' => $s['date'], 'reason' => 'Returned goods', 'lines' => [['source_line_id' => $sale['lines'][0]['id'], 'qty' => '1']], 'refund_now' => true, 'money_account_id' => $s['cash']])->assertCreated()->json('data');
        }
        $current = $this->getJson($s['url'].'/document/'.$sale['id'])->json('data');
        $this->assertSame('0', $current['credit_paisa']);
        $this->assertSame('45000', $current['returned_paisa']);
        $this->postAction($s['url'].'/document/'.$sale['id'].'/returns', ['business_date_bs' => $s['date'], 'reason' => 'Too many goods', 'lines' => [['source_line_id' => $sale['lines'][0]['id'], 'qty' => '0.001']]])->assertUnprocessable();
        $cancel = ['business_date_bs' => $s['date'], 'reason' => 'Mistaken entry'];
        $this->postAction($s['url'].'/document/'.$returns[0]['id'].'/cancel', $cancel)->assertUnprocessable();
        foreach (array_reverse($current['payments']) as $payment) {
            if ($payment['kind'] === 'customer_refund') {
                $this->postAction($s['url'].'/payments/'.$payment['id'].'/cancel', $cancel)->assertCreated();
            }
        }
        foreach (array_reverse($returns) as $return) {
            $this->postAction($s['url'].'/document/'.$return['id'].'/cancel', $cancel)->assertCreated();
        }
        $this->postAction($s['url'].'/document/'.$sale['id'].'/cancel', $cancel)->assertUnprocessable();
        $this->postAction($s['url'].'/payments/'.$sale['payments'][0]['id'].'/cancel', $cancel)->assertCreated();
        $this->postAction($s['url'].'/document/'.$sale['id'].'/cancel', $cancel)->assertCreated();
        $this->postAction($s['url'].'/document/'.$purchase['id'].'/cancel', $cancel)->assertCreated();
        $report = $this->getJson($s['url'].'/reports/overview')->json('data');
        $this->assertSame('500000', $report['cash_paisa']);
        $this->assertSame('0', $report['inventory_paisa']);
        $this->assertSame('0', $report['profit_paisa']);
        $this->assertSame($report['debit_paisa'], $report['credit_paisa']);
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('tenant_id', $s['tenant'])->sum('qty_milli'));
    }

    public function test_tax_rounding_and_three_partial_returns_drain_original_paisa(): void
    {
        $s = $this->shop();
        DB::table('tenants')->where('id', $s['tenant'])->update(['tax_recording_enabled' => true]);
        $this->bill($s, 'purchase', '3', '1');
        $sale = $this->bill($s, 'sale', '3', '1', '0', ['invoice_discount' => '0.01', 'lines' => [['item_id' => $s['item'], 'qty' => '3', 'unit_price' => '1', 'tax_category' => 'standard', 'tax_bps' => 1300]], 'expected_total_paisa' => '338']);
        $sum = 0;
        for ($i = 0; $i < 3; $i++) {
            $return = $this->postAction($s['url'].'/document/'.$sale['id'].'/returns', ['business_date_bs' => $s['date'], 'reason' => 'Return fraction', 'lines' => [['source_line_id' => $sale['lines'][0]['id'], 'qty' => '1']]])->assertCreated()->json('data');
            $sum += (int) $return['total_paisa'];
        }
        $this->assertSame(338, $sum);
        $this->assertSame('0', $this->getJson($s['url'].'/document/'.$sale['id'])->json('data.due_paisa'));
        $report = $this->getJson($s['url'].'/reports/overview')->json('data');
        $this->assertSame('300', $report['inventory_paisa']);
        $this->assertSame('0', $report['profit_paisa']);
    }

    public function test_aging_keeps_refund_credit_and_owner_history_shows_reversal(): void
    {
        $s = $this->shop();
        $this->bill($s, 'purchase', '1', '100');
        $sale = $this->bill($s, 'sale', '1', '150', '150');
        $this->postAction($s['url'].'/document/'.$sale['id'].'/returns', ['business_date_bs' => $s['date'], 'reason' => 'Customer returned goods', 'lines' => [['source_line_id' => $sale['lines'][0]['id'], 'qty' => '1']]])->assertCreated();
        $this->getJson($s['url'].'/reports/customer-aging')->assertOk()->assertJsonPath('data.0.due_paisa', '-15000')->assertJsonPath('data.0.bucket', 'refund_pending');
        $owner = $this->postAction($s['url'].'/owner-money', ['kind' => 'contribution', 'business_date_bs' => $s['date'], 'money_account_id' => $s['cash'], 'amount' => '50'])->assertCreated()->json('data.id');
        $this->getJson($s['url'].'/owner-money')->assertOk()->assertJsonPath('data.0.amount_paisa', '5000')->assertJsonPath('data.0.status', 'posted');
        $this->postAction($s['url'].'/owner-money/'.$owner.'/cancel', ['business_date_bs' => $s['date'], 'reason' => 'Mistaken contribution'])->assertCreated();
        $this->getJson($s['url'].'/owner-money')->assertOk()->assertJsonPath('data.0.status', 'cancelled');
    }

    public function test_purchase_return_uses_current_cost_and_posts_variance(): void
    {
        $s = $this->shop();
        $purchase = $this->bill($s, 'purchase', '1', '100');
        $this->bill($s, 'purchase', '1', '200');
        $this->postAction($s['url'].'/document/'.$purchase['id'].'/returns', ['business_date_bs' => $s['date'], 'reason' => 'Return first purchase', 'lines' => [['source_line_id' => $purchase['lines'][0]['id'], 'qty' => '1']]])->assertCreated();
        $report = $this->getJson($s['url'].'/reports/overview')->json('data');
        $this->assertSame('15000', $report['inventory_paisa']);
        $this->assertSame('20000', $report['payables_paisa']);
        $this->assertSame('-5000', $report['profit_paisa']);
        $this->assertSame($report['debit_paisa'], $report['credit_paisa']);
        $this->postAction($s['url'].'/document/'.$purchase['id'].'/cancel', ['business_date_bs' => $s['date'], 'reason' => 'Cannot cancel dependency'])->assertUnprocessable();
    }

    public function test_drafts_are_versioned_effect_free_editable_cloneable_and_cleaned(): void
    {
        $s = $this->shop();
        $input = ['contact_id' => $s['party'], 'business_date_bs' => $s['date'], 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '100']], 'paid_now' => '0'];
        $before = DB::table('journal_entries')->count();
        $draft = $this->postAction($s['url'].'/documents/purchase/drafts', $input)->assertCreated()->json('data');
        $this->assertNull($draft['number']);
        $this->assertSame($before, DB::table('journal_entries')->count());
        $edited = $this->patchJson($s['url'].'/document/'.$draft['id'].'/draft', [...$input, 'mutation_uuid' => (string) Str::uuid(), 'version' => $draft['version'], 'supplier_bill_number' => 'Updated reference', 'lines' => [['item_id' => $s['item'], 'qty' => '2', 'unit_price' => '100']]])->assertCreated()->json('data');
        $this->assertSame('Updated reference', $edited['supplier_bill_number']);
        $this->assertSame('20000', $edited['total_paisa']);
        $this->postAction($s['url'].'/document/'.$draft['id'].'/post', ['version' => $draft['version'], 'paid_now' => '0', 'expected_total_paisa' => '10000'])->assertConflict();
        $posted = $this->postAction($s['url'].'/document/'.$draft['id'].'/post', ['version' => $edited['version'], 'paid_now' => '0', 'expected_total_paisa' => '20000'])->assertCreated()->json('data');
        $clone = $this->postAction($s['url'].'/document/'.$posted['id'].'/clone', [])->assertCreated()->json('data');
        $this->assertSame('draft', $clone['status']);
        $this->assertNull($clone['number']);
        $this->deleteJson($s['url'].'/document/'.$clone['id'].'/draft')->assertNoContent();
        $this->assertDatabaseMissing('document_lines', ['document_id' => $clone['id']]);
        $this->deleteJson($s['url'].'/document/'.$posted['id'].'/draft')->assertConflict();
    }

    public function test_cash_and_stock_reject_overdraft_stale_counts_and_backdating(): void
    {
        $s = $this->shop();
        $bank = $this->postJson($s['url'].'/settings/accounts', ['name' => 'Cash box', 'money_kind' => 'cash'])->assertCreated()->json('data.id');
        $before = DB::table('journal_entries')->count();
        $this->postAction($s['url'].'/owner-money', ['kind' => 'withdrawal', 'business_date_bs' => $s['date'], 'money_account_id' => $bank, 'amount' => '1', 'overdraft_confirmed' => true])->assertUnprocessable();
        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->bill($s, 'purchase', '2', '100');
        $this->postAction($s['url'].'/stock-adjustments', ['business_date_bs' => $s['date'], 'item_id' => $s['item'], 'counted_qty' => '1', 'expected_qty_milli' => '0', 'reason' => 'Old stock count'])->assertConflict();
        $today = NepaliDate::today();
        $yesterday = NepaliDate::fromAd((new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kathmandu')))->modify('-1 day')->format('Y-m-d'));
        $this->postAction($s['url'].'/documents/sale', ['business_date_bs' => $yesterday, 'contact_id' => $s['party'], 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '100']], 'expected_total_paisa' => '10000'])->assertUnprocessable();
        $this->getJson($s['url'].'/reports/overview?to[]=invalid')->assertUnprocessable();
        $this->assertSame($today, $s['date']);
    }

    public function test_role_revocation_and_expiry_apply_before_cached_dashboard(): void
    {
        $s = $this->shop();
        $first = $this->getJson($s['url'])->assertOk()->json('data.cash_paisa');
        $this->assertSame('500000', $first);
        $this->postAction($s['url'].'/owner-money', ['kind' => 'contribution', 'business_date_bs' => $s['date'], 'money_account_id' => $s['cash'], 'amount' => '1'])->assertCreated();
        $this->getJson($s['url'])->assertJsonPath('data.cash_paisa', '500100');
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['tenant'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        auth()->forgetGuards();
        $this->actingAs($cashier, 'tenant');
        $home = $this->getJson($s['url'])->assertOk()->json('data');
        $this->assertArrayNotHasKey('cash_paisa', $home);
        $this->getJson($s['url'].'/reports/overview')->assertForbidden();
        $lookup = $this->getJson($s['url'].'/lookup')->json('data');
        $this->assertArrayNotHasKey('last_purchase_price_paisa', $lookup['items'][0]);
        DB::table('tenant_user')->where('tenant_id', $s['tenant'])->where('user_id', $cashier->id)->update(['active' => false]);
        $this->getJson($s['url'])->assertNotFound();
        auth()->forgetGuards();
        $this->actingAs($s['owner'], 'tenant');
        DB::table('tenants')->where('id', $s['tenant'])->update(['trial_ends_at' => now()->subMinute()]);
        $this->getJson($s['url'].'/reports/overview')->assertOk();
        $this->postAction($s['url'].'/owner-money', ['kind' => 'contribution', 'business_date_bs' => $s['date'], 'money_account_id' => $s['cash'], 'amount' => '1'])->assertForbidden();
        DB::table('tenants')->where('id', $s['tenant'])->update(['access_status' => 'suspended']);
        $this->getJson($s['url'])->assertForbidden();
    }

    public function test_replay_rechecks_current_role_before_returning_financial_result(): void
    {
        $s = $this->shop();
        $owner = ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'contribution', 'business_date_bs' => $s['date'], 'money_account_id' => $s['cash'], 'amount' => '5'];
        $count = ['mutation_uuid' => (string) Str::uuid(), 'item_id' => $s['item'], 'business_date_bs' => $s['date'], 'expected_qty_milli' => '0', 'counted_qty' => '1', 'unit_cost' => '1', 'reason' => 'Found original stock'];
        $this->postJson($s['url'].'/owner-money', $owner)->assertCreated();
        $this->postJson($s['url'].'/stock-adjustments', $count)->assertCreated();
        DB::table('tenant_user')->where('tenant_id', $s['tenant'])->where('user_id', $s['owner']->id)->update(['role' => 'cashier']);
        $this->postJson($s['url'].'/owner-money', $owner)->assertForbidden();
        DB::table('tenant_user')->where('tenant_id', $s['tenant'])->where('user_id', $s['owner']->id)->update(['role' => 'accountant']);
        $this->postJson($s['url'].'/stock-adjustments', $count)->assertForbidden();
        DB::table('tenant_user')->where('tenant_id', $s['tenant'])->where('user_id', $s['owner']->id)->update(['role' => 'cashier']);
        $this->bill($s, 'sale', '1', '150');
        $receipt = ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'receipt', 'business_date_bs' => $s['date'], 'contact_id' => $s['party'], 'money_account_id' => $s['cash'], 'amount' => '5'];
        $this->postJson($s['url'].'/payments', $receipt)->assertCreated();
        $this->postJson($s['url'].'/payments', $receipt)->assertOk();
        DB::table('tenant_user')->where('tenant_id', $s['tenant'])->where('user_id', $s['owner']->id)->update(['role' => 'owner']);
        $advance = [...$receipt, 'mutation_uuid' => (string) Str::uuid(), 'allocations' => [], 'unallocated_confirmed' => true];
        $this->postJson($s['url'].'/payments', $advance)->assertCreated();
        DB::table('tenant_user')->where('tenant_id', $s['tenant'])->where('user_id', $s['owner']->id)->update(['role' => 'cashier']);
        $this->postJson($s['url'].'/payments', $advance)->assertForbidden();
    }

    public function test_invitation_cannot_remove_last_owner_and_invalid_dates_are_validation_errors(): void
    {
        $s = $this->shop();
        $token = Str::random(64);
        DB::table('invitations')->insert(['tenant_id' => $s['tenant'], 'email' => $s['owner']->email, 'role' => 'cashier', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'invited_by' => $s['owner']->id]);
        $this->postJson('/api/invitations/'.$token)->assertUnprocessable();
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $s['tenant'], 'user_id' => $s['owner']->id, 'role' => 'owner']);
        $this->postAction($s['url'].'/documents/purchase/drafts', ['business_date_bs' => $s['date'], 'due_date_bs' => [], 'contact_id' => $s['party'], 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '1']]])->assertUnprocessable();
    }

    public function test_registration_verification_disabled_login_and_admin_injection(): void
    {
        $input = ['name' => 'New owner', 'email' => 'new-owner@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123'];
        $this->postJson('/register', [...$input, 'is_platform_admin' => true])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $input['email']]);
        $this->postJson('/register', $input)->assertCreated();
        $this->getJson('/api/me')->assertOk();
        $this->getJson('/api/businesses')->assertForbidden();
        $this->getJson('/api/platform/me')->assertUnauthorized();
        $this->postJson('/logout')->assertNoContent();
        auth()->forgetGuards();
        DB::table('users')->where('email', $input['email'])->update(['disabled_at' => now()]);
        $this->postJson('/login', ['email' => $input['email'], 'password' => $input['password']])->assertUnprocessable();
    }

    public function test_lock_reconciliation_reversals_private_files_and_csv_safety(): void
    {
        Storage::fake('local');
        $s = $this->shop();
        $purchase = $this->bill($s, 'purchase', '1', '100');
        $this->post($s['url'].'/attachments', ['document_id' => $purchase['id'], 'file' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")], ['Accept' => 'application/json'])->assertCreated();
        $file = DB::table('attachments')->first();
        $this->get($s['url'].'/attachments/'.$file->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post($s['url'].'/attachments', ['document_id' => $purchase['id'], 'file' => UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postJson($s['url'].'/contacts', ['name' => '=HYPERLINK("bad")', 'is_customer' => true, 'is_supplier' => false])->assertCreated();
        $csv = $this->get($s['url'].'/reports/receivables/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->postAction($s['url'].'/settings/close-through', ['business_date_bs' => $s['date'], 'reason' => 'Finished period', 'password' => 'wrong'])->assertForbidden();
        $this->postAction($s['url'].'/settings/close-through', ['business_date_bs' => $s['date'], 'reason' => 'Finished period', 'password' => 'password'])->assertCreated();
        $this->postAction($s['url'].'/document/'.$purchase['id'].'/cancel', ['business_date_bs' => $s['date'], 'reason' => 'Locked reversal'])->assertUnprocessable();
        $this->postAction($s['url'].'/owner-money', ['kind' => 'contribution', 'business_date_bs' => $s['date'], 'money_account_id' => $s['cash'], 'amount' => '1'])->assertUnprocessable();
        $this->getJson($s['url'].'/reports/stock')->assertJsonPath('data.0.value_paisa', '10000');
        auth()->forgetGuards();
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->get($s['url'].'/attachments/'.$file->id, ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_stock_gain_loss_owner_money_transfer_and_zero_opening(): void
    {
        $url = $this->business();
        $date = NepaliDate::today();
        $this->postAction($url.'/settings/opening-balances/finalize', ['business_date_bs' => $date, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $lookup = $this->getJson($url.'/lookup')->json('data');
        [$cash,$bank] = $lookup['accounts'];
        $this->postAction($url.'/owner-money', ['kind' => 'contribution', 'business_date_bs' => $date, 'money_account_id' => $cash['id'], 'amount' => '100'])->assertCreated();
        $this->postAction($url.'/transfers', ['kind' => 'transfer', 'business_date_bs' => $date, 'money_account_id' => $cash['id'], 'destination_account_id' => $bank['id'], 'amount' => '50'])->assertCreated();
        $item = $this->postJson($url.'/items', ['name' => 'Counted item', 'kind' => 'stock', 'unit_label' => 'unit', 'sale_price' => '10'])->assertCreated()->json('data.id');
        $count = $this->postAction($url.'/stock-adjustments', ['item_id' => $item, 'business_date_bs' => $date, 'expected_qty_milli' => '0', 'counted_qty' => '3', 'unit_cost' => '10', 'reason' => 'Found old stock'])->assertCreated()->json('data');
        $this->getJson($url.'/reports/overview')->assertJsonPath('data.profit_paisa', '3000')->assertJsonPath('data.cash_paisa', '10000');
        $this->postAction($url.'/stock-adjustments/'.$count['id'].'/cancel', ['business_date_bs' => $date, 'reason' => 'Mistaken count'])->assertCreated();
        $this->getJson($url.'/reports/overview')->assertJsonPath('data.profit_paisa', '0')->assertJsonPath('data.inventory_paisa', '0');
    }

    public function test_backdated_cash_cannot_make_later_daily_balance_negative(): void
    {
        $url = $this->business();
        $cash = $this->getJson($url.'/lookup')->json('data.accounts.0.id');
        $this->postAction($url.'/settings/opening-balances/finalize', ['business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        foreach ([['contribution', 20830101], ['withdrawal', 20830102], ['contribution', 20830103]] as [$kind,$date]) {
            $this->postAction($url.'/owner-money', ['kind' => $kind, 'business_date_bs' => $date, 'money_account_id' => $cash, 'amount' => '100'])->assertCreated();
        }
        $this->postAction($url.'/owner-money', ['kind' => 'withdrawal', 'business_date_bs' => 20830101, 'money_account_id' => $cash, 'amount' => '50'])->assertUnprocessable();
        $this->getJson($url.'/reports/overview')->assertJsonPath('data.cash_paisa', '10000');
    }

    public function test_expired_cache_is_removed_and_password_reset_stays_tenant_only(): void
    {
        config(['cache.default' => 'database']);
        DB::table('cache')->insert([['key' => 'expired-check', 'value' => serialize('old'), 'expiration' => time() - 1], ['key' => 'live-check', 'value' => serialize('new'), 'expiration' => time() + 60]]);
        $this->artisan('app:clean-cache')->assertSuccessful();
        $this->assertDatabaseMissing('cache', ['key' => 'expired-check']);
        $this->assertDatabaseHas('cache', ['key' => 'live-check']);
        Notification::fake();
        $user = User::factory()->create();
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notice) use (&$token) {
            $token = $notice->token;

            return true;
        });
        $this->postJson('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'changed-pass-123', 'password_confirmation' => 'changed-pass-123'])->assertOk();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'changed-pass-123'])->assertOk();
        $this->getJson('/api/me')->assertOk();
        $this->getJson('/api/platform/me')->assertUnauthorized();
    }

    public function test_bank_overdraft_requires_confirmation_is_audited_and_is_a_liability(): void
    {
        $s = $this->shop();
        $lookup = $this->getJson($s['url'].'/lookup')->json('data');
        $input = ['business_date_bs' => $s['date'], 'lines' => [['expense_category_id' => $lookup['categories'][0]['id'], 'qty' => '1', 'unit_price' => '50']], 'paid_now' => '50', 'money_account_id' => $lookup['accounts'][1]['id'], 'expected_total_paisa' => '5000'];
        $this->postAction($s['url'].'/documents/expense', $input)->assertUnprocessable();
        $this->postAction($s['url'].'/documents/expense', [...$input, 'overdraft_confirmed' => true])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $s['tenant'], 'action' => 'bank.overdraft']);
        $this->getJson($s['url'].'/reports/balance-sheet')->assertJsonPath('data.bank_overdrafts_paisa', '5000')->assertJsonPath('data.assets_paisa', '500000')->assertJsonPath('data.liabilities_paisa', '5000')->assertJsonPath('data.equity_paisa', '495000');
    }
}
