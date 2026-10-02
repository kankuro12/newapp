<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(private AccountingService $a) {}

    public function invoiceBalance(int $id, ?int $asOf = null): array
    {
        $date = $asOf ?? NepaliDate::today();
        $doc = $this->a->requireRow('documents', $id);
        $total = $doc->business_date_bs <= $date && $doc->posted_at && (! $doc->cancellation_date_bs || $doc->cancellation_date_bs > $date) ? (int) $doc->total_paisa : 0;
        $returned = (int) $this->a->rows('documents')->where('source_document_id', $id)->whereNotNull('posted_at')->where('business_date_bs', '<=', $date)->where(fn ($q) => $q->whereNull('cancellation_date_bs')->orWhere('cancellation_date_bs', '>', $date))->sum('total_paisa');
        $settled = 0;
        $allocations = $this->a->rows('payment_allocations')->where('document_id', $id)->get();
        foreach ($allocations as $allocation) {
            $payment = $this->a->requireRow('payments', $allocation->payment_id);
            if ($payment->business_date_bs <= $date && (! $payment->cancellation_date_bs || $payment->cancellation_date_bs > $date)) {
                $settled += (int) $allocation->amount_paisa * (in_array($payment->kind, ['receipt', 'supplier_payment']) ? 1 : -1);
            }
        }
        $due = $total - $returned - $settled;

        return ['signed_due_paisa' => $due, 'due_paisa' => max(0, $due), 'credit_paisa' => max(0, -$due), 'net_settled_paisa' => $settled, 'returned_paisa' => $returned];
    }

    public function post(int $actor, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'payment', $input, fn (Tenant $tenant) => ['table' => 'payments', 'id' => $this->create($actor, $tenant, $input)]);
    }

    public function create(int $actor, Tenant $tenant, array $input): int
    {
        $kind = $input['kind'];
        if (! in_array($kind, ['receipt', 'supplier_payment', 'customer_refund', 'supplier_refund', 'transfer'])) {
            $this->a->fail('Invalid payment kind.');
        }
        if ($kind !== 'receipt') {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
        }
        $date = NepaliDate::normalize($input['business_date_bs']);
        $this->a->assertOpenDate($tenant, $date);
        $amount = Money::parse($input['amount']);
        if (! $amount) {
            $this->a->fail('Enter positive amount.', 'amount');
        }
        if (empty($input['money_account_id'])) {
            $this->a->fail('Choose payment account.', 'money_account_id');
        }
        $money = $this->a->requireRow('accounts', $input['money_account_id']);
        if (! $money->is_money || $money->archived_at) {
            $this->a->fail('Choose active cash/bank account.');
        }
        $party = null;
        $lines = [];
        $allocations = [];
        if ($kind === 'transfer') {
            if (empty($input['destination_account_id'])) {
                $this->a->fail('Choose destination account.', 'destination_account_id');
            }
            $destination = $this->a->requireRow('accounts', $input['destination_account_id']);
            if ($destination->id === $money->id || ! $destination->is_money || $destination->archived_at) {
                $this->a->fail('Choose different destination cash/bank.');
            }
            $lines = [$this->a->line((int) $destination->id, $amount), $this->a->line((int) $money->id, -$amount)];
        } else {
            $party = $this->a->requireRow('contacts', $input['contact_id']);
            $customer = in_array($kind, ['receipt', 'customer_refund']);
            $refund = in_array($kind, ['customer_refund', 'supplier_refund']);
            if (($customer && ! $party->is_customer) || (! $customer && ! $this->a->payableParty($party))) {
                $this->a->fail('Party channel unavailable.');
            }
            $partyKey = $customer ? 'receivables' : 'payables';
            $moneyIn = in_array($kind, ['receipt', 'supplier_refund']);
            $lines = [$this->a->line((int) $money->id, $moneyIn ? $amount : -$amount), $this->a->line($partyKey, $moneyIn ? -$amount : $amount, (int) $party->id)];
            if ($refund) {
                $balance = $this->a->balance($this->a->account($partyKey), (int) $party->id);
                $available = $customer ? -$balance : $balance;
                $datedBalance = $this->a->balance($this->a->account($partyKey), (int) $party->id, $date);
                $available = min($available, $customer ? -$datedBalance : $datedBalance);
                if ($amount > $available) {
                    $this->a->fail('Refund exceeds available party credit.', 'amount');
                }
            }
            $supplied = $input['allocations'] ?? null;
            if ($supplied === null) {
                $remaining = $amount;
                $docs = $this->a->rows('documents')->where('contact_id', $party->id)->where('status', 'posted')->whereIn('type', $customer ? ['sale'] : ['purchase', 'expense'])->orderByRaw('COALESCE(due_date_bs,business_date_bs)')->orderBy('id')->get();
                foreach ($docs as $doc) {
                    if ($this->a->role($actor) === 'cashier' && $doc->created_by != $actor) {
                        continue;
                    } if ($doc->business_date_bs > $date) {
                        continue;
                    } $balance = $this->invoiceBalance((int) $doc->id);
                    $dated = $this->invoiceBalance((int) $doc->id, $date);
                    $balance['due_paisa'] = min($balance['due_paisa'], $dated['due_paisa']);
                    $balance['credit_paisa'] = min($balance['credit_paisa'], $dated['credit_paisa']);
                    $applied = min($remaining, $refund ? $balance['credit_paisa'] : $balance['due_paisa']);
                    if ($applied > 0) {
                        $allocations[] = ['document_id' => $doc->id, 'amount_paisa' => $applied];
                        $remaining -= $applied;
                    } if (! $remaining) {
                        break;
                    }
                }
            } else {
                foreach ($supplied as $row) {
                    $allocations[] = ['document_id' => (int) $row['document_id'], 'amount_paisa' => Money::parse($row['amount'])];
                }
            }
            if (count(array_unique(array_column($allocations, 'document_id'))) !== count($allocations)) {
                $this->a->fail('Duplicate selected bill.');
            }
            usort($allocations, fn ($x, $y) => $x['document_id'] <=> $y['document_id']);
            foreach ($allocations as $row) {
                $doc = $this->a->requireRow('documents', $row['document_id']);
                if ($doc->contact_id != $party->id || $doc->status !== 'posted' || ! in_array($doc->type, $customer ? ['sale'] : ['purchase', 'expense']) || $doc->business_date_bs > $date) {
                    $this->a->fail('Selected bill unavailable.');
                }
                if ($this->a->role($actor) === 'cashier' && $doc->created_by != $actor) {
                    abort(403);
                }
                $balance = $this->invoiceBalance((int) $doc->id);
                $dated = $this->invoiceBalance((int) $doc->id, $date);
                $balance['due_paisa'] = min($balance['due_paisa'], $dated['due_paisa']);
                $balance['credit_paisa'] = min($balance['credit_paisa'], $dated['credit_paisa']);
                if ($row['amount_paisa'] <= 0 || $row['amount_paisa'] > ($refund ? $balance['credit_paisa'] : $balance['due_paisa'])) {
                    $this->a->fail('Allocation exceeds bill amount.');
                }
            }
            $allocated = array_sum(array_column($allocations, 'amount_paisa'));
            if ($allocated > $amount || ($allocated < $amount && (empty($input['unallocated_confirmed']) || $this->a->role($actor) === 'cashier'))) {
                $this->a->fail('Review remaining money; explicitly confirm advance or starting-balance settlement.');
            }
        }
        $id = DB::table('payments')->insertGetId(['tenant_id' => $tenant->id, 'kind' => $kind, 'contact_id' => $party?->id, 'money_account_id' => $money->id, 'destination_account_id' => $kind === 'transfer' ? $destination->id : null, 'amount_paisa' => $amount, 'business_date_bs' => $date, 'reference' => $input['reference'] ?? null, 'notes' => $input['notes'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        $journal = $this->a->post($actor, $date, ['type' => 'payment', 'id' => $id], $lines, $kind, $input['overdraft_confirmed'] ?? false);
        $this->a->rows('payments')->where('id', $id)->update(['journal_id' => $journal]);
        foreach ($allocations as $row) {
            DB::table('payment_allocations')->insert(['tenant_id' => $tenant->id, 'payment_id' => $id, ...$row]);
        }

        return $id;
    }

    public function cancel(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'payment.cancel.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $old = $this->a->requireRow('payments', $id);
            if ($old->status === 'cancelled') {
                return ['table' => 'payments', 'id' => $id];
            }
            foreach ($this->a->rows('payment_allocations')->where('payment_id', $id)->get() as $allocation) {
                $balance = $this->invoiceBalance((int) $allocation->document_id);
                if (in_array($old->kind, ['receipt', 'supplier_payment']) && $balance['net_settled_paisa'] < $allocation->amount_paisa) {
                    $this->a->fail('Cancel related refunds first.');
                }
            }
            $date = NepaliDate::normalize($input['business_date_bs']);
            $journal = $this->a->reverse($actor, (int) $old->journal_id, $date, $input['reason'], $input['overdraft_confirmed'] ?? false);
            $this->a->rows('payments')->where('id', $id)->update(['status' => 'cancelled', 'cancellation_date_bs' => $date, 'cancellation_reason' => $input['reason'], 'cancelled_at' => now(), 'cancelled_by' => $actor, 'reversal_journal_id' => $journal]);

            return ['table' => 'payments', 'id' => $id];
        });
    }
}
