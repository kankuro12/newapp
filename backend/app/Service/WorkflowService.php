<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowService
{
    public const KINDS = ['quote', 'sales_order', 'purchase_order'];

    public const NICHES = ['general', 'glass', 'wood', 'laundry', 'repair', 'tailor', 'printing', 'bakery', 'field_service'];

    public function __construct(private AccountingService $a, private DocumentService $documents) {}

    public function row(int $actor, int $id): object
    {
        $row = $this->a->requireRow('business_workflows', $id);
        if ($this->a->role($actor) === 'cashier') {
            abort_unless($row->kind !== 'purchase_order' && $row->created_by == $actor, 403);
        }

        return $row;
    }

    public function data(int $actor, int $id): array
    {
        $row = (array) $this->row($actor, $id);
        foreach (['lines', 'bill_input', 'party_snapshot', 'business_snapshot'] as $key) {
            $row[$key] = json_decode($row[$key], true);
        }
        $row['number'] = ['quote' => 'QUO', 'sales_order' => 'SO', 'purchase_order' => 'PO'][$row['kind']].'-'.str_pad((string) $row['sequence'], 6, '0', STR_PAD_LEFT);
        $row['expired'] = $row['kind'] === 'quote' && $row['valid_until_bs'] && $row['valid_until_bs'] < NepaliDate::today();
        $row['overdue'] = $row['due_date_bs'] && $row['due_date_bs'] < NepaliDate::today() && ! in_array($row['status'], ['fulfilled', 'cancelled', 'converted']);
        $row['child_id'] = $this->a->rows('business_workflows')->where('source_id', $id)->value('id');
        $row['bill_status'] = $row['document_id'] ? $this->a->requireRow('documents', $row['document_id'])->status : null;

        return $row;
    }

    private function authorizeKind(int $actor, string $kind): void
    {
        $this->a->authorize($actor, $kind === 'purchase_order' ? ['owner', 'manager', 'accountant'] : ['owner', 'manager', 'accountant', 'cashier']);
    }

    private function insert(int $actor, Tenant $tenant, array $data): int
    {
        $sequence = (int) $this->a->rows('business_workflows')->where('kind', $data['kind'])->max('sequence') + 1;

        return DB::table('business_workflows')->insertGetId(['tenant_id' => $tenant->id, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(), 'sequence' => $sequence, ...$data]);
    }

    public function save(int $actor, array $input, string $uuid, ?int $id = null): array
    {
        $this->authorizeKind($actor, $input['kind']);

        return $this->a->mutate($actor, $uuid, 'workflow.save.'.($id ?? 'new'), $input, function (Tenant $tenant) use ($actor, $input, $id) {
            $old = $id ? $this->row($actor, $id) : null;
            if ($old) {
                abort_unless($old->version == ($input['version'] ?? 0) && $old->kind === $input['kind'] && ! $old->source_id && in_array($old->status, ['draft', 'open']), 409, 'Record changed or approved. Revise a draft before editing.');
            }
            $date = NepaliDate::normalize($input['business_date_bs']);
            $due = ! empty($input['due_date_bs']) ? NepaliDate::normalize($input['due_date_bs']) : null;
            $valid = $input['kind'] === 'quote' && ! empty($input['valid_until_bs']) ? NepaliDate::normalize($input['valid_until_bs']) : null;
            if (($due && $due < $date) || ($valid && $valid < $date)) {
                $this->a->fail('Validity and fulfilment date must follow record date.');
            }
            [$party,$totals] = $this->documents->prepare($actor, $tenant, [...$input, 'type' => $input['kind'] === 'purchase_order' ? 'purchase' : 'sale']);
            abort_unless((string) $totals['total_paisa'] === (string) $input['expected_total_paisa'], 409, 'Total changed. Review calculated amount.');
            foreach ($totals['lines'] as &$line) {
                $item = $this->a->requireRow('items', $line['item_id']);
                $line['item_kind'] = $item->kind;
                $line['pos_unit'] = $item->pos_unit;
            } unset($line);
            $billInput = ['contact_id' => $party->id, 'lines' => array_map(fn ($line) => ['item_id' => $line['item_id'], 'qty' => Money::format($line['qty_milli'], 3), 'unit_price' => Money::format($line['unit_price_paisa']), 'discount' => Money::format($line['line_discount_paisa']), 'tax_bps' => $line['tax_bps'], 'tax_category' => $line['tax_category']], $totals['lines']), 'invoice_discount' => Money::format($totals['invoice_discount_paisa']), 'promotional_confirmed' => $input['promotional_confirmed'] ?? false];
            $data = ['kind' => $input['kind'], 'status' => $input['kind'] === 'quote' ? 'draft' : 'open', 'business_date_bs' => $date, 'valid_until_bs' => $valid, 'due_date_bs' => $due, 'contact_id' => $party->id, 'niche' => $input['niche'] ?? 'general', 'title' => $input['title'] ?? null, 'reference' => $input['reference'] ?? null, 'specifications' => $input['specifications'] ?? null, 'notes' => $input['notes'] ?? null, 'bill_input' => json_encode($billInput), 'party_snapshot' => json_encode($party), 'business_snapshot' => json_encode($tenant->only(['name', 'address', 'phone', 'pan'])), 'lines' => json_encode($totals['lines']), ...array_diff_key($totals, ['lines' => true])];
            if ($old) {
                $this->a->rows('business_workflows')->where('id', $id)->update([...$data, 'version' => $old->version + 1, 'updated_at' => now()]);
            } else {
                $id = $this->insert($actor, $tenant, $data);
            }

            return ['table' => 'business_workflows', 'id' => $id];
        });
    }

    private function current(int $actor, int $id, array $input): object
    {
        $row = $this->row($actor, $id);
        $this->authorizeKind($actor, $row->kind);
        abort_unless($row->version == $input['version'] && ! in_array($row->status, ['converted', 'cancelled', 'rejected']), 409, 'Record changed or closed. Refresh before continuing.');

        return $row;
    }

    private function validQuote(object $row): void
    {
        if ($row->valid_until_bs && $row->valid_until_bs < NepaliDate::today()) {
            $this->a->fail('Quote expired. Create a revised quote before acceptance or conversion.');
        }
    }

    public function status(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'workflow.status.'.$id, $input, function () use ($actor, $id, $input) {
            $row = $this->current($actor, $id, $input);
            $next = $input['status'];
            $states = $row->kind === 'quote' ? ['draft' => ['sent', 'cancelled'], 'sent' => ['draft', 'accepted', 'rejected', 'cancelled'], 'accepted' => ['cancelled']] : ['open' => ['in_progress', 'ready', 'fulfilled', 'cancelled'], 'in_progress' => ['ready', 'cancelled'], 'ready' => ['in_progress', 'fulfilled', 'cancelled'], 'fulfilled' => ['cancelled']];
            abort_unless(in_array($next, $states[$row->status] ?? [], true), 409, 'Status transition unavailable.');
            if ($next === 'accepted') {
                $this->validQuote($row);
            }
            if (in_array($next, ['cancelled', 'rejected']) && mb_strlen(trim($input['reason'] ?? '')) < 3) {
                $this->a->fail('Enter reason for cancellation/rejection.', 'reason');
            }
            $this->a->rows('business_workflows')->where('id', $id)->update(['status' => $next, 'version' => $row->version + 1, 'updated_at' => now(), ...(in_array($next, ['cancelled', 'rejected']) ? ['cancellation_reason' => $input['reason']] : [])]);
            $this->a->audit($actor, 'workflow.status.changed', 'business_workflows', $id, ['from' => $row->status, 'to' => $next, 'reason' => $input['reason'] ?? null]);

            return ['table' => 'business_workflows', 'id' => $id];
        });
    }

    public function order(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'workflow.order.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $row = $this->current($actor, $id, $input);
            abort_unless($row->kind === 'quote' && $row->status === 'accepted', 409, 'Accept quote before creating an order.');
            $this->validQuote($row);
            $data = array_diff_key((array) $row, array_flip(['id', 'tenant_id', 'sequence', 'version', 'created_at', 'updated_at', 'created_by', 'cancellation_reason', 'document_id', 'source_id']));
            $child = $this->insert($actor, $tenant, [...$data, 'kind' => 'sales_order', 'status' => 'open', 'source_id' => $id, 'valid_until_bs' => null]);
            $this->a->rows('business_workflows')->where('id', $id)->update(['status' => 'converted', 'version' => $row->version + 1, 'updated_at' => now()]);

            return ['table' => 'business_workflows', 'id' => $child];
        });
    }

    public function bill(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'workflow.bill.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $row = $this->current($actor, $id, $input);
            if ($row->kind === 'quote') {
                abort_unless($row->status === 'accepted', 409, 'Accept quote before billing.');
                $this->validQuote($row);
            }
            $date = NepaliDate::normalize($input['business_date_bs']);
            if ($date < $row->business_date_bs) {
                $this->a->fail('Bill date precedes order/quote date.', 'business_date_bs');
            }
            $snapshot = json_decode($row->lines, true);
            foreach ($snapshot as $line) {
                $item = $this->a->requireRow('items', $line['item_id']);
                if ($item->kind !== $line['item_kind'] || $item->pos_unit !== $line['pos_unit'] || $item->unit_label !== $line['unit_snapshot']) {
                    $this->a->fail('Item unit/kind changed. Create a revised order before billing.');
                }
            }
            $notes = $this->data($actor, $id)['number'].' · '.implode(' · ', array_filter([$row->title, $row->reference, $row->specifications, $row->notes]));
            $bill = [...json_decode($row->bill_input, true), ...$input, 'type' => $row->kind === 'purchase_order' ? 'purchase' : 'sale', 'notes' => mb_substr($notes, 0, 1000)];
            $result = $this->documents->save($actor, $bill, (string) Str::uuid());
            foreach ($this->a->rows('document_lines')->where('document_id', $result['id'])->get() as $line) {
                $original = collect($snapshot)->firstWhere('item_id', $line->item_id);
                $this->a->rows('document_lines')->where('id', $line->id)->update(['description' => $original['description'], 'unit_snapshot' => $original['unit_snapshot']]);
            }
            $this->a->rows('documents')->where('id',$result['id'])->update(['party_snapshot' => $row->party_snapshot, 'business_snapshot' => $row->business_snapshot]);
            $this->a->rows('business_workflows')->where('id',$id)->update(['status' => 'converted', 'document_id' => $result['id'], 'version' => $row->version + 1, 'updated_at' => now()]);

            return $result;
        });
    }
}
