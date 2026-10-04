<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecurringExpenseService
{
    public function __construct(private AccountingService $a, private DocumentService $documents, private PaymentService $payments) {}

    public function save(int $actor, array $input, ?int $id = null): array
    {
        return $this->a->mutate($actor, $input['mutation_uuid'], 'regular.save.'.($id ?? 'new'), $input, function (Tenant $tenant) use ($actor, $input, $id) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $amount = Money::parse($input['amount']);
            if (! $amount) {
                $this->a->fail('Enter a positive monthly amount.', 'amount');
            }
            $values = ['label' => $input['label'], 'amount_paisa' => $amount, 'auto_generate' => $input['auto_generate'], 'enabled' => $input['enabled'], 'authorized_by' => $actor, 'last_error' => null, 'updated_at' => now()];
            if ($id) {
                $old = $this->a->requireRow('recurring_expenses', $id);
                abort_unless((int) $old->version === (int) $input['version'], 409, 'Setup changed. Reload before saving.');
                $this->a->rows('recurring_expenses')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
            } else {
                $date = NepaliDate::normalize($input['first_date_bs']);
                if (! $tenant->opening_finalized_at || $date < $tenant->opening_date_bs || $date <= ($tenant->closed_through_bs ?? 0)) {
                    $this->a->fail('First date must follow starting balances and closed periods.', 'first_date_bs');
                }
                $kind = $input['kind'];
                $flag = match ($kind) {
                    'salary' => 'is_employee', 'rent' => 'is_rent', default => 'is_supplier'
                };
                if (! empty($input['contact_id'])) {
                    $party = $this->a->requireRow('contacts', $input['contact_id']);
                    if ($party->is_system || $party->archived_at || ($kind === 'other' ? ! $this->a->payableParty($party) : ! $party->$flag)) {
                        $this->a->fail('Choose an active party with matching role.', 'contact_id');
                    }
                    $contact = $party->id;
                } else {
                    if (empty(trim($input['payee_name'] ?? ''))) {
                        $this->a->fail('Enter party name or choose existing party.', 'payee_name');
                    }
                    $contact = DB::table('contacts')->insertGetId(['tenant_id' => $tenant->id, 'name' => trim($input['payee_name']), 'is_customer' => false, $flag => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                if ($kind === 'other') {
                    $category = $this->a->requireRow('expense_categories', $input['expense_category_id'] ?? 0);
                } else {
                    $name = $kind === 'salary' ? 'Salary' : 'Rent';
                    $category = $this->a->rows('expense_categories')->where('name', $name)->first();
                    if (! $category) {
                        $account = DB::table('accounts')->insertGetId(['tenant_id' => $tenant->id, 'code' => (string) (100000 + (int) $this->a->rows('accounts')->max('id')), 'name' => $name, 'category' => 'expense', 'normal_side' => 'dr', 'created_at' => now(), 'updated_at' => now()]);
                        $categoryId = DB::table('expense_categories')->insertGetId(['tenant_id' => $tenant->id, 'name' => $name, 'account_id' => $account, 'created_at' => now(), 'updated_at' => now()]);
                        $category = $this->a->requireRow('expense_categories', $categoryId);
                    }
                }
                if ($category->archived_at) {
                    $this->a->fail('Expense category archived.');
                }
                $id = DB::table('recurring_expenses')->insertGetId(['tenant_id' => $tenant->id, 'kind' => $kind, 'contact_id' => $contact, 'expense_category_id' => $category->id, 'first_date_bs' => $date, 'next_date_bs' => $date, 'monthly_day' => $input['monthly_day'], 'created_at' => now(), ...$values]);
            }

            return ['table' => 'recurring_expenses', 'id' => $id];
        });
    }

    public function period(object $rule, string|int $period): int
    {
        $period = NepaliDate::normalize($period);
        if ($period % 100 !== 1 || $period < NepaliDate::monthRange((int) $rule->first_date_bs)[0] || $period > NepaliDate::today()) {
            $this->a->fail('Choose valid BS month from first month through today.', 'period_bs');
        }

        return $period;
    }

    public function preview(object $rule, int $period): array
    {
        $link = $this->a->rows('recurring_expense_occurrences')->where('recurring_expense_id', $rule->id)->where('period_bs', $period)->first();
        if (! $link) {
            return ['document_id' => null, 'status' => 'unrecorded', 'total_paisa' => (int) $rule->amount_paisa, 'due_paisa' => (int) $rule->amount_paisa];
        }
        $doc = $this->a->requireRow('documents', $link->document_id);

        return ['document_id' => $doc->id, 'number' => $doc->number, 'status' => $doc->status, 'total_paisa' => (int) $doc->total_paisa, ...$this->payments->invoiceBalance((int) $doc->id)];
    }

    private function accrue(int $actor, object $rule, int $period, int $date): int
    {
        $link = $this->a->rows('recurring_expense_occurrences')->where('recurring_expense_id', $rule->id)->where('period_bs', $period)->first();
        if ($link) {
            return (int) $link->document_id;
        }
        if (! $rule->enabled || NepaliDate::monthRange((int) $rule->next_date_bs)[0] !== $period) {
            $this->a->fail('Record next due month first, or enable this setup.');
        }
        $date = min((int) $rule->next_date_bs, $date);
        if ($date < $rule->first_date_bs || $date < $period) {
            $this->a->fail('Date precedes this monthly expense.');
        }
        $result = $this->documents->save($actor, ['type' => 'expense', 'contact_id' => $rule->contact_id, 'business_date_bs' => $date, 'due_date_bs' => max($date, (int) $rule->next_date_bs), 'notes' => $rule->label.' · BS '.intdiv($period, 100).' · monthly '.$rule->kind, 'lines' => [['expense_category_id' => $rule->expense_category_id, 'qty' => '1', 'unit_price' => Money::format((int) $rule->amount_paisa), 'tax_category' => 'outside_scope', 'tax_bps' => 0]], 'paid_now' => '0', 'expected_total_paisa' => (string) $rule->amount_paisa], (string) Str::uuid());
        DB::table('recurring_expense_occurrences')->insert(['tenant_id' => app(CurrentTenant::class)->id(), 'recurring_expense_id' => $rule->id, 'document_id' => $result['id'], 'period_bs' => $period, 'created_at' => now(), 'updated_at' => now()]);
        $this->a->rows('recurring_expenses')->where('id', $rule->id)->update(['next_date_bs' => NepaliDate::nextMonth((int) $rule->next_date_bs, (int) $rule->monthly_day), 'version' => $rule->version + 1, 'last_error' => null, 'updated_at' => now()]);

        return (int) $result['id'];
    }

    public function post(int $actor, int $id, array $input): array
    {
        return $this->a->mutate($actor, $input['mutation_uuid'], 'regular.post.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $rule = $this->a->requireRow('recurring_expenses', $id);
            $period = $this->period($rule, $input['period_bs']);
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->a->assertOpenDate($tenant, $date);
            if ($date < $period || $date < $rule->first_date_bs) {
                $this->a->fail('Payment date precedes salary/rent month.');
            }
            $preview = $this->preview($rule, $period);
            abort_unless((string) $preview['total_paisa'] === (string) $input['expected_total_paisa'], 409, 'Monthly amount changed. Review again.');
            $docId = $this->accrue($actor, $rule, $period, $date);
            $doc = $this->a->requireRow('documents', $docId);
            if ($doc->status !== 'posted') {
                $this->a->fail('Monthly expense canceled. Review original expense; automatic recreation is blocked.');
            }
            if (Money::parse($input['paid_now']) > 0) {
                $this->payments->create($actor, $tenant, ['kind' => 'supplier_payment', 'contact_id' => $doc->contact_id, 'business_date_bs' => $date, 'amount' => $input['paid_now'], 'money_account_id' => $input['money_account_id'] ?? null, 'overdraft_confirmed' => $input['overdraft_confirmed'] ?? false, 'allocations' => [['document_id' => $docId, 'amount' => $input['paid_now']]], 'notes' => $rule->label]);
            }

            return ['table' => 'documents', 'id' => $docId];
        });
    }

    public function generateDue(int $id, int $through): bool
    {
        $actor = (int) $this->a->requireRow('recurring_expenses', $id)->authorized_by;

        return DB::transaction(function () use ($id, $through, $actor) {
            $tenant = $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            abort_unless(DB::table('users')->where('id', $actor)->whereNotNull('email_verified_at')->exists(), 403, 'Setup author must have verified email.');
            $rule = $this->a->requireRow('recurring_expenses', $id);
            abort_unless((int) $rule->authorized_by === $actor, 409, 'Setup author changed. Retry generation.');
            if (! $rule->enabled || ! $rule->auto_generate || $rule->next_date_bs > $through) {
                return false;
            }
            $date = (int) $rule->next_date_bs;
            $this->a->assertOpenDate($tenant, $date);
            $doc = $this->accrue($actor, $rule, NepaliDate::monthRange($date)[0], $date);
            $tenant->increment('data_version');
            $this->a->audit($actor, 'regular.auto', 'documents', $doc);

            return true;
        }, 3);
    }
}
