<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    public function rows(string $table): Builder
    {
        return DB::table($table)->where('tenant_id', app(CurrentTenant::class)->id());
    }

    public function requireRow(string $table, int|string $id): object
    {
        return $this->rows($table)->where('id', $id)->first() ?? abort(404);
    }

    public function fail(string $message, string $field = 'form'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function payableParty(object $party): bool
    {
        return (bool) ($party->is_supplier || $party->is_employee || $party->is_rent);
    }

    public function role(int $actor): string
    {
        return $this->rows('tenant_user')->where('user_id', $actor)->where('active', true)->value('role') ?? abort(404);
    }

    public function authorize(int $actor, array $roles): void
    {
        abort_unless(in_array($this->role($actor), $roles, true), 403, 'Action unavailable for this role.');
    }

    public function lockTenant(int $tenantId, int $actorId, bool $writable = true): Tenant
    {
        if (DB::transactionLevel() === 0 || app(CurrentTenant::class)->id() !== $tenantId) {
            throw new \LogicException('Locked tenant transaction required.');
        }
        // ponytail: tenant row serializes financial writes; use ordered item locks if measured contention requires finer locking.
        $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
        $this->role($actorId);
        abort_if(DB::table('users')->where('id', $actorId)->whereNotNull('disabled_at')->exists(), 403);
        abort_if($tenant->access_status === 'suspended', 403, 'Business suspended.');
        if ($writable) {
            $expiry = $tenant->access_status === 'trial' ? $tenant->trial_ends_at : $tenant->access_until;
            abort_unless(in_array($tenant->access_status, ['trial', 'active']) && $expiry && $expiry->isFuture(), 403, 'Business access expired. Read and export remain available.');
        }

        return $tenant;
    }

    public function assertOpenDate(Tenant $tenant, int $date, bool $opening = false): void
    {
        NepaliDate::normalize($date);
        if ($date > NepaliDate::today() || $date <= ($tenant->closed_through_bs ?? 0)) {
            $this->fail('Date is future or locked.', 'business_date_bs');
        }
        if (! $opening && (! $tenant->opening_finalized_at || $date < $tenant->opening_date_bs)) {
            $this->fail('Complete starting balances first; date must follow starting date.', 'business_date_bs');
        }
    }

    public function mutate(int $actor, string $uuid, string $operation, array $input, callable $action): array
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            } foreach ($value as &$v) {
                if (is_array($v)) {
                    $v = $canonical($v);
                }
            }

            return $value;
        };
        $hash = hash('sha256', json_encode($canonical($input), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $uuid, $operation, $hash, $action) {
            $tenant = $this->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $old = $this->rows('mutation_requests')->where('mutation_uuid', $uuid)->first();
            if ($old) {
                abort_unless($old->actor_id == $actor && $old->operation === $operation && hash_equals($old->request_hash, $hash), 409, 'Retry conflicts with original request.');
                $roles = match (true) {
                    in_array($operation, ['openings', 'period.close']) => ['owner', 'accountant'],
                    str_starts_with($operation, 'stock.') => ['owner', 'manager'],
                    in_array($operation, ['document.post', 'document.draft', 'payment', 'pos.sale']) || str_starts_with($operation, 'pos.restaurant.checkout.') || str_starts_with($operation, 'pos.appointment.checkout.') || str_starts_with($operation, 'draft.') || str_starts_with($operation, 'document.clone.') => ['owner', 'manager', 'accountant', 'cashier'],
                    str_starts_with($operation, 'operations.') => ['owner', 'manager', 'cashier'],
                    str_starts_with($operation, 'workflow.') => ['owner', 'manager', 'accountant', 'cashier'],
                    str_starts_with($operation, 'fulfilment.save.') || str_starts_with($operation, 'fulfilment.bill.') => ['owner', 'manager', 'accountant', 'cashier'],
                    default => ['owner', 'manager', 'accountant'],
                };
                $this->authorize($actor, $roles);
                if ($this->role($actor) === 'cashier' && $old->result_type === 'business_workflows') {
                    $row = $this->requireRow('business_workflows', $old->result_id);
                    abort_unless($row->created_by == $actor && $row->kind !== 'purchase_order', 403);
                }
                if ($this->role($actor) === 'cashier' && ! str_starts_with($operation, 'operations.')) {
                    if ($old->result_type === 'workflow_packages' && str_starts_with($operation, 'fulfilment.save.package.')) {
                        app(FulfilmentService::class)->assertPackageReplayAccess($actor, (int) $old->result_id);

                        return ['table' => $old->result_type, 'id' => $old->result_id, 'replayed' => true];
                    }
                    if ($old->result_type === 'workflow_fulfilments' && str_starts_with($operation, 'fulfilment.save.')) {
                        if (str_starts_with($operation, 'fulfilment.save.billed.')) {
                            app(FulfilmentService::class)->assertBilledReplayAccess($actor, (int) $old->result_id);
                        }
                        app(FulfilmentService::class)->data($actor, (int) $old->result_id);

                        return ['table' => $old->result_type, 'id' => $old->result_id, 'replayed' => true];
                    }
                    if ($old->result_type === 'business_workflows' && str_starts_with($operation, 'workflow.')) {
                        return ['table' => $old->result_type, 'id' => $old->result_id, 'replayed' => true];
                    }
                    abort_unless(in_array($old->result_type, ['documents', 'payments']), 403);
                    $row = $this->requireRow($old->result_type, $old->result_id);
                    if (str_starts_with($operation, 'fulfilment.bill.')) {
                        app(WorkflowService::class)->row($actor, (int) $row->workflow_id);
                    }
                    abort_unless($row->created_by == $actor && ($old->result_type === 'documents' ? $row->type === 'sale' : $row->kind === 'receipt'), 403);
                    if ($old->result_type === 'payments') {
                        $allocated = $this->rows('payment_allocations')->where('payment_id', $row->id);
                        abort_unless((int) (clone $allocated)->sum('amount_paisa') === (int) $row->amount_paisa && ! $allocated->whereNotIn('document_id', $this->rows('documents')->where('created_by', $actor)->where('type', 'sale')->select('id'))->exists(), 403);
                    }
                }

                return ['table' => $old->result_type, 'id' => $old->result_id, 'replayed' => true];
            }
            $result = $action($tenant);
            DB::table('mutation_requests')->insert(['tenant_id' => $tenant->id, 'mutation_uuid' => $uuid, 'operation' => $operation, 'request_hash' => $hash, 'actor_id' => $actor, 'result_type' => $result['table'], 'result_id' => $result['id']]);
            Tenant::whereKey($tenant->id)->increment('data_version');
            $this->audit($actor, $operation, $result['table'], $result['id']);

            return [...$result, 'replayed' => false];
        }, 3);
    }

    public function audit(int $actor, string $action, string $type, int $id, array $metadata = []): void
    {
        DB::table('audit_logs')->insert(['tenant_id' => app(CurrentTenant::class)->id(), 'actor_id' => $actor, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }

    public function account(string $key): int
    {
        return (int) ($this->rows('accounts')->where('system_key', $key)->value('id') ?? throw new \LogicException('Missing chart account.'));
    }

    public function seedChart(int $tenantId): void
    {
        $chart = [
            ['1000', 'Cash', 'asset', 'dr', 'cash', true], ['1100', 'Bank', 'asset', 'dr', 'bank_default', true], ['1200', 'Customer Receivables', 'asset', 'dr', 'receivables', false], ['1300', 'Inventory', 'asset', 'dr', 'inventory', false], ['1400', 'Recoverable Input VAT', 'asset', 'dr', 'input_vat', false], ['2000', 'Supplier Payables', 'liability', 'cr', 'payables', false], ['2100', 'Output VAT', 'liability', 'cr', 'output_vat', false], ['3000', 'Owner Capital', 'equity', 'cr', 'capital', false], ['3100', 'Owner Drawings', 'equity', 'dr', 'drawings', false], ['3200', 'Opening Equity', 'equity', 'cr', 'opening_equity', false], ['3300', 'Retained Earnings', 'equity', 'cr', 'retained_earnings', false], ['4000', 'Sales', 'income', 'cr', 'sales', false], ['4100', 'Sales Returns', 'income', 'dr', 'sales_returns', false], ['4200', 'Inventory Gain', 'income', 'cr', 'inventory_gain', false], ['5000', 'Cost of Goods Sold', 'expense', 'dr', 'cogs', false], ['5100', 'General Expense', 'expense', 'dr', 'general_expense', false], ['5200', 'Inventory Loss', 'expense', 'dr', 'inventory_loss', false],
        ];
        $chart[] = ['1350', 'Delivered awaiting bill', 'asset', 'dr', 'delivered_unbilled', false];
        $chart[] = ['2050', 'Received awaiting bill', 'liability', 'cr', 'received_unbilled', false];
        $chart[] = ['1355', 'Billed awaiting receipt', 'asset', 'dr', 'billed_unreceived', false];
        $chart[] = ['1360', 'Goods in transit', 'asset', 'dr', 'goods_in_transit', false];
        $chart[] = ['2060', 'Sales billed awaiting fulfilment', 'liability', 'cr', 'sales_unfulfilled', false];
        foreach ($chart as [$code,$name,$category,$side,$key,$money]) {
            DB::table('accounts')->insert(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'category' => $category, 'normal_side' => $side, 'system_key' => $key, 'is_money' => $money, 'money_kind' => $money ? ($key === 'cash' ? 'cash' : 'bank') : null, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['Rent', 'Utilities', 'Transport', 'Miscellaneous'] as $i => $name) {
            $account = DB::table('accounts')->insertGetId(['tenant_id' => $tenantId, 'code' => (string) (5110 + $i * 10), 'name' => $name, 'category' => 'expense', 'normal_side' => 'dr', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('expense_categories')->insert(['tenant_id' => $tenantId, 'name' => $name, 'account_id' => $account, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['Walk-in', true, false], ['Expense payee', false, true]] as [$name,$customer,$supplier]) {
            DB::table('contacts')->insert(['tenant_id' => $tenantId, 'name' => $name, 'is_customer' => $customer, 'is_supplier' => $supplier, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function line(int|string $account, int $amount, ?int $contact = null): array
    {
        return ['account_id' => is_string($account) ? $this->account($account) : $account, 'contact_id' => $contact, 'debit_paisa' => max(0, $amount), 'credit_paisa' => max(0, -$amount)];
    }

    public function balance(int $account, ?int $contact = null, ?int $asOf = null): int
    {
        $q = $this->rows('journal_lines')->where('account_id', $account);
        if ($contact !== null) {
            $q->where('contact_id', $contact);
        }
        if ($asOf !== null) {
            $q->whereIn('journal_entry_id', $this->rows('journal_entries')->where('business_date_bs', '<=', $asOf)->select('id'));
        }

        return (int) $q->selectRaw('COALESCE(SUM(debit_paisa-credit_paisa),0) AS balance')->value('balance');
    }

    public function post(int $actor, int $date, array $source, array $lines, string $description, bool $overdraft = false): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Journal requires transaction.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => $l['debit_paisa'] || $l['credit_paisa']));
        if (count($lines) < 2 || array_sum(array_column($lines, 'debit_paisa')) !== array_sum(array_column($lines, 'credit_paisa'))) {
            throw new \LogicException('Unbalanced journal.');
        }
        $moneyDeltas = [];
        foreach ($lines as $line) {
            $account = $this->requireRow('accounts', $line['account_id']);
            if (($line['debit_paisa'] <= 0) === ($line['credit_paisa'] <= 0) || $line['debit_paisa'] < 0 || $line['credit_paisa'] < 0) {
                throw new \LogicException('Invalid journal line.');
            }
            if ($line['contact_id']) {
                $this->requireRow('contacts', $line['contact_id']);
            }
            if (in_array($account->system_key, ['receivables', 'payables']) && ! $line['contact_id']) {
                throw new \LogicException('Party required.');
            }
            if ($account->is_money) {
                $moneyDeltas[$account->id] = ($moneyDeltas[$account->id] ?? 0) + $line['debit_paisa'] - $line['credit_paisa'];
            }
        }
        foreach ($moneyDeltas as $id => $delta) {
            $running = $this->balance($id, null, $date) + $delta;
            $minimum = $running;
            $later = DB::table('journal_lines as l')->where('l.tenant_id', app(CurrentTenant::class)->id())->where('l.account_id', $id)->join('journal_entries as e', function ($join) {
                $join->on('e.id', '=', 'l.journal_entry_id')->on('e.tenant_id', '=', 'l.tenant_id');
            })->where('e.business_date_bs', '>', $date)->select('e.business_date_bs')->selectRaw('SUM(l.debit_paisa-l.credit_paisa) AS delta')->groupBy('e.business_date_bs')->orderBy('e.business_date_bs')->get();
            foreach ($later as $day) {
                $running += (int) $day->delta;
                $minimum = min($minimum, $running);
            }
            if ($minimum < 0) {
                $account = $this->requireRow('accounts', $id);
                if ($account->system_key === 'cash' || $account->money_kind === 'cash' || ! $overdraft || ! in_array($this->role($actor), ['owner', 'accountant'])) {
                    $this->fail('Insufficient funds on this or a later business date. Bank overdraft requires owner/accountant confirmation.', 'money_account_id');
                }
                $this->audit($actor, 'bank.overdraft', 'accounts', (int) $id, ['business_date_bs' => $date, 'lowest_balance_paisa' => $minimum]);
            }
        }
        $id = DB::table('journal_entries')->insertGetId(['tenant_id' => app(CurrentTenant::class)->id(), 'source_type' => $source['type'], 'source_id' => $source['id'], 'source_event' => $source['event'] ?? 'post', 'business_date_bs' => $date, 'fiscal_year_label' => NepaliDate::fiscalYearLabel($date), 'description' => $description, 'created_by' => $actor, 'reversal_of_id' => $source['reversal_of_id'] ?? null, 'owner_entry_kind' => $source['owner_entry_kind'] ?? null, 'created_at' => now()]);
        if ($source['type'] === 'owner' && ! $source['id']) {
            $this->rows('journal_entries')->where('id', $id)->update(['source_id' => $id]);
        }
        DB::table('journal_lines')->insert(array_map(fn ($line) => ['tenant_id' => app(CurrentTenant::class)->id(), 'journal_entry_id' => $id, ...$line], $lines));

        return $id;
    }

    public function reverse(int $actor, int $journal, int $date, string $reason, bool $overdraft = false, string $sourceEvent = 'reverse'): int
    {
        $original = $this->requireRow('journal_entries', $journal);
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());
        $this->assertOpenDate($tenant, $original->business_date_bs);
        $this->assertOpenDate($tenant, $date);
        if ($date < $original->business_date_bs || $original->reversal_of_id || $this->rows('journal_entries')->where('reversal_of_id', $journal)->exists()) {
            $this->fail('Invalid reversal or already reversed.');
        }
        $lines = $this->rows('journal_lines')->where('journal_entry_id', $journal)->get()->map(fn ($l) => ['account_id' => (int) $l->account_id, 'contact_id' => $l->contact_id ? (int) $l->contact_id : null, 'debit_paisa' => (int) $l->credit_paisa, 'credit_paisa' => (int) $l->debit_paisa])->all();

        return $this->post($actor, $date, ['type' => $original->source_type, 'id' => $original->source_id, 'event' => $sourceEvent, 'reversal_of_id' => $journal, 'owner_entry_kind' => $original->owner_entry_kind], $lines, $reason, $overdraft);
    }

    public function finalizeOpenings(int $actor, array $input, string $uuid): array
    {
        return $this->mutate($actor, $uuid, 'openings', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->authorize($actor, ['owner', 'accountant']);
            abort_if($tenant->opening_finalized_at, 409, 'Starting balances already finalized.');
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->assertOpenDate($tenant, $date, true);
            $lines = [];
            foreach ($input['money'] ?? [] as $row) {
                $account = $this->requireRow('accounts', $row['account_id']);
                if (! $account->is_money || $account->archived_at) {
                    $this->fail('Choose active cash/bank account.');
                } $lines[] = $this->line((int) $account->id, Money::parse($row['amount']));
            }
            foreach ($input['parties'] ?? [] as $row) {
                $party = $this->requireRow('contacts', $row['contact_id']);
                if ($party->is_system) {
                    $this->fail('Choose named customer/supplier.');
                }
                $amount = Money::parse($row['amount']);
                $direction = $row['direction'];
                if (! in_array($direction, ['customer_owes', 'customer_credit', 'supplier_owed', 'supplier_advance'])) {
                    $this->fail('Invalid opening direction.');
                }
                if ((str_starts_with($direction, 'customer') && ! $party->is_customer) || (str_starts_with($direction, 'supplier') && ! $this->payableParty($party))) {
                    $this->fail('Party channel unavailable.');
                }
                $lines[] = $this->line(str_starts_with($direction, 'customer') ? 'receivables' : 'payables', in_array($direction, ['customer_owes', 'supplier_advance']) ? $amount : -$amount, (int) $party->id);
            }
            foreach ($input['stock'] ?? [] as $row) {
                $item = $this->requireRow('items', $row['item_id']);
                if ($item->kind !== 'stock' || $item->archived_at) {
                    $this->fail('Choose active stock item.');
                }
                $qty = Money::quantity($row['qty']);
                $value = Money::parse($row['value']);
                if ($qty <= 0 || (! $value && empty($row['zero_cost_confirmed']))) {
                    $this->fail('Stock requires quantity and value, or explicit zero-cost confirmation.');
                }
                $id = DB::table('opening_balances')->insertGetId(['tenant_id' => $tenant->id, 'account_id' => $this->account('inventory'), 'item_id' => $item->id, 'qty_milli' => $qty, 'debit_paisa' => $value, 'credit_paisa' => 0, 'business_date_bs' => $date, 'created_by' => $actor]);
                app(InventoryService::class)->receive($actor, (int) $item->id, $date, $qty, $value, ['opening_balance_id' => $id]);
                $lines[] = $this->line('inventory', $value);
            }
            $net = array_sum(array_column($lines, 'debit_paisa')) - array_sum(array_column($lines, 'credit_paisa'));
            $lines[] = $this->line('opening_equity', -$net);
            $nonzero = array_filter($lines, fn ($l) => $l['debit_paisa'] || $l['credit_paisa']);
            $journal = $nonzero ? $this->post($actor, $date, ['type' => 'opening', 'id' => $tenant->id], $lines, 'Starting balances') : null;
            foreach ($lines as $line) {
                if ($line['account_id'] !== $this->account('inventory') && ($line['debit_paisa'] || $line['credit_paisa'])) {
                    DB::table('opening_balances')->insert(['tenant_id' => $tenant->id, ...$line, 'business_date_bs' => $date, 'created_by' => $actor, 'journal_id' => $journal]);
                }
            }
            $this->rows('opening_balances')->update(['journal_id' => $journal]);
            $tenant->update(['opening_date_bs' => $date, 'opening_finalized_at' => now()]);

            return ['table' => 'tenants', 'id' => $tenant->id];
        });
    }

    public function owner(int $actor, array $input, string $uuid): array
    {
        return $this->mutate($actor, $uuid, 'owner-money', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->authorize($actor, ['owner', 'manager', 'accountant']);
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->assertOpenDate($tenant, $date);
            $amount = Money::parse($input['amount']);
            if (! $amount || ! in_array($input['kind'], ['contribution', 'withdrawal'])) {
                $this->fail('Choose contribution/withdrawal and positive amount.');
            }
            $account = $this->requireRow('accounts', $input['money_account_id']);
            if (! $account->is_money || $account->archived_at) {
                $this->fail('Choose active cash/bank account.');
            }
            $in = $input['kind'] === 'contribution';
            $lines = [$this->line((int) $account->id, $in ? $amount : -$amount), $this->line($in ? 'capital' : 'drawings', $in ? -$amount : $amount)];
            $id = $this->post($actor, $date, ['type' => 'owner', 'id' => 0, 'owner_entry_kind' => $input['kind']], $lines, $input['notes'] ?? ($in ? 'Money added' : 'Personal withdrawal'), $input['overdraft_confirmed'] ?? false);

            return ['table' => 'journal_entries', 'id' => $id];
        });
    }
}
