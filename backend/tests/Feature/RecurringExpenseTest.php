<?php

namespace Tests\Feature;

use App\Models\User;
use App\NepaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecurringExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Regular payments shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$business['slug'];
        $cash = $this->getJson($url.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [['account_id' => $cash, 'amount' => '100000']], 'stock' => [], 'parties' => []])->assertCreated();

        return compact('url', 'cash', 'owner', 'business');
    }

    private function rule(array $s, array $extra = []): array
    {
        return $this->postJson($s['url'].'/regular-expenses', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'salary', 'label' => 'Monthly salary', 'payee_name' => 'Ram', 'amount' => '20000', 'first_date_bs' => 20830115, 'monthly_day' => 15, 'auto_generate' => true, 'enabled' => true, ...$extra])->assertCreated()->json('data');
    }

    private function record(array $s, array $rule, array $extra = []): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'period_bs' => 20830101, 'business_date_bs' => 20830115, 'expected_total_paisa' => $rule['amount_paisa'], 'paid_now' => '0', ...$extra];
    }

    public function test_salary_accrual_partial_payment_retry_and_reversal(): void
    {
        $s = $this->shop();
        $rule = $this->rule($s);
        $party = $this->getJson($s['url'].'/contacts/'.$rule['contact_id'])->assertOk()->json('data');
        $this->assertTrue($party['is_employee']);
        $this->assertFalse($party['is_supplier']);
        $input = $this->record($s, $rule);
        $path = $s['url'].'/regular-expenses/'.$rule['id'].'/post';
        $doc = $this->postJson($path, $input)->assertCreated()->assertJsonPath('data.due_paisa', '2000000')->json('data');
        $this->postJson($path, $input)->assertOk()->assertJsonPath('data.id', $doc['id']);
        $payment = $this->record($s, $rule, ['paid_now' => '5000', 'money_account_id' => $s['cash']]);
        $paid = $this->postJson($path, $payment)->assertCreated()->assertJsonPath('data.due_paisa', '1500000')->json('data');
        $this->postJson($path, $payment)->assertOk();
        $this->assertSame(1, DB::table('documents')->where('type', 'expense')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->getJson($s['url'].'/reports/payables?to=20830115')->assertOk()->assertJsonFragment(['name' => 'Ram', 'due_paisa' => '1500000']);
        $report = $this->getJson($s['url'].'/reports/overview?from=20830101&to=20830115')->assertOk()->json('data');
        $this->assertSame('-2000000', $report['profit_paisa']);
        $this->assertSame('9500000', $report['cash_paisa']);
        foreach ($this->getJson($s['url'].'/reports/reconciliation')->assertOk()->json('data') as $row) {
            $this->assertSame('0', $row['difference_paisa']);
        }
        $this->postJson($s['url'].'/payments/'.$paid['payments'][0]['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830115, 'reason' => 'Wrong payment'])->assertCreated();
        $this->getJson($s['url'].'/document/'.$doc['id'])->assertOk()->assertJsonPath('data.due_paisa', '2000000');
        $this->assertSame(20830215, (int) DB::table('recurring_expenses')->value('next_date_bs'));
    }

    public function test_monthly_rent_auto_action_is_unique_and_never_moves_cash(): void
    {
        $s = $this->shop();
        $rule = $this->rule($s, ['kind' => 'rent', 'label' => 'Shop rent', 'payee_name' => 'Landlord', 'amount' => '10000', 'first_date_bs' => 20830131, 'monthly_day' => 32]);
        $this->artisan('app:generate-recurring-expenses', ['--through' => 20830332])->assertSuccessful();
        $this->artisan('app:generate-recurring-expenses', ['--through' => 20830332])->assertSuccessful();
        $this->assertSame([20830131, 20830231, 20830332], DB::table('documents')->orderBy('id')->pluck('business_date_bs')->map(fn ($date) => (int) $date)->all());
        $this->assertSame(20830431, (int) DB::table('recurring_expenses')->value('next_date_bs'));
        $this->assertSame(3, DB::table('recurring_expense_occurrences')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $id = DB::table('documents')->orderBy('id')->value('id');
        $this->postJson($s['url'].'/document/'.$id.'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830332, 'reason' => 'Wrong rent'])->assertCreated();
        $this->postJson($s['url'].'/regular-expenses/'.$rule['id'].'/post', $this->record($s, $rule, ['business_date_bs' => 20830332]))->assertUnprocessable();
        $this->assertSame(3, DB::table('documents')->count());
        $this->assertSame(20840115, NepaliDate::nextMonth(20831215, 15));
    }

    public function test_party_can_have_all_roles_and_employee_only_has_payable_channel(): void
    {
        $s = $this->shop();
        $input = ['name' => 'Many roles', 'is_customer' => true, 'is_supplier' => true, 'is_employee' => true, 'is_rent' => true];
        $party = $this->postJson($s['url'].'/contacts', $input)->assertCreated()->assertJsonPath('data.is_employee', true)->assertJsonPath('data.is_rent', true)->json('data');
        $rule = $this->rule($s, ['contact_id' => $party['id'], 'payee_name' => null]);
        $this->assertSame($party['id'], $rule['contact_id']);
        $employee = $this->postJson($s['url'].'/contacts', [...$input, 'name' => 'Employee only', 'is_customer' => false, 'is_supplier' => false, 'is_rent' => false])->assertCreated()->json('data');
        $this->postJson($s['url'].'/contacts', [...$input, 'is_customer' => false, 'is_supplier' => false, 'is_employee' => false, 'is_rent' => false])->assertUnprocessable();
        $item = $this->postJson($s['url'].'/items', ['name' => 'Service', 'kind' => 'service', 'unit_label' => 'unit', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $bill = ['mutation_uuid' => (string) Str::uuid(), 'contact_id' => $employee['id'], 'business_date_bs' => 20830115, 'lines' => [['item_id' => $item, 'qty' => '1', 'unit_price' => '100']], 'expected_total_paisa' => '10000'];
        $this->postJson($s['url'].'/documents/purchase', $bill)->assertUnprocessable();
        $category = $this->getJson($s['url'].'/lookup')->json('data.categories.0.id');
        $doc = $this->postJson($s['url'].'/documents/expense', [...$bill, 'lines' => [['expense_category_id' => $category, 'qty' => '1', 'unit_price' => '100']], 'paid_now' => '0'])->assertCreated()->json('data');
        $this->patchJson($s['url'].'/contacts/'.$employee['id'], ['name' => 'Employee only', 'is_customer' => true, 'is_supplier' => false, 'is_employee' => false, 'is_rent' => false])->assertUnprocessable();
        $eligible = $this->getJson($s['url'].'/lookup?party_role=employee')->assertOk()->json('data.contacts');
        $this->assertContains($employee['id'], array_column($eligible, 'id'));
        foreach ($eligible as $contact) {
            $this->assertTrue($contact['is_employee']);
        }
        $this->postJson($s['url'].'/payments', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'supplier_payment', 'contact_id' => $employee['id'], 'business_date_bs' => 20830115, 'amount' => '100', 'money_account_id' => $s['cash'], 'allocations' => [['document_id' => $doc['id'], 'amount' => '100']]])->assertCreated();
        $this->postJson($s['url'].'/documents/sale', [...$bill, 'mutation_uuid' => (string) Str::uuid(), 'contact_id' => $party['id']])->assertCreated();
        $this->getJson($s['url'].'/reports/receivables')->assertOk()->assertJsonFragment(['name' => 'Many roles', 'due_paisa' => '10000']);
    }

    public function test_atomic_payment_access_and_future_changes(): void
    {
        $s = $this->shop();
        $rule = $this->rule($s, ['amount' => '200000']);
        $path = $s['url'].'/regular-expenses/'.$rule['id'];
        $this->postJson($path.'/post', $this->record($s, $rule, ['paid_now' => '200000', 'money_account_id' => $s['cash']]))->assertUnprocessable();
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('recurring_expense_occurrences')->count());
        $this->postJson($path.'/post', $this->record($s, $rule, ['expected_total_paisa' => '1']))->assertConflict();
        $this->postJson($path.'/post', $this->record($s, $rule, ['period_bs' => 20830102]))->assertUnprocessable();
        $doc = $this->postJson($path.'/post', $this->record($s, $rule))->assertCreated()->json('data');
        $fresh = $this->getJson($path)->assertOk()->json('data');
        $update = ['mutation_uuid' => (string) Str::uuid(), 'version' => $fresh['version'], 'amount' => '25000', 'label' => 'Salary changed', 'auto_generate' => true, 'enabled' => false];
        $paused = $this->patchJson($path, $update)->assertCreated()->json('data');
        $this->artisan('app:generate-recurring-expenses', ['--through' => 20830215])->assertSuccessful();
        $this->assertSame(1, DB::table('documents')->count());
        $this->patchJson($path, [...$update, 'mutation_uuid' => (string) Str::uuid(), 'version' => $paused['version'], 'enabled' => true])->assertCreated();
        DB::table('tenant_user')->where('user_id', $s['owner']->id)->update(['active' => false]);
        $this->artisan('app:generate-recurring-expenses', ['--through' => 20830215])->assertFailed();
        $this->assertSame(1, DB::table('documents')->count());
        DB::table('tenant_user')->where('user_id', $s['owner']->id)->update(['active' => true]);
        $this->artisan('app:generate-recurring-expenses', ['--through' => 20830215])->assertSuccessful();
        $this->assertSame(2500000, (int) DB::table('documents')->orderByDesc('id')->value('total_paisa'));
        $this->assertSame(20000000, (int) DB::table('documents')->where('id', $doc['id'])->value('total_paisa'));
        $cashier = User::factory()->create();
        DB::table('tenant_user')->insert(['tenant_id' => $s['business']['id'], 'user_id' => $cashier->id, 'role' => 'cashier', 'active' => true]);
        $this->actingAs($cashier, 'tenant');
        $this->getJson($s['url'].'/regular-expenses')->assertForbidden();
        $this->postJson($path.'/post', $this->record($s, $rule))->assertForbidden();
        $this->actingAs(User::factory()->create(), 'tenant');
        $this->getJson($path)->assertNotFound();
    }

    public function test_foreign_party_closed_period_and_disabled_author_block_auto_action(): void
    {
        $s = $this->shop();
        $rule = $this->rule($s);
        $other = $this->postJson('/api/businesses', ['name' => 'Other'])->assertCreated()->json('data');
        $foreign = $this->postJson('/api/app/'.$other['slug'].'/contacts', ['name' => 'Foreign employee', 'is_customer' => false, 'is_supplier' => false, 'is_employee' => true])->assertCreated()->json('data.id');
        $input = ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'salary', 'label' => 'Foreign', 'contact_id' => $foreign, 'amount' => '1', 'first_date_bs' => 20830115, 'monthly_day' => 15, 'auto_generate' => true, 'enabled' => true];
        $this->postJson($s['url'].'/regular-expenses', $input)->assertNotFound();
        foreach ([['closed_through_bs' => 20830131], ['access_status' => 'suspended'], ['access_status' => 'active', 'access_until' => now()->subDay()]] as $state) {
            DB::table('tenants')->where('id', $s['business']['id'])->update($state);
            $this->artisan('app:generate-recurring-expenses', ['--through' => 20830115])->assertFailed();
            $this->assertSame(0, DB::table('documents')->count());
            DB::table('tenants')->where('id', $s['business']['id'])->update(['closed_through_bs' => null, 'access_status' => 'trial']);
        }
        foreach (['disabled_at', 'email_verified_at'] as $field) {
            DB::table('users')->where('id', $s['owner']->id)->update([$field => $field === 'disabled_at' ? now() : null]);
            $this->artisan('app:generate-recurring-expenses', ['--through' => 20830115])->assertFailed();
            $this->assertSame(0, DB::table('documents')->count());
            DB::table('users')->where('id', $s['owner']->id)->update([$field => $field === 'disabled_at' ? null : now()]);
        }
        $this->assertNotNull(DB::table('recurring_expenses')->where('id', $rule['id'])->value('last_error'));
        $this->artisan('app:generate-recurring-expenses', ['--through' => 20830115])->assertSuccessful();
        $this->assertNull(DB::table('recurring_expenses')->where('id', $rule['id'])->value('last_error'));
    }
}
