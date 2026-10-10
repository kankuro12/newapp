<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FulfilmentService
{
    public function __construct(private AccountingService $a, private InventoryService $stock, private WorkflowService $workflows) {}

    public function active(int $workflow): bool
    {
        return $this->a->rows('workflow_fulfilments')->where('workflow_id', $workflow)->where('status', 'posted')->exists()
            || $this->a->rows('documents')->where('workflow_id', $workflow)->where('status', 'posted')->exists();
    }

    private function order(int $actor, int $id): object
    {
        $order = $this->workflows->row($actor, $id);
        $this->a->authorize($actor, $order->kind === 'purchase_order' ? ['owner', 'manager', 'accountant'] : ['owner', 'manager', 'accountant', 'cashier']);
        abort_unless(in_array($order->kind, ['sales_order', 'purchase_order']), 409, 'Create an order before recording fulfilment.');

        return $order;
    }

    private function postedLines(int $workflow): Collection
    {
        return $this->a->rows('workflow_fulfilment_lines')->whereIn('fulfilment_id', $this->a->rows('workflow_fulfilments')->where('workflow_id', $workflow)->where('status', 'posted')->select('id'))->get();
    }

    private function activeAllocations(int $workflow): Collection
    {
        return $this->a->rows('workflow_bill_allocations')->where('workflow_id', $workflow)->whereIn('document_line_id', $this->a->rows('document_lines')->whereIn('document_id', $this->a->rows('documents')->where('workflow_id', $workflow)->where('status', 'posted')->select('id'))->select('id'))->get();
    }

    private function available(object $line, Collection $posted, Collection $allocations): array
    {
        $returned = $posted->where('source_line_id', $line->id);
        $billed = $allocations->where('fulfilment_line_id', $line->id);

        return ['qty' => (int) $line->qty_milli - (int) $returned->sum('qty_milli') - (int) $billed->sum('qty_milli'), 'value' => (int) $line->clearing_value_paisa - (int) $returned->sum('clearing_value_paisa') - (int) $billed->sum('clearing_value_paisa')];
    }

    public function progress(int $actor, int $workflow): array
    {
        $order = $this->workflows->row($actor, $workflow);
        $posted = $this->postedLines($workflow);
        $confirmed = $this->a->rows('workflow_fulfilments')->where('workflow_id', $workflow)->where('status', 'posted')->where('handover_confirmed', true)->pluck('id');
        $transit = $this->a->rows('workflow_fulfilments')->where('workflow_id', $workflow)->where('status', 'posted')->where('handover_confirmed', false)->pluck('id');
        $allocations = $this->activeAllocations($workflow);
        $lines = [];
        foreach (json_decode($order->lines, true) as $index => $original) {
            $selected = $posted->where('position', $index + 1);
            $completed = (int) $selected->whereIn('fulfilment_id', $confirmed)->sum(fn ($line) => $line->source_line_id ? -(int) $line->qty_milli : (int) $line->qty_milli);
            $shipped = (int) $selected->whereIn('fulfilment_id', $transit)->sum('qty_milli');
            $billed = (int) $allocations->where('position', $index + 1)->sum('qty_milli');
            $billLines = $allocations->where('position', $index + 1)->pluck('document_line_id')->unique();
            $packed = (int) $this->a->rows('workflow_package_lines')->whereIn('document_line_id', $billLines)->whereIn('package_id', $this->a->rows('workflow_packages')->where('workflow_id', $workflow)->where('status', 'packed')->select('id'))->sum('qty_milli');
            $billedReturns = (int) $this->a->rows('document_lines')->whereIn('source_line_id', $billLines)->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->sum('qty_milli');
            $unfulfilledCredits = (int) $this->a->rows('workflow_return_allocations')->whereIn('document_line_id', $billLines)->whereNull('dispatch_allocation_id')->whereIn('return_document_line_id', $this->a->rows('document_lines')->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->select('id'))->sum('qty_milli');
            $transitCredits = $this->a->rows('workflow_return_allocations')->whereIn('document_line_id', $billLines)->where('return_mode', 'transit')->whereIn('return_document_line_id', $this->a->rows('document_lines')->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->select('id'))->get();
            foreach ($transitCredits as $credit) {
                $dispatch = $this->a->requireRow('workflow_dispatch_allocations', $credit->dispatch_allocation_id);
                $physical = $this->a->requireRow('workflow_fulfilment_lines', $dispatch->fulfilment_line_id);
                if ($confirmed->contains($physical->fulfilment_id)) {
                    $completed -= (int) $credit->qty_milli;
                } else {
                    $shipped -= (int) $credit->qty_milli;
                }
            }
            $transitQty = (int) $transitCredits->sum('qty_milli');
            $billable = ($order->fulfilment_policy ?? null) === 'bill_first' ? $original['qty_milli'] - $billed : $completed - $billed;
            $lines[] = ['position' => $index + 1, 'item_id' => $original['item_id'], 'description' => $original['description'], 'unit_snapshot' => $original['unit_snapshot'], 'ordered_qty_milli' => $original['qty_milli'], 'completed_qty_milli' => $completed, 'shipped_qty_milli' => $shipped, 'packed_qty_milli' => $packed, 'remaining_qty_milli' => max(0, $original['qty_milli'] - $completed - $unfulfilledCredits - $transitQty), 'billed_qty_milli' => $billed, 'billed_returned_qty_milli' => $billedReturns, 'credited_unfulfilled_qty_milli' => $unfulfilledCredits, 'credited_transit_qty_milli' => $transitQty, 'billable_qty_milli' => max(0, $billable)];
        }

        $bills = $this->a->rows('documents')->where('workflow_id', $workflow);
        if ($this->a->role($actor) === 'cashier') {
            $bills->where('created_by', $actor);
        }

        return ['active' => $this->active($workflow), 'lines' => $lines, 'activity' => $this->a->rows('workflow_fulfilments')->where('workflow_id', $workflow)->orderByDesc('id')->get()->map(fn ($row) => $this->data($actor, (int) $row->id))->all(), 'packages' => $this->a->rows('workflow_packages')->where('workflow_id', $workflow)->orderByDesc('id')->get()->map(fn ($row) => $this->packageData($actor, (int) $row->id))->all(), 'bills' => $bills->orderByDesc('id')->get(['id', 'number', 'status', 'business_date_bs', 'total_paisa'])->all()];
    }

    public function assertBilledReplayAccess(int $actor, int $id): void
    {
        $stage = $this->a->requireRow('workflow_fulfilments', $id);
        $this->order($actor, (int) $stage->workflow_id);
        if ($this->a->role($actor) !== 'cashier') {
            return;
        }
        // Replays retain historical allocations, including cancelled physical sources.
        $sources = $this->a->rows('workflow_dispatch_allocations')->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $id)->select('id'));
        abort_unless((clone $sources)->exists(), 403);
        $bills = $this->a->rows('documents')->whereIn('id', $this->a->rows('document_lines')->whereIn('id', $sources->select('document_line_id'))->select('document_id'));
        abort_if($bills->where(fn ($query) => $query->where('created_by', '!=', $actor)->orWhere('type', '!=', 'sale')->orWhere('workflow_id', '!=', $stage->workflow_id))->exists(), 403);
    }

    public function data(int $actor, int $id): array
    {
        $row = $this->a->requireRow('workflow_fulfilments', $id);
        $this->order($actor, (int) $row->workflow_id);
        $data = (array) $row;
        foreach (['party_snapshot', 'business_snapshot'] as $snapshot) {
            $data[$snapshot] = empty($data[$snapshot]) ? null : json_decode($data[$snapshot], true);
        }
        $data['number'] = ['delivery' => 'DEL', 'receipt' => 'REC', 'delivery_return' => 'DRT', 'receipt_return' => 'RRT'][$row->kind].'-'.str_pad((string) $row->sequence, 6, '0', STR_PAD_LEFT);
        $posted = $this->postedLines((int) $row->workflow_id);
        $allocations = $this->activeAllocations((int) $row->workflow_id);
        $data['lines'] = $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $id)->orderBy('position')->get()->map(function ($line) use ($actor, $row, $posted, $allocations) {
            $billed = $this->a->rows('workflow_dispatch_allocations')->where('fulfilment_line_id', $line->id)->exists();
            $available = $row->status === 'posted' && ! $line->source_line_id && ! $billed ? $this->available($line, $posted, $allocations)['qty'] : 0;
            $line = (array) $line;
            $line['billable_qty_milli'] = $available;
            $line['returnable_qty_milli'] = $available;
            $line['item_snapshot'] = json_decode($line['item_snapshot'], true);
            if ($this->a->role($actor) === 'cashier') {
                unset($line['inventory_value_paisa'], $line['clearing_value_paisa']);
            }

            return $line;
        })->all();
        if ($this->a->role($actor) === 'cashier') {
            unset($data['inventory_value_paisa'], $data['clearing_value_paisa'], $data['journal_id'], $data['reversal_journal_id'], $data['recognition_journal_id']);
        }

        return $data;
    }

    private function plan(int $actor, int $workflow, array $input): array
    {
        $order = $this->order($actor, $workflow);
        abort_if(($order->fulfilment_policy ?? null) === 'bill_first', 409, 'Use the billed source for this order.');
        abort_unless($order->version == $input['version'] && ! in_array($order->status, ['cancelled', 'converted', 'rejected']), 409, 'Order changed or closed. Refresh before continuing.');
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());
        $date = NepaliDate::normalize($input['business_date_bs']);
        $this->a->assertOpenDate($tenant, $date);
        if ($date < $order->business_date_bs) {
            $this->a->fail('Date precedes order.', 'business_date_bs');
        }
        $party = $this->a->requireRow('contacts', $order->contact_id);
        $vat = $order->kind === 'purchase_order' && (bool) ($input['vat_recoverable'] ?? false);
        if ($order->kind === 'purchase_order' && $order->fulfilment_vat_recoverable !== null) {
            abort_unless((bool) $order->fulfilment_vat_recoverable === $vat, 409, 'Receipt cost treatment is already fixed.');
        }
        $source = ! empty($input['source_id']) ? $this->a->requireRow('workflow_fulfilments', $input['source_id']) : null;
        if ($source) {
            abort_unless($source->workflow_id == $workflow, 404);
            abort_unless($source->status === 'posted' && in_array($source->kind, ['delivery', 'receipt']), 409, 'Return an active original fulfilment.');
            if ($date < $source->business_date_bs) {
                $this->a->fail('Return date precedes source.');
            }
        }
        if (! $source && ($party->archived_at || $party->is_system || ($order->kind === 'sales_order' ? ! $party->is_customer : ! $party->is_supplier))) {
            $this->a->fail('Choose an active named customer or supplier before staging.');
        }
        if (! $source && $vat && ! $tenant->tax_recording_enabled) {
            $this->a->fail('Recoverable tax requires tax recording.');
        }
        $kind = ($order->kind === 'purchase_order' ? 'receipt' : 'delivery').($source ? '_return' : '');
        $posted = $this->postedLines($workflow);
        $allocations = $this->activeAllocations($workflow);
        $originals = json_decode($order->lines, true);
        $lines = [];
        $pools = [];
        foreach ($input['lines'] as $entry) {
            $position = (int) $entry['position'];
            $original = $originals[$position - 1] ?? null;
            if (! $original) {
                $this->a->fail('Choose an original order line.');
            }
            $item = $this->a->requireRow('items', $original['item_id']);
            if ($item->kind !== $original['item_kind'] || $item->pos_unit !== $original['pos_unit'] || (! $source && ($item->archived_at || $item->unit_label !== $original['unit_snapshot']))) {
                $this->a->fail('Item unit or kind changed. Review the order.');
            }
            $qty = Money::quantity($entry['qty']);
            $selected = $posted->where('position', $position);
            $completed = (int) $selected->sum(fn ($line) => $line->source_line_id ? -(int) $line->qty_milli : (int) $line->qty_milli);
            $sourceLine = $source ? $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $source->id)->where('position', $position)->first() : null;
            if ($source && ! $sourceLine) {
                $this->a->fail('Line absent from original fulfilment.');
            }
            $returned = $sourceLine ? $posted->where('source_line_id', $sourceLine->id) : collect();
            $previousQty = $sourceLine ? (int) $returned->sum('qty_milli') : $completed;
            $limit = $sourceLine ? (int) $sourceLine->qty_milli : (int) $original['qty_milli'];
            if ($qty <= 0 || $qty > $limit - $previousQty) {
                $this->a->fail('Quantity must be positive and within remaining quantity.', 'lines');
            }
            $available = $sourceLine ? $this->available($sourceLine, $posted, $allocations) : null;
            if ($available && $qty > $available['qty']) {
                $this->a->fail('Use the original bill to return already billed quantity.', 'lines');
            }
            $clearing = 0;
            $inventory = 0;
            if ($item->kind === 'stock') {
                if ($date < ($tenant->last_stock_date_bs ?? 0)) {
                    $this->a->fail('Date precedes latest stock activity.', 'business_date_bs');
                }
                if ($sourceLine) {
                    $clearing = Money::multiplyDivide((int) $sourceLine->clearing_value_paisa, $previousQty + $qty, $limit) - (int) $returned->sum('clearing_value_paisa');
                    $clearing = $qty === $available['qty'] ? $available['value'] : min($clearing, $available['value']);
                } elseif ($kind === 'receipt') {
                    $originalCost = (int) $original['net_base_paisa'] + ($vat ? 0 : (int) $original['tax_paisa']);
                    $previousCost = (int) $selected->sum(fn ($line) => $line->source_line_id ? -(int) $line->clearing_value_paisa : (int) $line->clearing_value_paisa);
                    // A source return can leave a rounded paisa ahead of the order's cumulative target.
                    // Carry it into later receipts; the final quantity still consumes the exact residual.
                    $clearing = max(0, Money::multiplyDivide($originalCost, $previousQty + $qty, $limit) - $previousCost);
                }
                if (! isset($pools[$item->id])) {
                    $pool = $this->a->rows('inventory_balances')->where('item_id', $item->id)->first();
                    $pools[$item->id] = ['qty' => (int) ($pool?->qty_milli ?? 0), 'value' => (int) ($pool?->value_paisa ?? 0)];
                }
                $pool = &$pools[$item->id];
                if (in_array($kind, ['delivery', 'receipt_return'])) {
                    $inventory = $this->stock->outflowCost($pool['qty'], $pool['value'], $qty);
                    if ($kind === 'delivery') {
                        $clearing = $inventory;
                    }
                    $pool['qty'] -= $qty;
                    $pool['value'] -= $inventory;
                } else {
                    $inventory = $clearing;
                    $pool['qty'] += $qty;
                    $pool['value'] += $inventory;
                }
                unset($pool);
                if ($clearing < 0 || $inventory < 0) {
                    $this->a->fail('Cost allocation requires review.');
                }
            }
            $lines[] = ['position' => $position, 'item_id' => $item->id, 'source_line_id' => $sourceLine?->id, 'qty_milli' => $qty, 'inventory_value_paisa' => $inventory, 'clearing_value_paisa' => $clearing, 'item_snapshot' => $original];
        }
        $plan = ['workflow_id' => $workflow, 'workflow_version' => (int) $order->version, 'business_date_bs' => $date, 'source_id' => $source?->id, 'kind' => $kind, 'vat_recoverable' => $vat, 'lines' => $lines, 'inventory_value_paisa' => array_sum(array_column($lines, 'inventory_value_paisa')), 'clearing_value_paisa' => array_sum(array_column($lines, 'clearing_value_paisa')), 'reference' => $input['reference'] ?? null, 'notes' => $input['notes'] ?? null, 'pools_after' => $pools];
        $plan['fingerprint'] = hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), config('app.key'));

        return $plan;
    }

    public function preview(int $actor, int $workflow, array $input): array
    {
        $plan = $this->plan($actor, $workflow, $input);
        unset($plan['pools_after']);
        if ($this->a->role($actor) === 'cashier') {
            unset($plan['inventory_value_paisa'], $plan['clearing_value_paisa']);
            foreach ($plan['lines'] as &$line) {
                unset($line['inventory_value_paisa'], $line['clearing_value_paisa']);
            }
            unset($line);
        }

        return $plan;
    }

    private function billPlan(int $actor, int $workflow, array $input, bool $ordered = false): array
    {
        $order = $this->order($actor, $workflow);
        $policy = $ordered ? 'bill_first' : 'delivery_first';
        abort_if(($order->fulfilment_policy ?? null) && $order->fulfilment_policy !== $policy, 409, 'Order fulfilment policy is already fixed.');
        abort_unless($order->version == $input['version'] && ! in_array($order->status, ['cancelled', 'converted', 'rejected']), 409, 'Order changed or closed. Refresh before billing.');
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());
        $date = NepaliDate::normalize($input['business_date_bs']);
        $this->a->assertOpenDate($tenant, $date);
        $party = $this->a->requireRow('contacts', $order->contact_id);
        if ($party->archived_at || $party->is_system || ($order->kind === 'purchase_order' ? ! $party->is_supplier : ! $party->is_customer)) {
            $this->a->fail('Choose an active named party before billing.');
        }
        $vat = $order->kind === 'purchase_order' && (bool) ($ordered ? ($input['vat_recoverable'] ?? $order->fulfilment_vat_recoverable ?? false) : $order->fulfilment_vat_recoverable);
        if (isset($input['vat_recoverable']) && (! $ordered || $order->fulfilment_vat_recoverable !== null)) {
            $fixedVat = $ordered ? (bool) $order->fulfilment_vat_recoverable : $vat;
            abort_unless((bool) $input['vat_recoverable'] === $fixedVat, 409, 'Bill tax treatment must match its receipts.');
        }
        if ($date < $order->business_date_bs) {
            $this->a->fail('Bill date precedes order.', 'business_date_bs');
        }
        $posted = $this->postedLines($workflow);
        $allocated = $this->activeAllocations($workflow);
        $originals = json_decode($order->lines, true);
        $selections = [];
        $quantities = [];
        foreach ($input['lines'] as $entry) {
            if ($ordered) {
                $position = (int) $entry['position'];
                $original = $originals[$position - 1] ?? null;
                if (! $original) {
                    $this->a->fail('Choose an original order line.');
                }
                $qty = Money::quantity($entry['qty']);
                $previous = (int) $allocated->where('position', $position)->sum('qty_milli');
                if ($qty <= 0 || $qty + $previous > (int) $original['qty_milli'] || isset($quantities[$position])) {
                    $this->a->fail('Quantity exceeds the unbilled original order.', 'lines');
                }
                $quantities[$position] = $qty;
                $selections[] = ['fulfilment_line_id' => null, 'position' => $position, 'qty_milli' => $qty, 'clearing_value_paisa' => 0];

                continue;
            }
            $source = $this->a->requireRow('workflow_fulfilment_lines', $entry['fulfilment_line_id']);
            $stage = $this->a->requireRow('workflow_fulfilments', $source->fulfilment_id);
            abort_unless($stage->workflow_id == $workflow, 404);
            abort_unless($stage->status === 'posted' && ! $source->source_line_id, 409, 'Choose an active original delivery or receipt.');
            if ($date < $order->business_date_bs || $date < $stage->business_date_bs) {
                $this->a->fail('Bill date precedes source delivery/receipt.', 'business_date_bs');
            }
            $qty = Money::quantity($entry['qty']);
            $remaining = $this->available($source, $posted, $allocated);
            if ($qty <= 0 || $qty > $remaining['qty'] || $remaining['value'] < 0) {
                $this->a->fail('Bill quantity exceeds unbilled delivery/receipt.', 'lines');
            }
            $cost = $qty === $remaining['qty'] ? $remaining['value'] : Money::multiplyDivide($remaining['value'], $qty, $remaining['qty']);
            $position = (int) $source->position;
            $quantities[$position] = ($quantities[$position] ?? 0) + $qty;
            $selections[] = ['fulfilment_line_id' => (int) $source->id, 'stage_version' => (int) $stage->version, 'position' => $position, 'qty_milli' => $qty, 'clearing_value_paisa' => $cost];
        }
        ksort($quantities);
        $lines = [];
        $rounding = [];
        foreach ($quantities as $position => $qty) {
            $original = $originals[$position - 1];
            $item = $this->a->requireRow('items', $original['item_id']);
            if ($item->kind !== $original['item_kind'] || $item->pos_unit !== $original['pos_unit'] || ($ordered && ($item->archived_at || $item->unit_label !== $original['unit_snapshot']))) {
                $this->a->fail('Item base unit or kind changed. Review source order.');
            }
            if (! $tenant->tax_recording_enabled && ($vat || $original['tax_bps'])) {
                $this->a->fail('Enable tax recording before posting a taxable bill.');
            }
            $previous = $allocated->where('position', $position);
            $previousQty = (int) $previous->sum('qty_milli');
            $limit = (int) $original['qty_milli'];
            if ($qty + $previousQty > $limit) {
                $this->a->fail('Quantity exceeds unbilled original order.');
            }
            $priorLines = $this->a->rows('document_lines')->whereIn('id', $previous->pluck('document_line_id')->unique())->get();
            $line = ['position' => count($lines) + 1, 'item_id' => $item->id, 'expense_category_id' => null, 'description' => $original['description'], 'unit_snapshot' => $original['unit_snapshot'], 'qty_milli' => $qty, 'unit_price_paisa' => $original['unit_price_paisa'], 'tax_category' => $original['tax_category'], 'tax_bps' => $original['tax_bps']];
            foreach (['net_base_paisa', 'tax_paisa', 'line_discount_paisa', 'invoice_discount_paisa'] as $component) {
                $line[$component] = Money::multiplyDivide((int) $original[$component], $previousQty + $qty, $limit) - (int) $priorLines->sum($component);
                if ($line[$component] < 0) {
                    $this->a->fail('Source allocation requires review. Cancel later bills first.');
                }
            }
            $line['gross_paisa'] = $line['net_base_paisa'] + $line['line_discount_paisa'] + $line['invoice_discount_paisa'];
            $line['total_paisa'] = $line['net_base_paisa'] + $line['tax_paisa'];
            $rounding[] = ['position' => $line['position'], 'order_position' => $position, 'gross_rounding_paisa' => $line['gross_paisa'] - Money::multiplyDivide($qty, (int) $line['unit_price_paisa'], 1000), 'tax_rounding_paisa' => $line['tax_paisa'] - Money::multiplyDivide($line['net_base_paisa'], (int) $line['tax_bps'], 10000)];
            if (! $ordered && $order->kind === 'purchase_order' && $item->kind === 'stock') {
                $heldCost = array_sum(array_column(array_filter($selections, fn ($selection) => $selection['position'] === $position), 'clearing_value_paisa'));
                $rounding[array_key_last($rounding)]['cost_variance_paisa'] = ($vat ? $line['net_base_paisa'] : $line['total_paisa']) - $heldCost;
            }
            foreach ($selections as &$selection) {
                if ($selection['position'] === $position) {
                    $selection['invoice_position'] = $line['position'];
                }
            }
            unset($selection);
            $lines[] = $line;
        }
        $totals = ['lines' => $lines];
        foreach (['gross_paisa' => 'subtotal_paisa', 'line_discount_paisa' => 'line_discount_paisa', 'invoice_discount_paisa' => 'invoice_discount_paisa', 'tax_paisa' => 'tax_paisa', 'total_paisa' => 'total_paisa'] as $component => $key) {
            $totals[$key] = array_sum(array_column($lines, $component));
        }
        if ($totals['total_paisa'] <= 0 || $totals['total_paisa'] > 10000000000) {
            $this->a->fail('Bill total must be positive and within limit. Include zero-value lines with a positive bill.');
        }
        $paid = Money::parse($input['paid_now']);
        if ($paid > $totals['total_paisa']) {
            $this->a->fail('Paid amount exceeds bill total.', 'paid_now');
        }
        $moneyAccount = ! empty($input['money_account_id']) ? $this->a->requireRow('accounts', $input['money_account_id']) : null;
        if (($moneyAccount && (! $moneyAccount->is_money || $moneyAccount->archived_at)) || ($paid && ! $moneyAccount)) {
            $this->a->fail('Choose an active cash/bank account.', 'money_account_id');
        }
        $type = $order->kind === 'purchase_order' ? 'purchase' : 'sale';
        $due = app(PartyService::class)->dueDate($party, $type, $date, $input['due_date_bs'] ?? null);
        if ($due < $date) {
            $this->a->fail('Due date precedes bill date.');
        }
        $saved = json_decode($order->bill_input, true);
        $snapshot = ['id' => $workflow, 'number' => ($type === 'purchase' ? 'PO' : 'SO').'-'.str_pad((string) $order->sequence, 6, '0', STR_PAD_LEFT), 'version' => (int) $order->version, 'allocation' => 'cumulative_source_order', 'lines' => $rounding, 'offer' => $saved['basket_offer_snapshot'] ?? null];
        $terms = ['type' => $type, 'contact_id' => (int) $party->id, 'business_date_bs' => $date, 'due_date_bs' => $due, 'paid_now' => Money::format($paid), 'money_account_id' => $moneyAccount?->id, 'vat_recoverable' => $vat, 'supplier_bill_number' => $input['supplier_bill_number'] ?? null, 'supplier_bill_date_bs' => empty($input['supplier_bill_date_bs']) ? null : NepaliDate::normalize($input['supplier_bill_date_bs']), 'overdraft_confirmed' => (bool) ($input['overdraft_confirmed'] ?? false), 'notes' => $input['notes'] ?? null];
        $plan = [...$totals, 'fulfilment_policy' => $policy, 'workflow_id' => $workflow, 'workflow_version' => (int) $order->version, 'allocations' => $selections, 'source_order_snapshot' => $snapshot, 'terms' => $terms, 'context' => ['tenant_data_version' => (int) $tenant->data_version, 'credit_limit_paisa' => $party->credit_limit_paisa, 'money_balance_paisa' => $moneyAccount ? $this->a->balance((int) $moneyAccount->id, null, $date) : null]];
        $plan['fingerprint'] = hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), config('app.key'));

        return $plan;
    }

    public function billPreview(int $actor, int $workflow, array $input, bool $ordered = false): array
    {
        $plan = $this->billPlan($actor, $workflow, $input, $ordered);
        unset($plan['context']);
        foreach ($plan['allocations'] as &$allocation) {
            unset($allocation['clearing_value_paisa']);
        }
        unset($allocation);

        return $plan;
    }

    public function bill(int $actor, int $workflow, array $input, string $uuid, bool $ordered = false): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.bill.'.($ordered ? 'ordered.' : '').$workflow, $input, function (Tenant $tenant) use ($actor, $workflow, $input, $ordered) {
            $plan = $this->billPlan($actor, $workflow, $input, $ordered);
            abort_unless(hash_equals($plan['fingerprint'], $input['expected_fingerprint']) && (string) $plan['total_paisa'] === $input['expected_total_paisa'], 409, 'Bill context changed. Review again.');
            $order = $this->order($actor, $workflow);
            $result = app(DocumentService::class)->postStaged($actor, $tenant, $order, $plan);
            $this->a->rows('business_workflows')->where('id', $workflow)->update(['version' => $order->version + 1, 'fulfilment_policy' => $plan['fulfilment_policy'], 'fulfilment_vat_recoverable' => $plan['terms']['vat_recoverable'], 'updated_at' => now()]);

            return $result;
        });
    }

    private function dispatches(int $documentLine): Collection
    {
        return $this->a->rows('workflow_dispatch_allocations')->where('document_line_id', $documentLine)->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->whereIn('fulfilment_id', $this->a->rows('workflow_fulfilments')->where('status', 'posted')->select('id'))->select('id'))->get();
    }

    private function credits(int $documentLine): Collection
    {
        return $this->a->rows('workflow_return_allocations')->where('document_line_id', $documentLine)->whereIn('return_document_line_id', $this->a->rows('document_lines')->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->select('id'))->get();
    }

    private function packed(int $documentLine, ?int $exceptPackage = null): int
    {
        return (int) $this->a->rows('workflow_package_lines')->where('document_line_id', $documentLine)->whereIn('package_id', $this->a->rows('workflow_packages')->where('status', 'packed')->when($exceptPackage, fn ($query) => $query->where('id', '!=', $exceptPackage))->select('id'))->sum('qty_milli');
    }

    private function pendingSources(object $line): Collection
    {
        return $this->dispatches((int) $line->id)->concat($this->credits((int) $line->id)->whereNull('dispatch_allocation_id'));
    }

    private function recognitionContext(object $line): array
    {
        $credits = $this->credits((int) $line->id);
        $transit = $credits->where('return_mode', 'transit');
        $heldCredits = $credits->whereIn('return_mode', ['unfulfilled', 'transit']);
        $qty = (int) $heldCredits->sum('qty_milli');
        $recognized = 0;
        foreach ($this->dispatches((int) $line->id) as $source) {
            $physical = $this->a->requireRow('workflow_fulfilment_lines', $source->fulfilment_line_id);
            $stage = $this->a->requireRow('workflow_fulfilments', $physical->fulfilment_id);
            if ($stage->handover_confirmed) {
                $qty += (int) $source->qty_milli - (int) $transit->where('dispatch_allocation_id', $source->id)->sum('qty_milli');
                $recognized += (int) $source->recognition_sales_base_paisa;
            }
        }
        $released = (int) $heldCredits->sum('sales_base_paisa');

        return ['qty_milli' => $qty, 'sales_base_paisa' => $recognized, 'held_credit_paisa' => $released, 'held_left_paisa' => max(0, (int) $line->net_base_paisa - $recognized - $released)];
    }

    private function recognitionShare(object $line, int $qty): int
    {
        $context = $this->recognitionContext($line);
        $target = Money::multiplyDivide((int) $line->net_base_paisa, $context['qty_milli'] + $qty, (int) $line->qty_milli);

        return max(0, $target - $context['sales_base_paisa'] - $context['held_credit_paisa']);
    }

    private function remainingBilled(object $line, ?int $exceptPackage = null): int
    {
        return (int) $line->qty_milli - (int) $this->pendingSources($line)->sum('qty_milli') - $this->packed((int) $line->id, $exceptPackage);
    }

    public function billLineProgress(int $actor, object $bill, object $line): array
    {
        $this->order($actor, (int) $bill->workflow_id);
        $credits = $this->credits((int) $line->id);
        $sources = $this->dispatches((int) $line->id)->map(function ($source) use ($credits) {
            $physicalLine = $this->a->requireRow('workflow_fulfilment_lines', $source->fulfilment_line_id);
            $stage = $this->a->requireRow('workflow_fulfilments', $physicalLine->fulfilment_id);

            $remaining = (int) $source->qty_milli - (int) $credits->where('dispatch_allocation_id', $source->id)->sum('qty_milli');

            return ['id' => (int) $source->id, 'fulfilment_id' => (int) $stage->id, 'business_date_bs' => (int) $stage->business_date_bs, 'handover_confirmed' => (bool) $stage->handover_confirmed, 'qty_milli' => (int) $source->qty_milli, 'returnable_qty_milli' => $stage->handover_confirmed ? $remaining : 0, 'undelivered_returnable_qty_milli' => $stage->handover_confirmed ? 0 : $remaining];
        })->all();

        return ['unfulfilled_qty_milli' => max(0, $this->remainingBilled($line)), 'packed_qty_milli' => $this->packed((int) $line->id), 'credited_unfulfilled_qty_milli' => (int) $credits->whereNull('dispatch_allocation_id')->sum('qty_milli'), 'credited_transit_qty_milli' => (int) $credits->where('return_mode', 'transit')->sum('qty_milli'), 'fulfilment_sources' => $sources];
    }

    private function pendingShare(object $line, object $bill, int $qty): array
    {
        $consumed = $this->pendingSources($line);
        $after = (int) $consumed->sum('qty_milli') + $qty;
        $base = Money::multiplyDivide((int) $line->net_base_paisa, $after, (int) $line->qty_milli) - (int) $consumed->sum('sales_base_paisa');
        $value = $bill->type === 'purchase' ? (int) ($bill->vat_recoverable ? $line->net_base_paisa : $line->total_paisa) : 0;
        $held = Money::multiplyDivide($value, $after, (int) $line->qty_milli) - (int) $consumed->sum('pending_value_paisa');

        // Financial credits can be one paisa ahead of this physical quantity target.
        // Carry that rounding forward; final fulfilment consumes only the exact residual.
        return ['sales_base_paisa' => max(0, $base), 'pending_value_paisa' => max(0, $held)];
    }

    private function billedPlan(int $actor, int $workflow, array $input, ?int $package = null): array
    {
        $order = $this->order($actor, $workflow);
        abort_unless(($order->fulfilment_policy ?? null) === 'bill_first' && $order->version == $input['version'] && ! in_array($order->status, ['cancelled', 'converted', 'rejected']), 409, 'Refresh an active bill-first order.');
        abort_unless($package !== null || ($input['handover_confirmed'] ?? false) === true, 422, 'Confirm actual customer handover or completed receipt/work.');
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());
        $date = NepaliDate::normalize($input['business_date_bs']);
        $this->a->assertOpenDate($tenant, $date);
        $party = $this->a->requireRow('contacts', $order->contact_id);
        if ($party->archived_at || $party->is_system || ($order->kind === 'purchase_order' ? ! $party->is_supplier : ! $party->is_customer)) {
            $this->a->fail('Use an active named party.');
        }
        $originals = json_decode($order->lines, true);
        $allocations = [];
        $groups = [];
        foreach ($input['lines'] as $entry) {
            $line = $this->a->requireRow('document_lines', $entry['document_line_id']);
            $bill = $this->a->requireRow('documents', $line->document_id);
            abort_unless($bill->workflow_id == $workflow && ($bill->fulfilment_policy ?? null) === 'bill_first', 404);
            abort_unless($bill->status === 'posted' && $bill->type === ($order->kind === 'purchase_order' ? 'purchase' : 'sale'), 409, 'Use an active source bill.');
            if ($this->a->role($actor) === 'cashier') {
                abort_unless($bill->created_by == $actor, 403);
            }
            if ($date < $bill->business_date_bs || $date < $order->business_date_bs) {
                $this->a->fail('Date precedes source order/bill.', 'business_date_bs');
            }
            $source = $this->a->rows('workflow_bill_allocations')->where('document_line_id', $line->id)->where('workflow_id', $workflow)->whereNull('fulfilment_line_id')->first();
            abort_unless($source, 409, 'Missing ordered-quantity source.');
            $qty = Money::quantity($entry['qty']);
            if ($qty <= 0 || $qty > $this->remainingBilled($line, $package) || isset($allocations[$line->id])) {
                $this->a->fail('Quantity exceeds unfulfilled, unpacked billed quantity.', 'lines');
            }
            $position = (int) $source->position;
            $original = $originals[$position - 1];
            $item = $this->a->requireRow('items', $original['item_id']);
            if ($line->item_id != $item->id || $item->archived_at || $item->kind !== $original['item_kind'] || $item->pos_unit !== $original['pos_unit'] || $item->unit_label !== $original['unit_snapshot']) {
                $this->a->fail('Item unit/kind changed or item archived.');
            }
            $values = $this->pendingShare($line, $bill, $qty);
            if ($bill->type === 'sale' && $package === null) {
                $values['sales_base_paisa'] = $this->recognitionShare($line, $qty);
            }
            $allocations[$line->id] = ['document_line_id' => (int) $line->id, 'bill_version' => (int) $bill->version, 'position' => $position, 'qty_milli' => $qty, ...$values];
            $groups[$position] ??= ['position' => $position, 'item_id' => (int) $item->id, 'source_line_id' => null, 'qty_milli' => 0, 'inventory_value_paisa' => 0, 'clearing_value_paisa' => 0, 'item_snapshot' => $original];
            $groups[$position]['qty_milli'] += $qty;
            $groups[$position]['clearing_value_paisa'] += $values['pending_value_paisa'];
        }
        ksort($groups);
        $pools = [];
        $purchase = $order->kind === 'purchase_order';
        foreach ($groups as $position => &$group) {
            if ($group['item_snapshot']['item_kind'] !== 'stock') {
                continue;
            }
            if ($date < ($tenant->last_stock_date_bs ?? 0)) {
                $this->a->fail('Date precedes latest stock activity.', 'business_date_bs');
            }
            $item = $group['item_id'];
            if (! isset($pools[$item])) {
                $pool = $this->a->rows('inventory_balances')->where('item_id', $item)->first();
                $pools[$item] = ['qty' => (int) ($pool?->qty_milli ?? 0), 'value' => (int) ($pool?->value_paisa ?? 0)];
            }
            $cost = $purchase ? $group['clearing_value_paisa'] : $this->stock->outflowCost($pools[$item]['qty'], $pools[$item]['value'], $group['qty_milli']);
            $group['inventory_value_paisa'] = $cost;
            $pools[$item]['qty'] += $purchase ? $group['qty_milli'] : -$group['qty_milli'];
            $pools[$item]['value'] += $purchase ? $cost : -$cost;
            $takenQty = $takenCost = 0;
            foreach ($allocations as &$allocation) {
                if ($allocation['position'] === $position) {
                    $takenQty += $allocation['qty_milli'];
                    $target = Money::multiplyDivide($cost, $takenQty, $group['qty_milli']);
                    $allocation['inventory_cost_paisa'] = $purchase ? $allocation['pending_value_paisa'] : $target - $takenCost;
                    $takenCost = $target;
                }
            }
            unset($allocation);
        }
        unset($group);
        foreach ($allocations as &$allocation) {
            $allocation['inventory_cost_paisa'] ??= 0;
        }
        unset($allocation);
        $plan = ['workflow_id' => $workflow, 'workflow_version' => (int) $order->version, 'business_date_bs' => $date, 'kind' => $purchase ? 'receipt' : 'delivery', 'vat_recoverable' => (bool) $order->fulfilment_vat_recoverable, 'handover_confirmed' => $package === null, 'reference' => $input['reference'] ?? null, 'notes' => $input['notes'] ?? null, 'lines' => array_values($groups), 'allocations' => array_values($allocations), 'pools_after' => $pools, 'party_snapshot' => $order->party_snapshot, 'business_snapshot' => $order->business_snapshot];
        $plan['fingerprint'] = hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), config('app.key'));

        return $plan;
    }

    public function billedPreview(int $actor, int $workflow, array $input): array
    {
        return $this->visibleBilledPlan($actor, $this->billedPlan($actor, $workflow, $input));
    }

    private function visibleBilledPlan(int $actor, array $plan): array
    {
        unset($plan['pools_after']);
        if ($this->a->role($actor) === 'cashier') {
            foreach ($plan['lines'] as &$line) {
                unset($line['inventory_value_paisa'], $line['clearing_value_paisa']);
            }
            unset($line);
            foreach ($plan['allocations'] as &$allocation) {
                unset($allocation['inventory_cost_paisa'], $allocation['pending_value_paisa']);
            }
            unset($allocation);
        }

        return $plan;
    }

    public function saveBilled(int $actor, int $workflow, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.save.billed.'.$workflow, $input, function (Tenant $tenant) use ($actor, $workflow, $input) {
            $plan = $this->billedPlan($actor, $workflow, $input);
            abort_unless(hash_equals($plan['fingerprint'], $input['expected_fingerprint']), 409, 'Billed fulfilment changed. Review again.');

            return $this->postBilled($actor, $tenant, $workflow, $plan);
        });
    }

    private function postBilled(int $actor, Tenant $tenant, int $workflow, array $plan): array
    {
        $id = DB::table('workflow_fulfilments')->insertGetId(['tenant_id' => $tenant->id, ...array_diff_key($plan, array_flip(['workflow_version', 'lines', 'allocations', 'pools_after', 'fingerprint'])), 'workflow_version_at_post' => $plan['workflow_version'], 'inventory_value_paisa' => array_sum(array_column($plan['lines'], 'inventory_value_paisa')), 'clearing_value_paisa' => array_sum(array_column($plan['lines'], 'clearing_value_paisa')), 'sequence' => (int) $this->a->rows('workflow_fulfilments')->where('kind', $plan['kind'])->max('sequence') + 1, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        $journal = [];
        foreach ($plan['lines'] as $line) {
            $lineId = DB::table('workflow_fulfilment_lines')->insertGetId(['tenant_id' => $tenant->id, 'fulfilment_id' => $id, ...array_diff_key($line, ['item_snapshot' => true]), 'item_snapshot' => json_encode($line['item_snapshot'], JSON_THROW_ON_ERROR)]);
            $purchase = $plan['kind'] === 'receipt';
            if ($line['item_snapshot']['item_kind'] === 'stock') {
                if ($purchase) {
                    $this->stock->receive($actor, $line['item_id'], $plan['business_date_bs'], $line['qty_milli'], $line['inventory_value_paisa'], ['fulfilment_line_id' => $lineId]);
                } else {
                    $issued = $this->stock->issue($actor, $line['item_id'], $plan['business_date_bs'], $line['qty_milli'], ['fulfilment_line_id' => $lineId]);
                    if ($issued['cost'] !== $line['inventory_value_paisa']) {
                        throw new \LogicException('Locked billed dispatch cost diverged.');
                    }
                    $journal[] = $this->a->line($plan['handover_confirmed'] ? 'cogs' : 'goods_in_transit', $issued['cost']);
                    $journal[] = $this->a->line('inventory', -$issued['cost']);
                }
            }
            if ($purchase) {
                $journal[] = $this->a->line($line['item_snapshot']['item_kind'] === 'stock' ? 'inventory' : 'general_expense', $line['clearing_value_paisa']);
                $journal[] = $this->a->line('billed_unreceived', -$line['clearing_value_paisa']);
            }
            foreach ($plan['allocations'] as $allocation) {
                if ($allocation['position'] !== $line['position']) {
                    continue;
                }
                DB::table('workflow_dispatch_allocations')->insert(['tenant_id' => $tenant->id, 'workflow_id' => $workflow, 'fulfilment_line_id' => $lineId, 'recognition_sales_base_paisa' => $plan['handover_confirmed'] ? $allocation['sales_base_paisa'] : null, ...array_diff_key($allocation, ['position' => true, 'bill_version' => true])]);
                if (! $purchase && $plan['handover_confirmed']) {
                    $journal[] = $this->a->line('sales_unfulfilled', $allocation['sales_base_paisa']);
                    $journal[] = $this->a->line('sales', -$allocation['sales_base_paisa']);
                }
            }
        }
        $nonzero = array_filter($journal, fn ($line) => $line['debit_paisa'] || $line['credit_paisa']);
        $journalId = $nonzero ? $this->a->post($actor, $plan['business_date_bs'], ['type' => 'fulfilment', 'id' => $id], $journal, $plan['kind'].' '.$id) : null;
        $this->a->rows('workflow_fulfilments')->where('id', $id)->update(['journal_id' => $journalId]);
        $this->a->rows('business_workflows')->where('id', $workflow)->update(['version' => $plan['workflow_version'] + 1, 'updated_at' => now()]);

        return ['table' => 'workflow_fulfilments', 'id' => $id];
    }

    private function packageOrder(int $actor, int $workflow): object
    {
        $order = $this->order($actor, $workflow);
        abort_unless($order->kind === 'sales_order' && $order->fulfilment_policy === 'bill_first' && ! in_array($order->status, ['cancelled', 'converted', 'rejected']), 409, 'Use an active bill-first sales order.');
        $party = $this->a->requireRow('contacts', $order->contact_id);
        if ($party->archived_at || $party->is_system || ! $party->is_customer) {
            $this->a->fail('Use an active named customer.');
        }

        return $order;
    }

    private function packageRow(int $actor, int $id): object
    {
        $package = $this->a->requireRow('workflow_packages', $id);
        $this->order($actor, (int) $package->workflow_id);

        return $package;
    }

    private function packageSources(int $actor, object $package, bool $active = true): Collection
    {
        $lines = $this->a->rows('workflow_package_lines')->where('package_id', $package->id)->orderBy('id')->get();
        foreach ($lines as $line) {
            $billLine = $this->a->requireRow('document_lines', $line->document_line_id);
            $bill = $this->a->requireRow('documents', $billLine->document_id);
            abort_unless($bill->workflow_id == $package->workflow_id && $bill->type === 'sale' && $bill->fulfilment_policy === 'bill_first', 404);
            if ($active) {
                abort_unless($bill->status === 'posted', 409, 'Use an active source bill.');
            }
            if ($this->a->role($actor) === 'cashier') {
                abort_unless($bill->created_by == $actor, 403);
            }
        }

        return $lines;
    }

    public function assertPackageReplayAccess(int $actor, int $id): void
    {
        $this->packageSources($actor, $this->packageRow($actor, $id), false);
    }

    public function packageData(int $actor, int $id): array
    {
        $package = $this->packageRow($actor, $id);
        $data = (array) $package;
        $data['number'] = 'PKG-'.str_pad((string) $package->sequence, 6, '0', STR_PAD_LEFT);
        foreach (['party_snapshot', 'business_snapshot'] as $key) {
            $data[$key] = json_decode($data[$key], true);
        }
        $data['lines'] = $this->a->rows('workflow_package_lines')->where('package_id', $id)->orderBy('id')->get()->map(function ($line) use ($package) {
            $row = (array) $line;
            $row['item_snapshot'] = json_decode($row['item_snapshot'], true);
            $dispatch = $package->fulfilment_id ? $this->a->rows('workflow_dispatch_allocations')->where('document_line_id', $line->document_line_id)->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $package->fulfilment_id)->select('id'))->first() : null;
            $transitQty = $dispatch ? (int) $this->credits((int) $line->document_line_id)->where('dispatch_allocation_id', $dispatch->id)->where('return_mode', 'transit')->sum('qty_milli') : 0;
            $row['credited_transit_qty_milli'] = $transitQty;
            $remaining = max(0, (int) $line->qty_milli - $transitQty);
            $row['remaining_delivery_qty_milli'] = $package->status === 'shipped' ? $remaining : 0;
            $row['delivered_qty_milli'] = $package->status === 'delivered' ? $remaining : 0;

            return $row;
        })->all();
        $data['shipment'] = $package->fulfilment_id ? $this->data($actor, (int) $package->fulfilment_id) : null;
        $data['delivery_reversals'] = $this->a->rows('audit_logs')->where('subject_type', 'workflow_packages')->where('subject_id', $id)->where('action', 'package.delivery.undo')->orderBy('id')->get()->map(fn ($row) => ['actor_id' => (int) $row->actor_id, ...json_decode($row->metadata, true)])->all();
        $data['delivery_confirmations'] = $this->a->rows('audit_logs')->where('subject_type', 'workflow_packages')->where('subject_id', $id)->where('action', 'package.delivery.confirm')->orderBy('id')->get()->map(function ($row) use ($actor) {
            $history = ['actor_id' => (int) $row->actor_id, ...json_decode($row->metadata, true)];
            if ($this->a->role($actor) === 'cashier') {
                foreach ($history['allocations'] as &$allocation) {
                    unset($allocation['inventory_cost_paisa']);
                }
                unset($allocation);
            }

            return $history;
        })->all();
        if ($this->a->role($actor) === 'cashier') {
            unset($data['delivery_journal_id']);
        }

        return $data;
    }

    private function packageHistoryDate(object $package): int
    {
        $last = max($package->business_date_bs, $package->shipped_date_bs ?? 0, $package->delivered_date_bs ?? 0);
        if ($package->fulfilment_id) {
            $last = max($last, (int) $this->a->rows('journal_entries')->where('source_type', 'fulfilment_delivery')->where('source_id', $package->fulfilment_id)->max('business_date_bs'));
            $allocations = $this->a->rows('workflow_dispatch_allocations')->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $package->fulfilment_id)->select('id'))->select('id');
            $returnLines = $this->a->rows('workflow_return_allocations')->where('return_mode', 'transit')->whereIn('dispatch_allocation_id', $allocations)->select('return_document_line_id');
            foreach ($this->a->rows('documents')->whereIn('id', $this->a->rows('document_lines')->whereIn('id', $returnLines)->select('document_id'))->get(['business_date_bs', 'cancellation_date_bs']) as $returned) {
                $last = max($last, (int) $returned->business_date_bs, (int) $returned->cancellation_date_bs);
            }
        }
        foreach ($this->a->rows('audit_logs')->where('subject_type', 'workflow_packages')->where('subject_id', $package->id)->where('action', 'package.delivery.undo')->get(['metadata']) as $row) {
            $last = max($last, (int) json_decode($row->metadata, true)['business_date_bs']);
        }

        return $last;
    }

    private function packPlan(int $actor, int $workflow, array $input): array
    {
        $order = $this->packageOrder($actor, $workflow);
        abort_unless($order->version == $input['version'], 409, 'Order changed. Review again.');
        $date = NepaliDate::normalize($input['business_date_bs']);
        $this->a->assertOpenDate(Tenant::findOrFail(app(CurrentTenant::class)->id()), $date);
        $originals = json_decode($order->lines, true);
        $lines = [];
        foreach ($input['lines'] as $entry) {
            $line = $this->a->requireRow('document_lines', $entry['document_line_id']);
            $bill = $this->a->requireRow('documents', $line->document_id);
            abort_unless($bill->workflow_id == $workflow && $bill->type === 'sale' && $bill->fulfilment_policy === 'bill_first', 404);
            abort_unless($bill->status === 'posted', 409, 'Use an active source bill.');
            if ($this->a->role($actor) === 'cashier') {
                abort_unless($bill->created_by == $actor, 403);
            }
            if ($date < $bill->business_date_bs || $date < $order->business_date_bs) {
                $this->a->fail('Packing date precedes order/bill.', 'business_date_bs');
            }
            $source = $this->a->rows('workflow_bill_allocations')->where('workflow_id', $workflow)->where('document_line_id', $line->id)->whereNull('fulfilment_line_id')->first();
            abort_unless($source, 409, 'Missing ordered bill source.');
            $original = $originals[$source->position - 1];
            $item = $this->a->requireRow('items', $line->item_id);
            if ($item->archived_at || $item->kind !== 'stock' || $item->kind !== $original['item_kind'] || $item->unit_label !== $original['unit_snapshot'] || $item->pos_unit !== $original['pos_unit']) {
                $this->a->fail('Pack active unchanged stock items only. Services use direct completion.');
            }
            $qty = Money::quantity($entry['qty']);
            $remaining = $this->remainingBilled($line);
            if ($qty <= 0 || $qty > $remaining || isset($lines[$line->id])) {
                $this->a->fail('Quantity exceeds unfulfilled, unpacked billed quantity.', 'lines');
            }
            $lines[$line->id] = ['document_line_id' => (int) $line->id, 'qty_milli' => $qty, 'item_snapshot' => $original, 'bill_version' => (int) $bill->version, 'remaining_qty_milli' => $remaining];
        }
        $plan = ['workflow_id' => $workflow, 'workflow_version' => (int) $order->version, 'business_date_bs' => $date, 'reference' => $input['reference'] ?? null, 'notes' => $input['notes'] ?? null, 'party_snapshot' => $order->party_snapshot, 'business_snapshot' => $order->business_snapshot, 'lines' => array_values($lines)];
        $plan['fingerprint'] = hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), config('app.key'));

        return $plan;
    }

    public function packPreview(int $actor, int $workflow, array $input): array
    {
        return $this->packPlan($actor, $workflow, $input);
    }

    public function pack(int $actor, int $workflow, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.save.package.pack.'.$workflow, $input, function (Tenant $tenant) use ($actor, $workflow, $input) {
            $plan = $this->packPlan($actor, $workflow, $input);
            abort_unless(hash_equals($plan['fingerprint'], $input['expected_fingerprint']), 409, 'Package changed. Review again.');
            $id = DB::table('workflow_packages')->insertGetId(['tenant_id' => $tenant->id, ...array_diff_key($plan, array_flip(['workflow_version', 'lines', 'fingerprint'])), 'sequence' => (int) $this->a->rows('workflow_packages')->max('sequence') + 1, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($plan['lines'] as $line) {
                DB::table('workflow_package_lines')->insert(['tenant_id' => $tenant->id, 'package_id' => $id, 'document_line_id' => $line['document_line_id'], 'qty_milli' => $line['qty_milli'], 'item_snapshot' => json_encode($line['item_snapshot'], JSON_THROW_ON_ERROR)]);
            }
            $this->a->rows('business_workflows')->where('id', $workflow)->update(['version' => $plan['workflow_version'] + 1, 'updated_at' => now()]);

            return ['table' => 'workflow_packages', 'id' => $id];
        });
    }

    private function shipPlan(int $actor, int $id, array $input): array
    {
        $package = $this->packageRow($actor, $id);
        $order = $this->packageOrder($actor, (int) $package->workflow_id);
        abort_unless($package->status === 'packed' && $package->version == $input['version'] && $order->version == $input['workflow_version'], 409, 'Package or order changed.');
        $date = NepaliDate::normalize($input['business_date_bs']);
        if ($date < $package->business_date_bs) {
            $this->a->fail('Shipment date precedes packing.', 'business_date_bs');
        }
        $lines = $this->packageSources($actor, $package)->map(fn ($line) => ['document_line_id' => (int) $line->document_line_id, 'qty' => Money::format((int) $line->qty_milli, 3)])->all();
        $stage = $this->billedPlan($actor, (int) $order->id, ['version' => $order->version, 'business_date_bs' => $date, 'lines' => $lines, 'reference' => $package->reference, 'notes' => $package->notes], $id);
        $stage['party_snapshot'] = $package->party_snapshot;
        $stage['business_snapshot'] = $package->business_snapshot;
        $plan = ['package_id' => $id, 'package_version' => (int) $package->version, 'stage' => $stage, 'carrier' => $input['carrier'] ?? null, 'tracking_reference' => $input['tracking_reference'] ?? null];
        $plan['fingerprint'] = hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), config('app.key'));

        return $plan;
    }

    public function shipPreview(int $actor, int $id, array $input): array
    {
        $plan = $this->shipPlan($actor, $id, $input);
        $plan['stage'] = $this->visibleBilledPlan($actor, $plan['stage']);

        return $plan;
    }

    public function ship(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.save.package.ship.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $plan = $this->shipPlan($actor, $id, $input);
            abort_unless(hash_equals($plan['fingerprint'], $input['expected_fingerprint']), 409, 'Shipment changed. Review again.');
            $stage = $this->postBilled($actor, $tenant, $plan['stage']['workflow_id'], $plan['stage']);
            $this->a->rows('workflow_packages')->where('id', $id)->update(['status' => 'shipped', 'version' => $plan['package_version'] + 1, 'fulfilment_id' => $stage['id'], 'shipped_date_bs' => $plan['stage']['business_date_bs'], 'shipped_by' => $actor, 'carrier' => $plan['carrier'], 'tracking_reference' => $plan['tracking_reference'], 'updated_at' => now()]);

            return ['table' => 'workflow_packages', 'id' => $id];
        });
    }

    private function deliveryPlan(int $actor, int $id, array $input): array
    {
        $package = $this->packageRow($actor, $id);
        $order = $this->packageOrder($actor, (int) $package->workflow_id);
        abort_unless($package->status === 'shipped' && $package->version == $input['version'] && $order->version == $input['workflow_version'], 409, 'Package or order changed.');
        abort_unless(($input['handover_confirmed'] ?? false) === true, 422, 'Confirm actual customer delivery.');
        $sources = $this->packageSources($actor, $package);
        $billVersions = $sources->map(function ($line) {
            $billLine = $this->a->requireRow('document_lines', $line->document_line_id);
            $bill = $this->a->requireRow('documents', $billLine->document_id);

            return ['document_line_id' => (int) $billLine->id, 'document_id' => (int) $bill->id, 'version' => (int) $bill->version];
        })->all();
        $stage = $this->a->requireRow('workflow_fulfilments', $package->fulfilment_id);
        abort_unless($stage->status === 'posted' && ! $stage->handover_confirmed, 409, 'Use an active unconfirmed shipment.');
        $date = NepaliDate::normalize($input['business_date_bs']);
        $this->a->assertOpenDate(Tenant::findOrFail(app(CurrentTenant::class)->id()), $date);
        if ($date < $this->packageHistoryDate($package)) {
            $this->a->fail('Delivery date precedes shipment or its delivery history.', 'business_date_bs');
        }
        $allocations = $this->a->rows('workflow_dispatch_allocations')->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $stage->id)->select('id'))->orderBy('id')->get();
        $remaining = $allocations->map(function ($allocation) {
            $line = $this->a->requireRow('document_lines', $allocation->document_line_id);
            $returns = $this->credits((int) $line->id)->where('dispatch_allocation_id', $allocation->id)->where('return_mode', 'transit');
            $qty = (int) $allocation->qty_milli - (int) $returns->sum('qty_milli');

            return ['id' => (int) $allocation->id, 'document_line_id' => (int) $line->id, 'qty_milli' => $qty, 'inventory_cost_paisa' => (int) $allocation->inventory_cost_paisa - (int) $returns->sum('inventory_cost_paisa'), 'sales_base_paisa' => $qty ? $this->recognitionShare($line, $qty) : 0];
        });
        abort_unless($remaining->sum('qty_milli') > 0, 409, 'All shipped goods were returned; do not invent customer delivery.');
        $plan = ['package_id' => $id, 'package_version' => (int) $package->version, 'workflow_id' => (int) $order->id, 'workflow_version' => (int) $order->version, 'fulfilment_id' => (int) $stage->id, 'fulfilment_version' => (int) $stage->version, 'business_date_bs' => $date, 'bill_versions' => $billVersions, 'allocations' => $remaining->all(), 'inventory_cost_paisa' => (int) $remaining->sum('inventory_cost_paisa'), 'sales_base_paisa' => (int) $remaining->sum('sales_base_paisa'), 'qty_milli' => (int) $remaining->sum('qty_milli')];
        $plan['fingerprint'] = hash_hmac('sha256', json_encode($plan, JSON_THROW_ON_ERROR), config('app.key'));

        return $plan;
    }

    public function deliveryPreview(int $actor, int $id, array $input): array
    {
        $plan = $this->deliveryPlan($actor, $id, $input);
        if ($this->a->role($actor) === 'cashier') {
            unset($plan['inventory_cost_paisa']);
            foreach ($plan['allocations'] as &$allocation) {
                unset($allocation['inventory_cost_paisa']);
            }
            unset($allocation);
        }

        return $plan;
    }

    public function deliver(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.save.package.deliver.'.$id, $input, function () use ($actor, $id, $input) {
            $plan = $this->deliveryPlan($actor, $id, $input);
            abort_unless(hash_equals($plan['fingerprint'], $input['expected_fingerprint']), 409, 'Delivery changed. Review again.');
            $lines = [$this->a->line('cogs', $plan['inventory_cost_paisa']), $this->a->line('goods_in_transit', -$plan['inventory_cost_paisa']), $this->a->line('sales_unfulfilled', $plan['sales_base_paisa']), $this->a->line('sales', -$plan['sales_base_paisa'])];
            $nonzero = array_filter($lines, fn ($line) => $line['debit_paisa'] || $line['credit_paisa']);
            $journal = $nonzero ? $this->a->post($actor, $plan['business_date_bs'], ['type' => 'fulfilment_delivery', 'id' => $plan['fulfilment_id'], 'event' => 'post.'.$plan['package_version']], $lines, 'Confirm package '.$id.' delivery') : null;
            foreach ($plan['allocations'] as $allocation) {
                $this->a->rows('workflow_dispatch_allocations')->where('id', $allocation['id'])->update(['recognition_sales_base_paisa' => $allocation['sales_base_paisa']]);
            }
            $this->a->audit($actor, 'package.delivery.confirm', 'workflow_packages', $id, ['business_date_bs' => $plan['business_date_bs'], 'allocations' => $plan['allocations']]);
            $this->a->rows('workflow_packages')->where('id', $id)->update(['status' => 'delivered', 'version' => $plan['package_version'] + 1, 'delivered_date_bs' => $plan['business_date_bs'], 'delivered_by' => $actor, 'delivery_journal_id' => $journal, 'updated_at' => now()]);
            $this->a->rows('workflow_fulfilments')->where('id', $plan['fulfilment_id'])->update(['handover_confirmed' => true, 'version' => $plan['fulfilment_version'] + 1, 'recognition_journal_id' => $journal, 'updated_at' => now()]);
            $this->a->rows('business_workflows')->where('id', $plan['workflow_id'])->update(['version' => $plan['workflow_version'] + 1, 'updated_at' => now()]);

            return ['table' => 'workflow_packages', 'id' => $id];
        });
    }

    private function assertNoPackageReturns(object $package, bool $completedOnly = false): void
    {
        $allocations = $this->a->rows('workflow_dispatch_allocations')->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $package->fulfilment_id)->select('id'))->select('id');
        abort_if($this->a->rows('workflow_return_allocations')->whereIn('dispatch_allocation_id', $allocations)->when($completedOnly, fn ($query) => $query->where('return_mode', 'completed'))->whereIn('return_document_line_id', $this->a->rows('document_lines')->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->select('id'))->exists(), 409, 'Reverse physical returns before reversing package delivery.');
    }

    public function undoDelivery(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.package.undo-delivery.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $package = $this->packageRow($actor, $id);
            $order = $this->order($actor, (int) $package->workflow_id);
            abort_unless($package->status === 'delivered' && $package->version == $input['version'] && $order->version == $input['workflow_version'], 409, 'Package or order changed.');
            $this->assertNoPackageReturns($package, true);
            $stage = $this->a->requireRow('workflow_fulfilments', $package->fulfilment_id);
            abort_unless($stage->status === 'posted' && $stage->handover_confirmed, 409);
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->a->assertOpenDate($tenant, (int) $package->delivered_date_bs);
            $this->a->assertOpenDate($tenant, $date);
            if ($date < $this->packageHistoryDate($package)) {
                $this->a->fail('Reversal precedes delivery.', 'business_date_bs');
            }
            if ($package->delivery_journal_id) {
                $this->a->reverse($actor, (int) $package->delivery_journal_id, $date, $input['reason'], sourceEvent: 'reverse.'.$package->version);
            }
            $this->a->audit($actor, 'package.delivery.undo', 'workflow_packages', $id, ['business_date_bs' => $date, 'reason' => $input['reason']]);
            $this->a->rows('workflow_packages')->where('id', $id)->update(['status' => 'shipped', 'version' => $package->version + 1, 'updated_at' => now()]);
            $this->a->rows('workflow_fulfilments')->where('id', $stage->id)->update(['handover_confirmed' => false, 'version' => $stage->version + 1, 'updated_at' => now()]);
            $this->a->rows('workflow_dispatch_allocations')->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $stage->id)->select('id'))->update(['recognition_sales_base_paisa' => null]);
            $this->a->rows('business_workflows')->where('id', $order->id)->update(['version' => $order->version + 1, 'updated_at' => now()]);

            return ['table' => 'workflow_packages', 'id' => $id];
        });
    }

    public function cancelPackage(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.package.cancel.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $package = $this->packageRow($actor, $id);
            $order = $this->order($actor, (int) $package->workflow_id);
            abort_unless(in_array($package->status, ['packed', 'shipped']) && $package->version == $input['version'] && $order->version == $input['workflow_version'], 409, 'Undo delivery first, or refresh the package/order.');
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->a->assertOpenDate($tenant, (int) $package->business_date_bs);
            $this->a->assertOpenDate($tenant, $date);
            if ($date < $this->packageHistoryDate($package)) {
                $this->a->fail('Cancellation precedes package history.', 'business_date_bs');
            }
            if ($package->status === 'shipped') {
                $this->assertNoPackageReturns($package);
                $stage = $this->a->requireRow('workflow_fulfilments', $package->fulfilment_id);
                abort_unless($stage->status === 'posted' && ! $stage->handover_confirmed, 409);
                foreach ($this->a->rows('stock_movements')->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $stage->id)->select('id'))->where('source_event', 'post')->orderByDesc('id')->get() as $movement) {
                    $this->stock->reverse($actor, (int) $movement->id, $date);
                }
                $journal = $stage->journal_id ? $this->a->reverse($actor, (int) $stage->journal_id, $date, $input['reason']) : null;
                $this->a->rows('workflow_fulfilments')->where('id', $stage->id)->update(['status' => 'cancelled', 'version' => $stage->version + 1, 'cancellation_date_bs' => $date, 'cancellation_reason' => $input['reason'], 'cancelled_by' => $actor, 'reversal_journal_id' => $journal, 'updated_at' => now()]);
            }
            $this->a->rows('workflow_packages')->where('id', $id)->update(['status' => 'cancelled', 'version' => $package->version + 1, 'cancellation_date_bs' => $date, 'cancellation_reason' => $input['reason'], 'cancelled_by' => $actor, 'updated_at' => now()]);
            $this->a->rows('business_workflows')->where('id', $order->id)->update(['version' => $order->version + 1, 'updated_at' => now()]);

            return ['table' => 'workflow_packages', 'id' => $id];
        });
    }

    public function billFirstReturnPlan(object $bill, object $line, array $entry, int $qty, int $date, array $returnMoney): array
    {
        $this->a->requireRow('business_workflows', $bill->workflow_id);
        $source = $entry['return_source'] ?? null;
        if ($source === 'unfulfilled') {
            if ($qty > $this->remainingBilled($line)) {
                $this->a->fail('Credit exceeds unfulfilled, unpacked quantity.');
            }

            $consumed = $this->pendingSources($line);
            $baseLeft = $bill->type === 'sale' ? $this->recognitionContext($line)['held_left_paisa'] : max(0, (int) $line->net_base_paisa - (int) $consumed->sum('sales_base_paisa'));
            $heldTotal = $bill->type === 'purchase' ? (int) ($bill->vat_recoverable ? $line->net_base_paisa : $line->total_paisa) : 0;
            $heldLeft = max(0, $heldTotal - (int) $consumed->sum('pending_value_paisa'));
            $creditCost = $bill->vat_recoverable ? $returnMoney['net_base_paisa'] : $returnMoney['net_base_paisa'] + $returnMoney['tax_paisa'];

            // A credit must not release more pending value than its actual financial reduction.
            abort_unless(($entry['return_mode'] ?? 'unfulfilled') === 'unfulfilled', 422, 'Unfulfilled credit requires its own source mode.');

            return ['document_line_id' => (int) $line->id, 'dispatch_allocation_id' => null, 'return_mode' => 'unfulfilled', 'qty_milli' => $qty, 'inventory_cost_paisa' => 0, 'sales_base_paisa' => min($returnMoney['net_base_paisa'], $baseLeft), 'pending_value_paisa' => min($creditCost, $heldLeft)];
        }
        if (! is_string($source) || ! preg_match('/^[1-9][0-9]{0,18}$/', $source)) {
            $this->a->fail('Choose unfulfilled credit or an exact completed delivery/receipt.');
        }
        $allocation = $this->a->requireRow('workflow_dispatch_allocations', $source);
        abort_unless($allocation->document_line_id == $line->id && $allocation->workflow_id == $bill->workflow_id, 404);
        $physicalLine = $this->a->requireRow('workflow_fulfilment_lines', $allocation->fulfilment_line_id);
        $stage = $this->a->requireRow('workflow_fulfilments', $physicalLine->fulfilment_id);
        $mode = $entry['return_mode'] ?? 'completed';
        abort_unless(in_array($mode, ['transit', 'completed'], true), 422, 'Choose the source return mode.');
        abort_unless($stage->status === 'posted' && (bool) $stage->handover_confirmed === ($mode === 'completed'), 409, 'Source delivery state changed. Review the correct return mode.');
        $package = $this->a->rows('workflow_packages')->where('fulfilment_id', $stage->id)->first();
        if ($mode === 'transit') {
            $item = $this->a->requireRow('items', $line->item_id);
            abort_unless($bill->type === 'sale' && $item->kind === 'stock' && $package && $package->status === 'shipped', 409, 'Return actual undelivered sales stock from its shipped package.');
        }
        if ($date < ($package ? $this->packageHistoryDate($package) : $stage->business_date_bs)) {
            $this->a->fail('Return date precedes actual fulfilment.');
        }
        $prior = $this->credits((int) $line->id)->where('dispatch_allocation_id', $allocation->id);
        $previousQty = (int) $prior->sum('qty_milli');
        if ($qty + $previousQty > (int) $allocation->qty_milli) {
            $this->a->fail('Return exceeds this exact fulfilled source.');
        }
        $plan = ['document_line_id' => (int) $line->id, 'dispatch_allocation_id' => (int) $allocation->id, 'return_mode' => $mode, 'qty_milli' => $qty];
        foreach (['inventory_cost_paisa', 'pending_value_paisa', 'sales_base_paisa'] as $component) {
            $plan[$component] = Money::multiplyDivide((int) $allocation->$component, $previousQty + $qty, (int) $allocation->qty_milli) - (int) $prior->sum($component);
        }
        if ($mode === 'transit') {
            $plan['sales_base_paisa'] = min($returnMoney['net_base_paisa'], $this->recognitionContext($line)['held_left_paisa']);
            $plan['pending_value_paisa'] = 0;
        }

        return $plan;
    }

    public function recordBilledReturn(int $actor, object $bill, array $line, int $lineId, array $allocation, int $date): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Locked original bill return required.');
        }
        DB::table('workflow_return_allocations')->insert(['tenant_id' => app(CurrentTenant::class)->id(), 'return_document_line_id' => $lineId, ...$allocation]);
        $item = $this->a->requireRow('items', $line['item_id']);
        $physical = $allocation['dispatch_allocation_id'] !== null;
        $journal = [];
        if ($bill->type === 'sale') {
            $held = $allocation['return_mode'] === 'completed' ? 0 : $allocation['sales_base_paisa'];
            $journal[] = $this->a->line('sales_unfulfilled', $held);
            $journal[] = $this->a->line('sales_returns', $line['net_base_paisa'] - $held);
            $journal[] = $this->a->line('output_vat', $line['tax_paisa']);
            if ($physical && $item->kind === 'stock') {
                $cost = $allocation['inventory_cost_paisa'];
                $this->stock->receive($actor, (int) $item->id, $date, $line['qty_milli'], $cost, ['document_line_id' => $lineId]);
                $journal[] = $this->a->line('inventory', $cost);
                $journal[] = $this->a->line($allocation['return_mode'] === 'transit' ? 'goods_in_transit' : 'cogs', -$cost);
            }
        } else {
            $financial = $bill->vat_recoverable ? $line['net_base_paisa'] : $line['total_paisa'];
            $removed = $allocation['pending_value_paisa'];
            $account = 'billed_unreceived';
            if ($physical) {
                $account = $item->kind === 'stock' ? 'inventory' : 'general_expense';
                if ($item->kind === 'stock') {
                    $removed = $this->stock->issue($actor, (int) $item->id, $date, $line['qty_milli'], ['document_line_id' => $lineId])['cost'];
                }
            }
            $journal[] = $this->a->line($account, -$removed);
            $variance = $removed - $financial;
            $journal[] = $this->a->line($variance >= 0 ? 'inventory_loss' : 'inventory_gain', $variance);
            if ($bill->vat_recoverable) {
                $journal[] = $this->a->line('input_vat', -$line['tax_paisa']);
            }
        }

        return $journal;
    }

    public function beforeBillCancellation(int $actor, object $doc, int $date): void
    {
        if ($doc->source_document_id && ($doc->fulfilment_policy ?? null) === 'bill_first') {
            $source = $this->a->requireRow('documents', $doc->source_document_id);
            $this->order($actor, (int) $source->workflow_id);
            $returns = $this->a->rows('workflow_return_allocations')->where('return_mode', 'transit')->whereIn('return_document_line_id', $this->a->rows('document_lines')->where('document_id', $doc->id)->select('id'))->get();
            foreach ($returns as $returned) {
                $dispatch = $this->a->requireRow('workflow_dispatch_allocations', $returned->dispatch_allocation_id);
                $physical = $this->a->requireRow('workflow_fulfilment_lines', $dispatch->fulfilment_line_id);
                $stage = $this->a->requireRow('workflow_fulfilments', $physical->fulfilment_id);
                abort_if($stage->handover_confirmed, 409, 'Undo this package delivery before cancelling its transit return.');
                $package = $this->a->rows('workflow_packages')->where('fulfilment_id', $stage->id)->first();
                if ($package && $date < $this->packageHistoryDate($package)) {
                    $this->a->fail('Return reversal precedes package history.', 'business_date_bs');
                }
            }
        }
        if (! $doc->workflow_id) {
            return;
        }
        $this->order($actor, (int) $doc->workflow_id);
        if (($doc->fulfilment_policy ?? null) === 'bill_first') {
            foreach ($this->a->rows('document_lines')->where('document_id', $doc->id)->get() as $line) {
                abort_if($this->dispatches((int) $line->id)->isNotEmpty() || $this->packed((int) $line->id) > 0, 409, 'Reverse dependent fulfilment/packages before cancelling the bill.');
            }
        }
        abort_if($this->a->rows('documents')->where('workflow_id', $doc->workflow_id)->where('status', 'posted')->where('workflow_version_at_post', '>', $doc->workflow_version_at_post)->exists(), 409, 'Cancel later staged bills first.');
        abort_if($this->a->rows('workflow_fulfilments')->where('workflow_id', $doc->workflow_id)->whereNotNull('source_id')->where('status', 'posted')->where('workflow_version_at_post', '>', $doc->workflow_version_at_post)->exists(), 409, 'Cancel later unbilled source returns first.');
    }

    public function documentChanged(object $doc): void
    {
        $workflow = $doc->workflow_id;
        if (! $workflow && $doc->source_document_id) {
            $workflow = $this->a->requireRow('documents', $doc->source_document_id)->workflow_id;
        }
        if ($workflow) {
            $this->a->rows('business_workflows')->where('id', $workflow)->increment('version');
        }
    }

    public function save(int $actor, int $workflow, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.save.'.$workflow, $input, function (Tenant $tenant) use ($actor, $workflow, $input) {
            $plan = $this->plan($actor, $workflow, $input);
            abort_unless(hash_equals($plan['fingerprint'], $input['expected_fingerprint']), 409, 'Fulfilment changed. Review again.');
            $header = array_diff_key($plan, array_flip(['workflow_version', 'lines', 'pools_after', 'fingerprint']));
            $order = $this->order($actor, $workflow);
            $header['party_snapshot'] = $order->party_snapshot;
            $header['business_snapshot'] = $order->business_snapshot;
            $id = DB::table('workflow_fulfilments')->insertGetId(['tenant_id' => $tenant->id, ...$header, 'workflow_version_at_post' => $plan['workflow_version'], 'sequence' => (int) $this->a->rows('workflow_fulfilments')->where('kind', $plan['kind'])->max('sequence') + 1, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($plan['lines'] as $line) {
                $lineId = DB::table('workflow_fulfilment_lines')->insertGetId(['tenant_id' => $tenant->id, 'fulfilment_id' => $id, ...array_diff_key($line, ['item_snapshot' => true]), 'item_snapshot' => json_encode($line['item_snapshot'])]);
                if ($line['item_snapshot']['item_kind'] === 'stock') {
                    $source = ['fulfilment_line_id' => $lineId];
                    if (in_array($plan['kind'], ['delivery', 'receipt_return'])) {
                        $issued = $this->stock->issue($actor, $line['item_id'], $plan['business_date_bs'], $line['qty_milli'], $source);
                        if ($issued['cost'] !== $line['inventory_value_paisa']) {
                            throw new \LogicException('Locked stock cost diverged from reviewed fulfilment.');
                        }
                    } else {
                        $this->stock->receive($actor, $line['item_id'], $plan['business_date_bs'], $line['qty_milli'], $line['inventory_value_paisa'], $source);
                    }
                }
            }
            $value = $plan['inventory_value_paisa'];
            $clearing = $plan['clearing_value_paisa'];
            $journalLines = match ($plan['kind']) {
                'delivery' => [$this->a->line('delivered_unbilled', $clearing), $this->a->line('inventory', -$value)],
                'delivery_return' => [$this->a->line('inventory', $value), $this->a->line('delivered_unbilled', -$clearing)],
                'receipt' => [$this->a->line('inventory', $value), $this->a->line('received_unbilled', -$clearing)],
                'receipt_return' => [$this->a->line('received_unbilled', $clearing), $this->a->line('inventory', -$value), $this->a->line($value >= $clearing ? 'inventory_loss' : 'inventory_gain', $value - $clearing)],
            };
            $journal = $value || $clearing ? $this->a->post($actor, $plan['business_date_bs'], ['type' => 'fulfilment', 'id' => $id], $journalLines, $plan['kind'].' '.$id) : null;
            $this->a->rows('workflow_fulfilments')->where('id', $id)->update(['journal_id' => $journal]);
            $this->a->rows('business_workflows')->where('id', $workflow)->update(['version' => $plan['workflow_version'] + 1, 'fulfilment_policy' => 'delivery_first', 'fulfilment_vat_recoverable' => $plan['vat_recoverable'], 'updated_at' => now()]);

            return ['table' => 'workflow_fulfilments', 'id' => $id];
        });
    }

    public function cancel(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'fulfilment.cancel.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $stage = $this->a->requireRow('workflow_fulfilments', $id);
            $order = $this->order($actor, (int) $stage->workflow_id);
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            abort_unless($stage->status === 'posted' && $stage->version == $input['version'] && $order->version == $input['workflow_version'], 409, 'Action or order changed.');
            abort_if($this->a->rows('workflow_fulfilments')->where('source_id', $id)->where('status', 'posted')->exists(), 409, 'Cancel source returns first.');
            $allocated = $this->activeAllocations((int) $order->id);
            $sourceLines = $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $id)->pluck('id');
            $dispatchIds = $this->a->rows('workflow_dispatch_allocations')->whereIn('fulfilment_line_id', $sourceLines)->pluck('id');
            abort_if($this->a->rows('workflow_return_allocations')->whereIn('dispatch_allocation_id', $dispatchIds)->whereIn('return_document_line_id', $this->a->rows('document_lines')->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->select('id'))->exists(), 409, 'Reverse physical billed returns before cancelling this fulfilment.');
            abort_if($this->a->rows('workflow_packages')->where('fulfilment_id', $id)->where('status', '<>', 'cancelled')->exists(), 409, 'Reverse the dependent package before this fulfilment.');
            abort_if($allocated->whereIn('fulfilment_line_id', $sourceLines)->isNotEmpty(), 409, 'Cancel allocated bills first.');
            abort_if($stage->source_id && $this->a->rows('documents')->where('workflow_id', $order->id)->where('status', 'posted')->where('workflow_version_at_post', '>', $stage->workflow_version_at_post ?? 0)->exists(), 409, 'Cancel later staged bills before this source return.');
            if ($stage->source_id) {
                abort_if($this->a->rows('workflow_fulfilments')->where('source_id', $stage->source_id)->where('id', '>', $id)->where('status', 'posted')->exists(), 409, 'Cancel later partial returns first.');
            }
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->a->assertOpenDate($tenant, $stage->business_date_bs);
            $this->a->assertOpenDate($tenant, $date);
            if ($date < $stage->business_date_bs) {
                $this->a->fail('Cancellation precedes source.');
            }
            foreach ($this->a->rows('stock_movements')->whereIn('fulfilment_line_id', $this->a->rows('workflow_fulfilment_lines')->where('fulfilment_id', $id)->select('id'))->where('source_event', 'post')->orderByDesc('id')->get() as $movement) {
                $this->stock->reverse($actor, (int) $movement->id, $date);
            }
            $journal = $stage->journal_id ? $this->a->reverse($actor, (int) $stage->journal_id, $date, $input['reason']) : null;
            $this->a->rows('workflow_fulfilments')->where('id', $id)->update(['status' => 'cancelled', 'version' => $stage->version + 1, 'cancellation_date_bs' => $date, 'cancellation_reason' => $input['reason'], 'cancelled_by' => $actor, 'reversal_journal_id' => $journal, 'updated_at' => now()]);
            $this->a->rows('business_workflows')->where('id', $order->id)->update(['version' => $order->version + 1, 'updated_at' => now()]);

            return ['table' => 'workflow_fulfilments', 'id' => $id];
        });
    }
}
