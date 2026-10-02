<?php

namespace App\Http\Controllers;

use App\NepaliDate;
use App\Service\AccountingService;
use App\Service\PaymentService;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private AccountingService $a) {}

    private function bounded(Builder $query): Collection
    {
        $rows = $query->limit(20001)->get();
        if ($rows->count() > 20000) {
            $this->a->fail('More than 20,000 rows. Narrow report dates or filters.');
        }

        return $rows;
    }

    private function journal(int $to, ?int $from = null): Builder
    {
        $entries = $this->a->rows('journal_entries')->where('business_date_bs', '<=', $to);
        if ($from !== null) {
            $entries->where('business_date_bs', '>=', $from);
        }

        return $this->a->rows('journal_lines')->whereIn('journal_entry_id', $entries->select('id'));
    }

    public function accounts(int $to, ?int $from = null): array
    {
        $sums = $this->journal($to, $from)->select('account_id')->selectRaw('SUM(debit_paisa) AS debit_paisa, SUM(credit_paisa) AS credit_paisa')->groupBy('account_id')->get()->keyBy('account_id');

        return $this->a->rows('accounts')->orderBy('code')->get()->map(function ($a) use ($sums) {
            $sum = $sums[$a->id] ?? null;

            return [...(array) $a, 'debit_paisa' => (int) ($sum?->debit_paisa ?? 0), 'credit_paisa' => (int) ($sum?->credit_paisa ?? 0), 'balance_paisa' => (int) ($sum?->debit_paisa ?? 0) - (int) ($sum?->credit_paisa ?? 0)];
        })->all();
    }

    public function overview(int $from, int $to): array
    {
        $accounts = $this->accounts($to);
        $period = $this->accounts($to, $from);
        $key = [];
        $periodKey = [];
        $cash = 0;
        $debit = 0;
        $credit = 0;
        $profit = 0;
        $asset = 0;
        $liability = 0;
        $equity = 0;
        $bankOverdraft = 0;
        foreach ($accounts as $row) {
            $key[$row['system_key']] = $row['balance_paisa'];
            if ($row['is_money']) {
                $cash += $row['balance_paisa'];
                $bankOverdraft += max(0, -$row['balance_paisa']);
            } $debit += $row['debit_paisa'];
            $credit += $row['credit_paisa'];
            if ($row['category'] === 'asset') {
                $asset += $row['balance_paisa'];
            } if ($row['category'] === 'liability') {
                $liability -= $row['balance_paisa'];
            } if (in_array($row['category'], ['equity', 'income', 'expense'])) {
                $equity -= $row['balance_paisa'];
            }
        }
        foreach ($period as $row) {
            $periodKey[$row['system_key']] = $row['balance_paisa'];
            if (in_array($row['category'], ['income', 'expense'])) {
                $profit -= $row['balance_paisa'];
            }
        }
        $partySums = $this->journal($to)->whereIn('account_id', [$this->a->account('receivables'), $this->a->account('payables')])->select('account_id', 'contact_id')->selectRaw('SUM(debit_paisa-credit_paisa) AS balance')->groupBy('account_id', 'contact_id')->get();
        $ar = 0;
        $ap = 0;
        $customerCredits = 0;
        $advances = 0;
        foreach ($partySums as $row) {
            if ($row->account_id === $this->a->account('receivables')) {
                $ar += max(0, (int) $row->balance);
                $customerCredits += max(0, -(int) $row->balance);
            } else {
                $ap += max(0, -(int) $row->balance);
                $advances += max(0, (int) $row->balance);
            }
        }

        return ['cash_paisa' => $cash, 'inventory_paisa' => $key['inventory'] ?? 0, 'receivables_paisa' => $ar, 'payables_paisa' => $ap, 'customer_credits_paisa' => $customerCredits, 'supplier_advances_paisa' => $advances, 'bank_overdrafts_paisa' => $bankOverdraft, 'sales_paisa' => -($periodKey['sales'] ?? 0) - ($periodKey['sales_returns'] ?? 0), 'cogs_paisa' => $periodKey['cogs'] ?? 0, 'profit_paisa' => $profit, 'debit_paisa' => $debit, 'credit_paisa' => $credit, 'assets_paisa' => $asset + $customerCredits + $advances + $bankOverdraft, 'liabilities_paisa' => $liability + $customerCredits + $advances + $bankOverdraft, 'equity_paisa' => $equity];
    }

    public function stockRows(int $to): array
    {
        $latest = $this->a->rows('stock_movements')->where('business_date_bs', '<=', $to)->select('item_id')->selectRaw('MAX(id) AS movement_id')->groupBy('item_id');
        $query = DB::table('items')->where('items.tenant_id', app(CurrentTenant::class)->id())->where('items.kind', 'stock')->leftJoinSub($latest, 'latest', fn ($join) => $join->on('latest.item_id', '=', 'items.id'))->leftJoin('stock_movements as pool', 'pool.id', '=', 'latest.movement_id')->select('items.id', 'items.name', 'items.unit_label', 'items.low_stock_qty_milli')->selectRaw('COALESCE(pool.qty_after_milli,0) AS qty_milli, COALESCE(pool.value_after_paisa,0) AS value_paisa')->orderBy('items.name');

        return $this->bounded($query)->map(fn ($row) => (array) $row)->all();
    }

    public function stockValue(int $to): int
    {
        return array_sum(array_column($this->stockRows($to), 'value_paisa'));
    }

    private function filters(Request $r): array
    {
        $input = $r->validate(['from' => ['nullable', ...array_slice(app(BusinessController::class)->dateRule(), 1)], 'to' => ['nullable', ...array_slice(app(BusinessController::class)->dateRule(), 1)], 'contact_id' => 'nullable|integer|min:1', 'item_id' => 'nullable|integer|min:1', 'account_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1']);
        $to = NepaliDate::normalize($input['to'] ?? NepaliDate::today());
        $from = NepaliDate::normalize($input['from'] ?? NepaliDate::monthRange($to)[0]);
        if ($from > $to) {
            $this->a->fail('Range start follows end.');
        }

        return [$from, $to, $input];
    }

    public function dashboard(Request $r): JsonResponse
    {
        $actor = auth('tenant')->id();
        $role = $this->a->role($actor);
        $tenant = $r->attributes->get('tenant');
        $today = NepaliDate::today();
        if ($role === 'cashier') {
            $documents = $this->a->rows('documents')->where('type', 'sale')->where('created_by', $actor)->orderByDesc('id')->limit(10)->get()->map(fn ($d) => ['id' => $d->id, 'number' => $d->number, 'status' => $d->status, 'total_paisa' => $d->total_paisa, 'business_date_bs' => $d->business_date_bs]);

            return response()->json(['data' => ['recent' => BusinessController::json($documents), 'today_bs' => $today, 'role' => $role, 'tenant' => BusinessController::json($tenant)]]);
        }
        $data = Cache::remember('business-book:home:'.$tenant->id.':'.$tenant->data_version.':'.$role.':'.$actor.':'.$today, 45, function () use ($today, $role, $tenant) {
            $recent = $this->a->rows('documents')->orderByDesc('id')->limit(6)->select('id', 'number', 'type', 'status', 'business_date_bs', 'total_paisa', 'party_snapshot')->get();
            $low = array_values(array_filter($this->stockRows($today), fn ($s) => $s['qty_milli'] <= $s['low_stock_qty_milli']));

            return BusinessController::json([...$this->overview($today, $today), 'recent' => $recent, 'low_stock' => array_slice($low, 0, 5), 'today_bs' => $today, 'role' => $role, 'tenant' => $tenant]);
        });

        return response()->json(['data' => $data]);
    }

    public function show(Request $r, string $tenant, string $report): JsonResponse
    {
        $this->a->authorize(auth('tenant')->id(), ['owner', 'manager', 'accountant']);
        [$from,$to,$input] = $this->filters($r);
        $data = match ($report) {
            'overview','profit-loss','balance-sheet' => $this->overview($from, $to),
            'trial-balance' => $this->accounts($to),
            'reconciliation' => $this->reconciliation($to),
            'customer-aging','supplier-aging' => $this->aging($to, $report === 'customer-aging'),
            'stock','low-stock' => array_values(array_filter($this->stockRows($to), fn ($s) => $report !== 'low-stock' || $s['qty_milli'] <= $s['low_stock_qty_milli'])),
            'cashbook','statement' => $this->statement($from, $to, $input, $report === 'cashbook'),
            'receivables','payables' => $this->dues($to, $report === 'receivables'),
            'stock-movements' => $this->movements($from, $to, $input),
            'sales','purchases','expenses','money' => $this->activity($from, $to, $report),
            default => abort(404),
        };

        return response()->json(['data' => BusinessController::json($data), 'from_bs' => $from, 'to_bs' => $to]);
    }

    private function dues(int $to, bool $customer): array
    {
        $account = $this->a->account($customer ? 'receivables' : 'payables');

        return $this->a->rows('contacts')->orderBy('name')->get()->map(function ($party) use ($to, $account, $customer) {
            $balance = $this->a->balance($account, (int) $party->id, $to) * ($customer ? 1 : -1);

            return ['id' => $party->id, 'name' => $party->name, 'due_paisa' => max(0, $balance), 'credit_paisa' => max(0, -$balance)];
        })->all();
    }

    public function reconciliation(int $to): array
    {
        $totals = [];
        foreach ($this->bounded($this->a->rows('contacts')->orderBy('id')) as $party) {
            foreach (['receivables', 'payables'] as $channel) {
                $account = $this->a->account($channel);
                $sign = $channel === 'receivables' ? 1 : -1;
                $opening = (int) $this->a->rows('opening_balances')->where('account_id', $account)->where('contact_id', $party->id)->where('business_date_bs', '<=', $to)->selectRaw('COALESCE(SUM(debit_paisa-credit_paisa),0) AS amount')->value('amount') * $sign;
                $bills = 0;
                $unallocated = 0;
                // ponytail: linear bill scan; batch dated allocations when reconciliation reaches thousands of bills.
                foreach ($this->bounded($this->a->rows('documents')->where('contact_id', $party->id)->whereNotNull('posted_at')->whereIn('type', $channel === 'receivables' ? ['sale'] : ['purchase', 'expense'])) as $doc) {
                    $bills += app(PaymentService::class)->invoiceBalance((int) $doc->id, $to)['signed_due_paisa'];
                }
                foreach ($this->bounded($this->a->rows('payments')->where('contact_id', $party->id)->where('business_date_bs', '<=', $to)->whereIn('kind', $channel === 'receivables' ? ['receipt', 'customer_refund'] : ['supplier_payment', 'supplier_refund'])->where(fn ($q) => $q->whereNull('cancellation_date_bs')->orWhere('cancellation_date_bs', '>', $to))) as $payment) {
                    $unallocated += ((int) $payment->amount_paisa - (int) $this->a->rows('payment_allocations')->where('payment_id', $payment->id)->sum('amount_paisa')) * (str_ends_with($payment->kind, 'refund') ? 1 : -1);
                }
                $ledger = $this->a->balance($account, (int) $party->id, $to);
                $totals[] = ['name' => $party->name, 'channel' => $channel, 'starting_paisa' => $opening, 'bills_paisa' => $bills, 'unallocated_paisa' => $unallocated, 'balance_paisa' => $ledger * $sign, 'difference_paisa' => $ledger * $sign - $opening - $bills - $unallocated];
            }
        }

        return $totals;
    }

    private function aging(int $to, bool $customer): array
    {
        $rows = [];
        foreach ($this->bounded($this->a->rows('documents')->whereNotNull('posted_at')->where('business_date_bs', '<=', $to)->whereIn('type', $customer ? ['sale'] : ['purchase', 'expense'])->orderBy('contact_id')->orderBy('business_date_bs')) as $doc) {
            $due = app(PaymentService::class)->invoiceBalance((int) $doc->id, $to)['signed_due_paisa'];
            if (! $due) {
                continue;
            }
            $days = NepaliDate::daysBetween((int) ($doc->due_date_bs ?? $doc->business_date_bs), $to);
            $bucket = $due < 0 ? 'refund_pending' : ($days <= 0 ? 'not_due' : ($days <= 30 ? '1_30_days' : ($days <= 60 ? '31_60_days' : ($days <= 90 ? '61_90_days' : 'over_90_days'))));
            $rows[] = ['number' => $doc->number, 'name' => json_decode($doc->party_snapshot)->name, 'due_date_bs' => $doc->due_date_bs ?? $doc->business_date_bs, 'bucket' => $bucket, 'due_paisa' => $due];
        }
        foreach ($this->reconciliation($to) as $party) {
            if ($party['channel'] === ($customer ? 'receivables' : 'payables') && ($party['starting_paisa'] || $party['unallocated_paisa'])) {
                $rows[] = ['number' => 'Starting / unapplied', 'name' => $party['name'], 'due_date_bs' => null, 'bucket' => 'starting_and_unapplied', 'due_paisa' => $party['starting_paisa'] + $party['unallocated_paisa']];
            }
        }

        return $rows;
    }

    private function statement(int $from, int $to, array $input, bool $cash): array
    {
        if ($cash) {
            $account = $this->a->requireRow('accounts', $input['account_id'] ?? $this->a->account('cash'));
            if (! $account->is_money) {
                $this->a->fail('Choose money account.');
            } $contact = null;
        } else {
            $contact = (int) ($input['contact_id'] ?? 0);
            $this->a->requireRow('contacts', $contact);
            $account = $this->a->requireRow('accounts', $input['account_id'] ?? $this->a->account('receivables'));
            if (! in_array($account->system_key, ['receivables', 'payables'])) {
                $this->a->fail('Choose customer or supplier channel.');
            }
        }
        $opening = $this->a->balance((int) $account->id, $contact, $from - 1);
        $q = $this->journal($to, $from)->where('account_id', $account->id);
        if ($contact) {
            $q->where('contact_id', $contact);
        }
        $rows = $this->bounded($q->orderByRaw('(SELECT business_date_bs FROM journal_entries WHERE journal_entries.id = journal_lines.journal_entry_id AND journal_entries.tenant_id = journal_lines.tenant_id)')->orderBy('journal_entry_id')->orderBy('id'));
        $balance = $opening;
        $data = [];
        foreach ($rows as $line) {
            $entry = $this->a->requireRow('journal_entries', $line->journal_entry_id);
            $balance += $line->debit_paisa - $line->credit_paisa;
            $data[] = ['id' => $entry->id, 'business_date_bs' => $entry->business_date_bs, 'description' => $entry->description, 'source_type' => $entry->source_type, 'source_id' => $entry->source_id, 'received_paisa' => (int) $line->debit_paisa, 'paid_paisa' => (int) $line->credit_paisa, 'balance_paisa' => $balance];
        }

        return ['opening_paisa' => $opening, 'closing_paisa' => $balance, 'rows' => $data, 'channel' => $cash ? 'cash' : $account->system_key];
    }

    private function movements(int $from, int $to, array $input): array
    {
        $q = $this->a->rows('stock_movements')->whereBetween('business_date_bs', [$from, $to]);
        if (! empty($input['item_id'])) {
            $this->a->requireRow('items', $input['item_id']);
            $q->where('item_id', $input['item_id']);
        }

        return $this->bounded($q->orderBy('business_date_bs')->orderBy('id'))->all();
    }

    private function activity(int $from, int $to, string $report): array
    {
        $money = $report === 'money';
        $q = $this->a->rows($money ? 'payments' : 'documents')->where(fn ($q) => $q->whereBetween('business_date_bs', [$from, $to])->orWhereBetween('cancellation_date_bs', [$from, $to]));
        if (! $money) {
            $q->whereNotNull('posted_at')->whereIn('type', match ($report) {
                'sales' => ['sale', 'sale_return'], 'purchases' => ['purchase', 'purchase_return'], 'expenses' => ['expense']
            });
        }

        return $this->bounded($q->orderBy('business_date_bs')->orderBy('id'))->map(function ($row) use ($money, $from, $to) {
            $inPeriod = $row->business_date_bs >= $from && $row->business_date_bs <= $to;
            $cancelInPeriod = $row->cancellation_date_bs && $row->cancellation_date_bs >= $from && $row->cancellation_date_bs <= $to;

            return ['id' => $row->id, 'number' => $money ? 'PAY-'.$row->id : $row->number, 'type' => $money ? $row->kind : $row->type, 'business_date_bs' => $row->business_date_bs, 'status' => $row->status, 'amount_paisa' => (int) ($money ? $row->amount_paisa : $row->total_paisa) * (($inPeriod ? 1 : 0) - ($cancelInPeriod ? 1 : 0)) * (! $money && str_ends_with($row->type, '_return') ? -1 : 1)];
        })->all();
    }

    public function export(Request $r, string $tenant, string $report): StreamedResponse
    {
        $this->a->authorize(auth('tenant')->id(), ['owner', 'accountant']);
        $response = $this->show($r, $tenant, $report)->getData(true);
        $data = $response['data'];
        $rows = isset($data['rows']) ? $data['rows'] : (array_is_list($data) ? $data : [$data]);
        abort_if(count($rows) > 20000, 422, 'Export exceeds 20,000 rows.');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            if ($rows) {
                $keys = array_keys($rows[0]);
                fputcsv($out, $keys, ',', '"', '');
                foreach ($rows as $row) {
                    fputcsv($out, array_map(function ($value) {
                        $text = is_array($value) ? json_encode($value) : (string) $value;

                        return preg_match('/^[\s]*[=+@-]/u', $text) ? "'".$text : $text;
                    }, array_values($row)), ',', '"', '');
                }
            } fclose($out);
        }, $report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
