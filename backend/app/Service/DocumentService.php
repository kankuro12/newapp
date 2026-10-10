<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    public function __construct(private AccountingService $a, private InventoryService $stock, private PaymentService $money, private PartyService $parties) {}

    public function calculate(array $input): array
    {
        $lines = [];
        $bases = [];
        foreach ($input['lines'] as $i => $row) {
            $qty = Money::quantity($row['qty']);
            $price = Money::parse($row['unit_price']);
            if (! $qty) {
                $this->a->fail('Quantity must be positive.', 'lines.'.$i.'.qty');
            }
            $gross = Money::multiplyDivide($qty, $price, 1000);
            if (isset($row['discount_bps']) && isset($row['discount'])) {
                $this->a->fail('Choose fixed or percentage discount.');
            }
            $discount = isset($row['discount_bps']) ? Money::multiplyDivide($gross, (int) $row['discount_bps'], 10000) : Money::parse($row['discount'] ?? '0');
            if ($discount > $gross) {
                $this->a->fail('Discount exceeds line price.');
            }
            $bases[] = $gross - $discount;
            $lines[] = ['position' => $i + 1, 'item_id' => $row['item_id'] ?? null, 'expense_category_id' => $row['expense_category_id'] ?? null, 'description' => $row['description'] ?? '', 'unit_snapshot' => $row['unit_snapshot'] ?? 'unit', 'qty_milli' => $qty, 'unit_price_paisa' => $price, 'gross_paisa' => $gross, 'line_discount_paisa' => $discount, 'tax_category' => $row['tax_category'] ?? 'outside_scope', 'tax_bps' => (int) ($row['tax_bps'] ?? 0)];
        }
        if (isset($input['invoice_discount_bps']) && isset($input['invoice_discount'])) {
            $this->a->fail('Choose fixed or percentage bill discount.');
        }
        $invoice = isset($input['invoice_discount_bps']) ? Money::multiplyDivide(array_sum($bases), (int) $input['invoice_discount_bps'], 10000) : Money::parse($input['invoice_discount'] ?? '0');
        $shares = Money::allocate($invoice, $bases);
        foreach ($lines as $i => &$line) {
            $line['invoice_discount_paisa'] = $shares[$i];
            $line['net_base_paisa'] = $bases[$i] - $shares[$i];
            $line['tax_paisa'] = in_array($line['tax_category'], ['standard', 'zero']) ? Money::multiplyDivide($line['net_base_paisa'], $line['tax_bps'], 10000) : 0;
            $line['total_paisa'] = $line['net_base_paisa'] + $line['tax_paisa'];
        } unset($line);
        $total = array_sum(array_column($lines, 'total_paisa'));
        if ($total <= 0 || $total > 10000000000) {
            $this->a->fail('Bill total must be positive and within limit.');
        }

        return ['lines' => $lines, 'subtotal_paisa' => array_sum(array_column($lines, 'gross_paisa')), 'line_discount_paisa' => array_sum(array_column($lines, 'line_discount_paisa')), 'invoice_discount_paisa' => $invoice, 'tax_paisa' => array_sum(array_column($lines, 'tax_paisa')), 'total_paisa' => $total];
    }

    public function prepare(int $actor, Tenant $tenant, array $input): array
    {
        $type = $input['type'];
        if (! in_array($type, ['sale', 'purchase', 'expense'])) {
            $this->a->fail('Use original bill to create a return.');
        }
        if ($type !== 'sale') {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
        }
        $party = isset($input['contact_id']) && $input['contact_id'] ? $this->a->requireRow('contacts', $input['contact_id']) : $this->a->rows('contacts')->where('is_system', true)->where('name', $type === 'sale' ? 'Walk-in' : 'Expense payee')->first();
        if (! $party || $party->archived_at || ($type === 'purchase' && ($party->is_system || ! $party->is_supplier)) || ($type === 'sale' && ! $party->is_customer) || ($type === 'expense' && ! $this->a->payableParty($party))) {
            $this->a->fail('Choose valid customer, supplier or expense payee.', 'contact_id');
        }
        $seen = [];
        foreach ($input['lines'] as $i => &$row) {
            if (empty($row[$type === 'expense' ? 'expense_category_id' : 'item_id'])) {
                $this->a->fail('Choose item/category.', 'lines.'.$i);
            }
            if ($type === 'expense') {
                $category = $this->a->requireRow('expense_categories', $row['expense_category_id']);
                if ($category->archived_at) {
                    $this->a->fail('Expense category archived.');
                }
                $row['item_id'] = null;
                $row['description'] = $category->name;
                $row['unit_snapshot'] = 'expense';
            } else {
                $item = $this->a->requireRow('items', $row['item_id']);
                if ($item->archived_at) {
                    $this->a->fail('Item archived.', 'lines.'.$i.'.item_id');
                }
                $seen[$item->id] = ($seen[$item->id] ?? 0) + Money::quantity($row['qty']);
                if ($seen[$item->id] > 1000000000) {
                    $this->a->fail('Combined item quantity exceeds limit.', 'lines.'.$i.'.qty');
                }
                $row['expense_category_id'] = null;
                $row['description'] = $item->name;
                $row['unit_snapshot'] = $item->unit_label;
                $row['tax_category'] ??= $item->default_tax_category;
                $row['tax_bps'] ??= (int) $item->default_tax_bps;
                if (Money::parse($row['unit_price']) === 0 && ($type === 'purchase' || empty($input['promotional_confirmed']) || ! in_array($this->a->role($actor), ['owner', 'manager']))) {
                    $this->a->fail('Enter positive price. Promotional sale requires owner/manager confirmation.');
                }
            }
            $row['tax_category'] ??= $tenant->tax_recording_enabled ? 'standard' : 'outside_scope';
            $row['tax_bps'] ??= $tenant->tax_recording_enabled ? $tenant->default_tax_bps : 0;
            if ($row['tax_category'] !== 'standard' && $row['tax_bps'] != 0) {
                $this->a->fail('Use zero tax rate for zero, exempt or outside-scope items.');
            }
            if (! $tenant->tax_recording_enabled && ($row['tax_bps'] || ! empty($input['vat_recoverable']))) {
                $this->a->fail('Enable bookkeeping tax in business settings first.');
            }
        } unset($row);

        return [$party, $this->calculate($input)];
    }

    public function review(int $actor, Tenant $tenant, array $input, array $context, bool $saving = false): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant', 'cashier']);
        $offer = null;
        $normal = $input;
        if (! empty($input['basket_offer_id'])) {
            if ($input['type'] !== 'sale') {
                $this->a->fail('Named offers apply to sales only.', 'basket_offer_id');
            }
            if (Money::parse($input['invoice_discount'] ?? '0') || ($input['invoice_discount_bps'] ?? 0)) {
                $this->a->fail('Remove separate bill discount before selecting an offer.', 'invoice_discount');
            }
            $review = app(BasketService::class)->checkout($actor, $input, $input['lines'], $context, $saving);
            unset($normal['invoice_discount_bps']);
            $normal = [...$normal, ...$review['input']];
            $preview = $review['preview'];
            $offer = $preview['basket_offer'];
            if ($saving) {
                abort_unless((string) ($input['expected_total_paisa'] ?? '') === (string) $preview['total_paisa'], 409, 'Bill total changed. Review current offer total.');
            }
        }
        [$party, $totals] = $this->prepare($actor, $tenant, $normal);
        if (! $offer) {
            $preview = [...$totals, 'basket_offer' => null, 'fingerprint' => hash('sha256', json_encode(['source' => $context, 'party' => (int) $party->id, 'date' => NepaliDate::normalize($input['business_date_bs']), 'totals' => $totals], JSON_THROW_ON_ERROR))];
        }

        return compact('normal', 'party', 'totals', 'preview', 'offer');
    }

    private function draft(int $actor, int $id, int $version): object
    {
        $doc = $this->a->requireRow('documents', $id);
        if ($this->a->role($actor) === 'cashier' && ($doc->created_by != $actor || $doc->type !== 'sale')) {
            abort(403);
        }
        abort_unless($doc->status === 'draft' && $doc->version == $version, 409, 'Draft changed or posted.');

        return $doc;
    }

    public function preview(int $actor, array $input, ?int $id = null): array
    {
        $doc = $id ? $this->draft($actor, $id, (int) $input['version']) : null;
        $input['type'] = $doc?->type ?? $input['type'];
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());

        return $this->review($actor, $tenant, $input, ['type' => 'document', 'id' => $id, 'version' => (int) ($doc?->version ?? 0)])['preview'];
    }

    public function postPreview(int $actor, int $id, array $input, bool $posting = false): array
    {
        $doc = $this->draft($actor, $id, (int) $input['version']);
        $raw = [...json_decode($doc->draft_input, true), 'type' => $doc->type];
        unset($raw['expected_fingerprint'], $raw['expected_total_paisa']);
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());
        $review = $this->review($actor, $tenant, [...$raw, ...array_intersect_key($input, array_flip(['expected_fingerprint', 'expected_total_paisa']))], ['type' => 'document', 'id' => $id, 'version' => (int) $doc->version], $posting);
        if ($doc->basket_offer_id) {
            $saved = json_decode($doc->basket_offer_snapshot, true);
            $current = $review['offer'];
            unset($saved['checkout_source'], $current['checkout_source']);
            abort_unless($saved === $current && (string) $review['totals']['total_paisa'] === (string) $doc->total_paisa, 409, 'Draft offer changed. Edit and review the draft before posting.');
        }

        return $review['preview'];
    }

    private function insert(int $actor, Tenant $tenant, array $input, ?array $trustedOffer = null): int
    {
        if ($trustedOffer) {
            [$party, $totals] = $this->prepare($actor, $tenant, $input);
            $offer = $trustedOffer;
        } else {
            ['party' => $party, 'totals' => $totals, 'offer' => $offer] = $this->review($actor, $tenant, $input, ['type' => 'document', 'id' => null, 'version' => 0], true);
        }

        return $this->insertPrepared($actor, $tenant, $input, $party, $totals, $offer);
    }

    private function insertPrepared(int $actor, Tenant $tenant, array $input, object $party, array $totals, ?array $offer): int
    {
        $date = NepaliDate::normalize($input['business_date_bs']);
        $input['due_date_bs'] = $this->parties->dueDate($party, $input['type'], $date, $input['due_date_bs'] ?? null);
        if (! empty($input['due_date_bs']) && NepaliDate::normalize($input['due_date_bs']) < $date) {
            $this->a->fail('Due date precedes bill date.');
        }
        $id = DB::table('documents')->insertGetId(['tenant_id' => $tenant->id, 'type' => $input['type'], 'contact_id' => $party->id, 'business_date_bs' => $date, 'due_date_bs' => ! empty($input['due_date_bs']) ? NepaliDate::normalize($input['due_date_bs']) : null, 'supplier_bill_number' => $input['supplier_bill_number'] ?? null, 'supplier_bill_date_bs' => ! empty($input['supplier_bill_date_bs']) ? NepaliDate::normalize($input['supplier_bill_date_bs']) : null, 'notes' => $input['notes'] ?? null, 'party_snapshot' => json_encode($party), 'business_snapshot' => json_encode($tenant->only(['name', 'address', 'phone', 'pan'])), 'vat_recoverable' => $input['vat_recoverable'] ?? false, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(), 'draft_input' => json_encode($input), ...array_diff_key($totals, ['lines' => true])]);
        $this->a->rows('documents')->where('id', $id)->update(['basket_offer_id' => $offer['id'] ?? null, 'basket_offer_snapshot' => $offer ? json_encode($offer) : null]);
        foreach ($totals['lines'] as $line) {
            DB::table('document_lines')->insert(['tenant_id' => $tenant->id, 'document_id' => $id, ...$line]);
        }

        return $id;
    }

    public function postStaged(int $actor, Tenant $tenant, object $order, array $plan): array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Locked staged bill transaction required.');
        }
        $current = app(WorkflowService::class)->row($actor, (int) $order->id);
        abort_unless($tenant->id == app(CurrentTenant::class)->id() && $current->version == $plan['workflow_version'] && $current->id == $plan['workflow_id'], 409);
        $totals = array_intersect_key($plan, array_flip(['lines', 'subtotal_paisa', 'line_discount_paisa', 'invoice_discount_paisa', 'tax_paisa', 'total_paisa']));
        $party = $this->a->requireRow('contacts', $current->contact_id);
        $id = $this->insertPrepared($actor, $tenant, $plan['terms'], $party, $totals, null);
        $this->a->rows('documents')->where('id', $id)->update(['workflow_id' => $current->id, 'fulfilment_policy' => $plan['fulfilment_policy'], 'workflow_version_at_post' => $current->version, 'source_order_snapshot' => json_encode($plan['source_order_snapshot'], JSON_THROW_ON_ERROR)]);
        $lines = $this->a->rows('document_lines')->where('document_id', $id)->get()->keyBy('position');
        foreach ($plan['allocations'] as $allocation) {
            DB::table('workflow_bill_allocations')->insert(['tenant_id' => $tenant->id, 'workflow_id' => $current->id, 'document_line_id' => $lines[$allocation['invoice_position']]->id, 'fulfilment_line_id' => $allocation['fulfilment_line_id'], 'position' => $allocation['position'], 'qty_milli' => $allocation['qty_milli'], 'clearing_value_paisa' => $allocation['clearing_value_paisa']]);
        }
        $this->postDocument($actor, $tenant, $id, [...$plan['terms'], 'expected_total_paisa' => (string) $plan['total_paisa']]);
        $this->a->rows('documents')->where('id', $id)->update(['party_snapshot' => $current->party_snapshot, 'business_snapshot' => $current->business_snapshot]);

        return ['table' => 'documents', 'id' => $id];
    }

    private function number(Tenant $tenant, string $type, int $date): string
    {
        $fy = NepaliDate::fiscalYearLabel($date);
        $q = $this->a->rows('document_sequences')->where('type', $type)->where('fiscal_year_label', $fy);
        if (! $q->exists()) {
            DB::table('document_sequences')->insert(['tenant_id' => $tenant->id, 'type' => $type, 'fiscal_year_label' => $fy, 'next_number' => 1]);
        }
        $sequence = $q->lockForUpdate()->first();
        $q->increment('next_number');
        $prefix = ['sale' => 'SAL', 'purchase' => 'PUR', 'expense' => 'EXP', 'sale_return' => 'SR', 'purchase_return' => 'PR'][$type];

        return $prefix.'-'.explode('/', $fy)[0].'-'.str_pad((string) $sequence->next_number, 6, '0', STR_PAD_LEFT);
    }

    private function postDocument(int $actor, Tenant $tenant, int $id, array $input): void
    {
        $doc = $this->a->requireRow('documents', $id);
        abort_unless($doc->status === 'draft', 409, 'Bill already posted.');
        $this->a->assertOpenDate($tenant, $doc->business_date_bs);
        if ($this->a->role($actor) === 'cashier' && ($doc->created_by != $actor || $doc->type !== 'sale')) {
            abort(403);
        }
        if ($doc->type !== 'sale') {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
        }
        if ((string) ($input['expected_total_paisa'] ?? '') !== (string) $doc->total_paisa) {
            abort(409, 'Bill total changed. Review total: '.Money::format((int) $doc->total_paisa));
        }
        $party = $this->a->requireRow('contacts', $doc->contact_id);
        if ($party->archived_at) {
            $this->a->fail('Party archived.');
        }
        if ($doc->supplier_bill_number && $this->a->rows('documents')->where('id', '<>', $id)->where('type', 'purchase')->where('contact_id', $doc->contact_id)->where('supplier_bill_number', $doc->supplier_bill_number)->where('fiscal_year_label', NepaliDate::fiscalYearLabel($doc->business_date_bs))->where('status', 'posted')->exists()) {
            $this->a->fail('Supplier bill reference already recorded.', 'supplier_bill_number');
        }
        $lines = [$this->a->line($doc->type === 'sale' ? 'receivables' : 'payables', $doc->type === 'sale' ? (int) $doc->total_paisa : -(int) $doc->total_paisa, (int) $doc->contact_id)];
        foreach ($this->a->rows('document_lines')->where('document_id', $id)->orderBy('position')->get() as $line) {
            $cost = 0;
            if ($doc->type === 'expense') {
                $category = $this->a->requireRow('expense_categories', $line->expense_category_id);
                if ($category->archived_at) {
                    $this->a->fail('Category archived.');
                }
                $lines[] = $this->a->line((int) $category->account_id, (int) ($doc->vat_recoverable ? $line->net_base_paisa : $line->total_paisa));
            } else {
                $item = $this->a->requireRow('items', $line->item_id);
                if ($item->archived_at && ! $doc->workflow_id) {
                    $this->a->fail('Item archived.');
                }
                $stagedCost = $doc->workflow_id ? (int) $this->a->rows('workflow_bill_allocations')->where('document_line_id', $line->id)->sum('clearing_value_paisa') : null;
                if (($doc->fulfilment_policy ?? null) === 'bill_first') {
                    // An invoice establishes dues and tax; goods/work remain physically unfulfilled.
                    $held = $doc->type === 'sale' ? -(int) $line->net_base_paisa : (int) ($doc->vat_recoverable ? $line->net_base_paisa : $line->total_paisa);
                    $lines[] = $this->a->line($doc->type === 'sale' ? 'sales_unfulfilled' : 'billed_unreceived', $held);
                } elseif ($doc->type === 'sale') {
                    $lines[] = $this->a->line('sales', -(int) $line->net_base_paisa);
                    if ($item->kind === 'stock') {
                        $cost = $stagedCost ?? $this->stock->issue($actor, (int) $item->id, $doc->business_date_bs, (int) $line->qty_milli, ['document_line_id' => $line->id])['cost'];
                        $lines[] = $this->a->line('cogs', $cost);
                        $lines[] = $this->a->line($doc->workflow_id ? 'delivered_unbilled' : 'inventory', -$cost);
                    }
                } else {
                    $cost = (int) ($doc->vat_recoverable ? $line->net_base_paisa : $line->total_paisa);
                    $lines[] = $this->a->line($item->kind === 'stock' ? ($doc->workflow_id ? 'received_unbilled' : 'inventory') : 'general_expense', $item->kind === 'stock' && $doc->workflow_id ? $stagedCost : $cost);
                    if ($item->kind === 'stock') {
                        if ($doc->workflow_id) {
                            $variance = $cost - $stagedCost;
                            $lines[] = $this->a->line($variance >= 0 ? 'inventory_loss' : 'inventory_gain', $variance);
                            $cost = $stagedCost;
                        } else {
                            $this->stock->receive($actor, (int) $item->id, $doc->business_date_bs, (int) $line->qty_milli, $cost, ['document_line_id' => $line->id]);
                        }
                    }
                    $this->a->rows('items')->where('id', $item->id)->update(['last_purchase_price_paisa' => $line->unit_price_paisa]);
                }
            }
            if ($line->tax_paisa) {
                if ($doc->type === 'sale') {
                    $lines[] = $this->a->line('output_vat', -(int) $line->tax_paisa);
                } elseif ($doc->vat_recoverable) {
                    $lines[] = $this->a->line('input_vat', (int) $line->tax_paisa);
                }
            }
            $this->a->rows('document_lines')->where('id', $line->id)->update(['inventory_cost_paisa' => $cost]);
        }
        $number = $this->number($tenant, $doc->type, $doc->business_date_bs);
        $journal = $this->a->post($actor, $doc->business_date_bs, ['type' => 'document', 'id' => $id], $lines, $number);
        $this->a->rows('documents')->where('id', $id)->update(['business_snapshot' => json_encode($tenant->only(['name', 'address', 'phone', 'pan'])), 'party_snapshot' => json_encode($party), 'status' => 'posted', 'posted_at' => now(), 'posted_by' => $actor, 'number' => $number, 'fiscal_year_label' => NepaliDate::fiscalYearLabel($doc->business_date_bs), 'journal_id' => $journal, 'draft_input' => null, 'version' => $doc->version + 1]);
        $paid = Money::parse($input['paid_now'] ?? '0');
        if ($paid > $doc->total_paisa || ($party->is_system && $paid !== (int) $doc->total_paisa)) {
            $this->a->fail('Walk-in/expense payee must be fully paid; amount cannot exceed bill total.', 'paid_now');
        }
        if ($paid > 0) {
            $this->money->create($actor, $tenant, ['kind' => $doc->type === 'sale' ? 'receipt' : 'supplier_payment', 'amount' => Money::format($paid), 'contact_id' => $doc->contact_id, 'money_account_id' => $input['money_account_id'] ?? 0, 'business_date_bs' => $doc->business_date_bs, 'overdraft_confirmed' => $input['overdraft_confirmed'] ?? false, 'allocations' => [['document_id' => $id, 'amount' => Money::format($paid)]]]);
        }
        if ($doc->type === 'sale') {
            $this->parties->assertCredit($party, $doc->business_date_bs, (int) $doc->total_paisa - $paid);
        }
    }

    public function save(int $actor, array $input, string $uuid, bool $post = true, ?array $trustedOffer = null): array
    {
        return $this->a->mutate($actor, $uuid, $post ? 'document.post' : 'document.draft', $input, function (Tenant $tenant) use ($actor, $input, $post, $trustedOffer) {
            $id = $this->insert($actor, $tenant, $input, $trustedOffer);
            if ($post) {
                $this->postDocument($actor, $tenant, $id, $input);
            }

            return ['table' => 'documents', 'id' => $id];
        });
    }

    public function post(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'draft.post.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $doc = $this->draft($actor, $id, (int) $input['version']);
            if ($doc->basket_offer_id) {
                $this->postPreview($actor, $id, $input, true);
            }
            $this->postDocument($actor, $tenant, $id, $input);

            return ['table' => 'documents', 'id' => $id];
        });
    }

    public function updateDraft(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'draft.update.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $old = $this->a->requireRow('documents', $id);
            abort_unless($old->status === 'draft' && $old->version == $input['version'], 409, 'Draft changed or posted.');
            if ($this->a->role($actor) === 'cashier' && $old->created_by != $actor) {
                abort(403);
            }
            $input['type'] = $old->type;
            ['party' => $party, 'totals' => $totals, 'offer' => $offer] = $this->review($actor, $tenant, $input, ['type' => 'document', 'id' => $id, 'version' => (int) $old->version], true);
            $date = NepaliDate::normalize($input['business_date_bs']);
            $due = $this->parties->dueDate($party, $old->type, $date, $input['due_date_bs'] ?? null);
            if ($due && $due < $date) {
                $this->a->fail('Due date precedes bill date.');
            } $this->a->rows('document_lines')->where('document_id', $id)->delete();
            $this->a->rows('documents')->where('id', $id)->update(['contact_id' => $party->id, 'business_date_bs' => $date, 'due_date_bs' => $due, 'supplier_bill_number' => $input['supplier_bill_number'] ?? null, 'supplier_bill_date_bs' => ! empty($input['supplier_bill_date_bs']) ? NepaliDate::normalize($input['supplier_bill_date_bs']) : null, 'vat_recoverable' => $input['vat_recoverable'] ?? false, 'business_snapshot' => json_encode($tenant->only(['name', 'address', 'phone', 'pan'])), 'notes' => $input['notes'] ?? null, 'party_snapshot' => json_encode($party), 'draft_input' => json_encode($input), 'version' => $old->version + 1, ...array_diff_key($totals, ['lines' => true])]);
            $this->a->rows('documents')->where('id', $id)->update(['basket_offer_id' => $offer['id'] ?? null, 'basket_offer_snapshot' => $offer ? json_encode($offer) : null]);
            foreach ($totals['lines'] as $line) {
                DB::table('document_lines')->insert(['tenant_id' => $tenant->id, 'document_id' => $id, ...$line]);
            }

            return ['table' => 'documents', 'id' => $id];
        });
    }

    private function returnPlan(int $actor, Tenant $tenant, int $sourceId, array $input): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
        $original = $this->a->requireRow('documents', $sourceId);
        if ($original->status !== 'posted' || ! in_array($original->type, ['sale', 'purchase'])) {
            $this->a->fail('Choose posted sale/purchase.');
        }
        $date = NepaliDate::normalize($input['business_date_bs']);
        $this->a->assertOpenDate($tenant, $date);
        if ($date < $original->business_date_bs) {
            $this->a->fail('Return date precedes original bill.');
        }

        $transit = collect($input['lines'])->contains(fn ($row) => ($row['return_mode'] ?? null) === 'transit');
        if ($transit && (! isset($input['version'], $input['workflow_version']) || ($original->fulfilment_policy ?? null) !== 'bill_first')) {
            $this->a->fail('Review the current bill and order before a transit return.', 'version');
        }
        if (isset($input['version'])) {
            abort_unless($original->version == $input['version'], 409, 'Source bill changed. Review again.');
        }
        $workflow = $original->workflow_id ? $this->a->requireRow('business_workflows', $original->workflow_id) : null;
        if (isset($input['workflow_version'])) {
            abort_unless($workflow && $workflow->version == $input['workflow_version'], 409, 'Source order changed. Review again.');
        }
        $newLines = [];
        $history = [];
        $returnAllocations = [];

        $seen = [];
        foreach ($input['lines'] as $i => $row) {
            $line = $this->a->requireRow('document_lines', $row['source_line_id']);
            if ($line->document_id != $sourceId || isset($seen[$line->id])) {
                $this->a->fail('Invalid or duplicate original line.');
            } $seen[$line->id] = true;
            if (($original->fulfilment_policy ?? null) !== 'bill_first' && (isset($row['return_source']) || ($row['return_mode'] ?? 'completed') !== 'completed')) {
                $this->a->fail('This invoice has no bill-first fulfilment source. Use its ordinary return.', 'lines.'.$i.'.return_source');
            }
            $prior = $this->a->rows('document_lines')->where('source_line_id', $line->id)->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->get();
            $returnedQty = (int) $prior->sum('qty_milli');
            $history[] = ['source_line_id' => (int) $line->id, 'prior' => $prior->all()];
            $qty = Money::quantity($row['qty']);
            if ($qty <= 0 || $returnedQty + $qty > $line->qty_milli) {
                $this->a->fail('Return exceeds available quantity.', 'lines.'.$i.'.qty');
            }
            $new = ['position' => $i + 1, 'item_id' => $line->item_id, 'source_line_id' => $line->id, 'description' => $line->description, 'unit_snapshot' => $line->unit_snapshot, 'qty_milli' => $qty, 'unit_price_paisa' => $line->unit_price_paisa, 'tax_category' => $line->tax_category, 'tax_bps' => $line->tax_bps];
            if ($line->measurement_snapshot) {
                $new['measurement_snapshot'] = json_encode(['mode' => 'return', 'source_measurement' => json_decode($line->measurement_snapshot, true)]);
            }
            foreach (['gross_paisa', 'line_discount_paisa', 'invoice_discount_paisa', 'net_base_paisa', 'tax_paisa', 'inventory_cost_paisa'] as $component) {
                $new[$component] = Money::multiplyDivide((int) $line->$component, $returnedQty + $qty, (int) $line->qty_milli) - (int) $prior->sum($component);
            }
            if (($original->fulfilment_policy ?? null) === 'bill_first') {
                $allocation = app(FulfilmentService::class)->billFirstReturnPlan($original, $line, $row, $qty, $date, $new);
                $returnAllocations[$i + 1] = $allocation;
                $new['inventory_cost_paisa'] = $allocation['inventory_cost_paisa'];
            }
            $new['total_paisa'] = $new['net_base_paisa'] + $new['tax_paisa'];
            $newLines[] = $new;
        }
        $total = array_sum(array_column($newLines, 'total_paisa'));
        $stockContext = [];
        $sourceContext = [];
        foreach ($newLines as $line) {
            $allocation = $returnAllocations[$line['position']] ?? null;
            if ($allocation && $allocation['dispatch_allocation_id']) {
                $dispatch = $this->a->requireRow('workflow_dispatch_allocations', $allocation['dispatch_allocation_id']);
                $physical = $this->a->requireRow('workflow_fulfilment_lines', $dispatch->fulfilment_line_id);
                $stage = $this->a->requireRow('workflow_fulfilments', $physical->fulfilment_id);
                $sourceContext[] = ['dispatch' => (array) $dispatch, 'stage' => (array) $stage, 'package' => (array) $this->a->rows('workflow_packages')->where('fulfilment_id', $stage->id)->first()];
            }
            $item = $this->a->requireRow('items', $line['item_id']);
            if ($item->kind !== 'stock' || ($allocation && $allocation['dispatch_allocation_id'] === null)) {
                continue;
            }
            if ($date < ($tenant->last_stock_date_bs ?? 0)) {
                $this->a->fail('Stock date precedes last stock transaction.', 'business_date_bs');
            }
            $pool = $this->a->rows('inventory_balances')->where('item_id', $line['item_id'])->first();
            $stockContext[$line['item_id']] ??= ['qty_milli' => (int) ($pool->qty_milli ?? 0), 'value_paisa' => (int) ($pool->value_paisa ?? 0)];
        }
        $balance = $this->money->invoiceBalance($sourceId);
        $refund = ! empty($input['refund_now']) ? min($total, max(0, $total - $balance['signed_due_paisa'])) : 0;
        $refundAccount = null;
        if ($refund > 0) {
            if (empty($input['money_account_id'])) {
                $this->a->fail('Choose payment account.', 'money_account_id');
            }
            $refundAccount = $this->a->requireRow('accounts', $input['money_account_id'] ?? 0);
            if (! $refundAccount->is_money || $refundAccount->archived_at) {
                $this->a->fail('Choose active cash/bank account.', 'money_account_id');
            }
        }
        $plan = ['business_date_bs' => $date, 'total_paisa' => $total, 'lines' => $newLines, 'return_allocations' => $returnAllocations, 'refund_paisa' => $refund];
        $context = ['original' => (array) $original, 'workflow' => (array) $workflow, 'history' => $history, 'sources' => $sourceContext, 'stock' => $stockContext, 'last_stock_date_bs' => $tenant->last_stock_date_bs, 'invoice_balance' => $balance, 'refund_account' => (array) $refundAccount, 'reason' => $input['reason'], 'refund_now' => (bool) ($input['refund_now'] ?? false), 'overdraft_confirmed' => (bool) ($input['overdraft_confirmed'] ?? false)];
        $plan['fingerprint'] = hash_hmac('sha256', json_encode(['plan' => $plan, 'context' => $context], JSON_THROW_ON_ERROR), config('app.key'));

        return $plan;
    }

    public function returnPreview(int $actor, int $sourceId, array $input): array
    {
        return DB::transaction(function () use ($actor, $sourceId, $input) {
            $tenant = $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor);

            return $this->returnPlan($actor, $tenant, $sourceId, $input);
        });
    }

    public function createReturn(int $actor, int $sourceId, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'document.return.'.$sourceId, $input, function (Tenant $tenant) use ($actor, $sourceId, $input) {
            $plan = $this->returnPlan($actor, $tenant, $sourceId, $input);
            $transit = collect($plan['return_allocations'])->contains(fn ($row) => $row['return_mode'] === 'transit');
            if ($transit && empty($input['expected_fingerprint'])) {
                $this->a->fail('Review the transit return before saving.', 'expected_fingerprint');
            }
            if (isset($input['expected_fingerprint'])) {
                abort_unless(hash_equals($plan['fingerprint'], $input['expected_fingerprint']), 409, 'Return changed. Review again.');
            }
            $original = $this->a->requireRow('documents', $sourceId);
            $date = $plan['business_date_bs'];
            $newLines = $plan['lines'];
            $returnAllocations = $plan['return_allocations'];
            $total = $plan['total_paisa'];
            $type = $original->type.'_return';
            $journalLines = [];
            $sourceOrder = $original->source_order_snapshot ? json_decode($original->source_order_snapshot, true) : null;
            if ($sourceOrder) {
                $sourceOrder['allocation'] = 'cumulative_source_bill_return';
                $sourceOrder['source_bill_id'] = $sourceId;
                $sourcePositions = collect($sourceOrder['lines'])->keyBy('position');
                $sourceLines = $this->a->rows('document_lines')->where('document_id', $sourceId)->get()->keyBy('id');
                $sourceOrder['lines'] = array_map(fn ($line) => ['position' => $line['position'], 'order_position' => $sourcePositions[$sourceLines[$line['source_line_id']]->position]['order_position'], 'gross_rounding_paisa' => $line['gross_paisa'] - Money::multiplyDivide($line['qty_milli'], (int) $line['unit_price_paisa'], 1000), 'tax_rounding_paisa' => $line['tax_paisa'] - Money::multiplyDivide($line['net_base_paisa'], (int) $line['tax_bps'], 10000)], $newLines);
            }
            $number = $this->number($tenant, $type, $date);
            $id = DB::table('documents')->insertGetId(['tenant_id' => $tenant->id, 'type' => $type, 'status' => 'posted', 'contact_id' => $original->contact_id, 'source_document_id' => $sourceId, 'business_date_bs' => $date, 'reason' => $input['reason'], 'party_snapshot' => $original->party_snapshot, 'business_snapshot' => $original->business_snapshot, 'total_paisa' => $total, 'subtotal_paisa' => array_sum(array_column($newLines, 'gross_paisa')), 'line_discount_paisa' => array_sum(array_column($newLines, 'line_discount_paisa')), 'invoice_discount_paisa' => array_sum(array_column($newLines, 'invoice_discount_paisa')), 'tax_paisa' => array_sum(array_column($newLines, 'tax_paisa')), 'vat_recoverable' => $original->vat_recoverable, 'number' => $number, 'fiscal_year_label' => NepaliDate::fiscalYearLabel($date), 'created_by' => $actor, 'posted_by' => $actor, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($sourceOrder) {
                $this->a->rows('documents')->where('id', $id)->update(['source_order_snapshot' => json_encode($sourceOrder, JSON_THROW_ON_ERROR)]);
            }
            if (($original->fulfilment_policy ?? null) === 'bill_first') {
                $this->a->rows('documents')->where('id', $id)->update(['fulfilment_policy' => 'bill_first']);
            }
            $journalLines[] = $this->a->line($original->type === 'sale' ? 'receivables' : 'payables', $original->type === 'sale' ? -$total : $total, (int) $original->contact_id);
            foreach ($newLines as $line) {
                $lineId = DB::table('document_lines')->insertGetId(['tenant_id' => $tenant->id, 'document_id' => $id, ...$line]);
                if (isset($returnAllocations[$line['position']])) {
                    $journalLines = [...$journalLines, ...app(FulfilmentService::class)->recordBilledReturn($actor, $original, $line, $lineId, $returnAllocations[$line['position']], $date)];

                    continue;
                }
                $item = $this->a->requireRow('items', $line['item_id']);
                if ($original->type === 'sale') {
                    $journalLines[] = $this->a->line('sales_returns', $line['net_base_paisa']);
                    $journalLines[] = $this->a->line('output_vat', $line['tax_paisa']);
                    if ($item->kind === 'stock') {
                        $this->stock->receive($actor, (int) $item->id, $date, $line['qty_milli'], $line['inventory_cost_paisa'], ['document_line_id' => $lineId]);
                        $journalLines[] = $this->a->line('inventory', $line['inventory_cost_paisa']);
                        $journalLines[] = $this->a->line('cogs', -$line['inventory_cost_paisa']);
                    }
                } else {
                    $originalCost = $original->vat_recoverable ? $line['net_base_paisa'] : $line['total_paisa'];
                    $poolCost = $originalCost;
                    if ($item->kind === 'stock') {
                        $poolCost = $this->stock->issue($actor, (int) $item->id, $date, $line['qty_milli'], ['document_line_id' => $lineId])['cost'];
                    }
                    $journalLines[] = $this->a->line($item->kind === 'stock' ? 'inventory' : 'general_expense', -$poolCost);
                    if ($original->vat_recoverable) {
                        $journalLines[] = $this->a->line('input_vat', -$line['tax_paisa']);
                    }
                    $variance = $poolCost - $originalCost;
                    $journalLines[] = $this->a->line($variance >= 0 ? 'inventory_loss' : 'inventory_gain', $variance);
                    // Preserve original cumulative return components; actual moving-average removal lives in stock_movements.
                }
            }
            $nonzero = array_filter($journalLines, fn ($l) => $l['debit_paisa'] || $l['credit_paisa']);
            $journal = $nonzero ? $this->a->post($actor, $date, ['type' => 'document', 'id' => $id], $journalLines, $number) : null;
            $this->a->rows('documents')->where('id', $id)->update(['journal_id' => $journal]);
            app(FulfilmentService::class)->documentChanged($original);
            if (! empty($input['refund_now'])) {
                $credit = $this->money->invoiceBalance($sourceId)['credit_paisa'];
                $amount = min($total, $credit);
                if ($amount > 0) {
                    $this->money->create($actor, $tenant, ['kind' => $original->type === 'sale' ? 'customer_refund' : 'supplier_refund', 'contact_id' => $original->contact_id, 'money_account_id' => $input['money_account_id'] ?? 0, 'amount' => Money::format($amount), 'business_date_bs' => $date, 'overdraft_confirmed' => $input['overdraft_confirmed'] ?? false, 'allocations' => [['document_id' => $sourceId, 'amount' => Money::format($amount)]]]);
                }
            }

            return ['table' => 'documents', 'id' => $id];
        });
    }

    public function cloneDraft(int $actor, int $id, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'document.clone.'.$id, [], function (Tenant $tenant) use ($actor, $id) {
            $old = $this->a->requireRow('documents', $id);
            abort_unless(in_array($old->type, ['sale', 'purchase', 'expense']), 422, 'Clone original bill.');
            if ($this->a->role($actor) === 'cashier' && ($old->type !== 'sale' || $old->created_by != $actor)) {
                abort(403);
            }
            $offer = $old->basket_offer_snapshot ? json_decode($old->basket_offer_snapshot, true) : null;
            $lines = $this->a->rows('document_lines')->where('document_id', $id)->orderBy('position')->get()->map(fn ($line) => ['item_id' => $line->item_id, 'expense_category_id' => $line->expense_category_id, 'qty' => Money::format((int) $line->qty_milli, 3), 'unit_price' => Money::format((int) $line->unit_price_paisa), 'discount' => Money::format((int) ($offer['line_bases'][$line->position - 1]['line_discount_paisa'] ?? $line->line_discount_paisa)), 'tax_category' => $line->tax_category, 'tax_bps' => $line->tax_bps])->all();
            $input = ['type' => $old->type, 'contact_id' => $old->contact_id, 'business_date_bs' => NepaliDate::today(), 'notes' => $old->notes, 'vat_recoverable' => (bool) $old->vat_recoverable, 'promotional_confirmed' => true, 'invoice_discount' => Money::format((int) $old->invoice_discount_paisa), 'lines' => $lines];

            if ($offer) {
                $input['invoice_discount'] = '0';
            }
            if ($old->workflow_id) {
                $input['invoice_discount'] = '0';
                foreach ($input['lines'] as &$line) {
                    $line['discount'] = '0';
                }
                unset($line);
            }

            return ['table' => 'documents', 'id' => $this->insert($actor, $tenant, $input)];
        });
    }

    public function cancel(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'document.cancel.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $doc = $this->a->requireRow('documents', $id);
            if ($doc->status === 'cancelled') {
                return ['table' => 'documents', 'id' => $id];
            } if ($doc->status !== 'posted') {
                $this->a->fail('Delete draft instead.');
            }
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->a->assertOpenDate($tenant, $doc->business_date_bs);
            $this->a->assertOpenDate($tenant, $date);
            if ($date < $doc->business_date_bs) {
                $this->a->fail('Reversal date precedes bill.');
            }
            $activePayments = $this->a->rows('payment_allocations')->where('document_id', $id)->whereIn('payment_id', $this->a->rows('payments')->where('status', 'posted')->select('id'))->exists();
            if ($activePayments || $this->a->rows('documents')->where('source_document_id', $id)->where('status', 'posted')->exists()) {
                $this->a->fail('Cancel related payments/returns first.');
            }
            if ($doc->source_document_id) {
                $lineIds = $this->a->rows('document_lines')->where('document_id', $id)->pluck('source_line_id');
                if ($this->a->rows('document_lines')->whereIn('source_line_id', $lineIds)->where('document_id', '>', $id)->whereIn('document_id', $this->a->rows('documents')->where('status', 'posted')->select('id'))->exists()) {
                    $this->a->fail('Cancel later partial returns first.');
                }
                if ($this->a->rows('payment_allocations')->where('document_id', $doc->source_document_id)->whereIn('payment_id', $this->a->rows('payments')->where('status', 'posted')->whereIn('kind', ['customer_refund', 'supplier_refund'])->select('id'))->exists()) {
                    $this->a->fail('Cancel related refunds first.');
                }
            }
            app(FulfilmentService::class)->beforeBillCancellation($actor, $doc, $date);
            foreach ($this->a->rows('stock_movements')->whereIn('document_line_id', $this->a->rows('document_lines')->where('document_id', $id)->select('id'))->where('source_event', 'post')->orderByDesc('id')->get() as $movement) {
                $this->stock->reverse($actor, (int) $movement->id, $date);
            }
            $journal = $doc->journal_id ? $this->a->reverse($actor, (int) $doc->journal_id, $date, $input['reason']) : null;
            $this->a->rows('documents')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor, 'cancellation_reason' => $input['reason'], 'cancellation_date_bs' => $date, 'reversal_journal_id' => $journal]);
            app(FulfilmentService::class)->documentChanged($doc);

            return ['table' => 'documents', 'id' => $id];
        });
    }

    public function deleteDraft(int $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $this->a->lockTenant(app(CurrentTenant::class)->id(), $actor);
            $doc = $this->a->requireRow('documents', $id);
            abort_unless($doc->status === 'draft', 409, 'Posted bills cannot be deleted.');
            if ($this->a->role($actor) === 'cashier' && $doc->created_by != $actor) {
                abort(403);
            }
            $paths = $this->a->rows('attachments')->where('document_id', $id)->pluck('storage_path')->all();
            $this->a->rows('attachments')->where('document_id', $id)->delete();
            $this->a->rows('document_lines')->where('document_id', $id)->delete();
            $this->a->rows('documents')->where('id', $id)->delete();
            $this->a->audit($actor, 'draft.deleted', 'documents', $id);
            Tenant::whereKey(app(CurrentTenant::class)->id())->increment('data_version');
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
        });
    }
}
