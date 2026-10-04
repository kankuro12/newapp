<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PartyService
{
    public function __construct(private AccountingService $a) {}

    public function manage(int $actor): void
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
    }

    public function configure(int $actor, int $id, array $input): array
    {
        $this->manage($actor);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'party.trading.'.$id, $input, function () use ($actor, $id, $input) {
            $this->manage($actor);
            $party = $this->a->requireRow('contacts', $id);
            abort_if($party->is_system || $party->archived_at, 403);
            abort_unless($party->trading_version == $input['version'], 409, 'Trading setup changed. Reload first.');
            $limit = ($input['credit_limit'] ?? '') === '' ? null : Money::parse($input['credit_limit']);
            $this->a->rows('contacts')->where('id', $id)->update(['sales_terms_days' => $input['sales_terms_days'], 'purchase_terms_days' => $input['purchase_terms_days'], 'credit_limit_paisa' => $limit, 'trading_version' => $party->trading_version + 1, 'updated_at' => now()]);

            return ['table' => 'contacts', 'id' => $id];
        });
    }

    public function dueDate(object $party, string $type, int $date, string|int|null $explicit): int
    {
        return $explicit ? NepaliDate::normalize($explicit) : NepaliDate::addDays($date, (int) ($type === 'sale' ? $party->sales_terms_days : ($type === 'purchase' ? $party->purchase_terms_days : 0)));
    }

    public function assertCredit(object $party, int $date, int $additional): void
    {
        if ($party->credit_limit_paisa === null || $additional <= 0 || $party->is_system) {
            return;
        }
        $account = $this->a->account('receivables');
        $running = $this->a->balance($account, (int) $party->id, $date);
        $peak = $running;
        $changes = DB::table('journal_lines')->where('journal_lines.tenant_id', app(CurrentTenant::class)->id())->where('account_id', $account)->where('contact_id', $party->id)->join('journal_entries as e', fn ($j) => $j->on('e.id', '=', 'journal_lines.journal_entry_id')->on('e.tenant_id', '=', 'journal_lines.tenant_id'))->where('e.business_date_bs', '>', $date)->select('e.business_date_bs')->selectRaw('SUM(debit_paisa-credit_paisa) AS delta')->groupBy('e.business_date_bs')->orderBy('e.business_date_bs')->get();
        foreach ($changes as $change) {
            $running += (int) $change->delta;
            $peak = max($peak, $running);
        }
        if ($peak > (int) $party->credit_limit_paisa) {
            $this->a->fail('Customer credit limit exceeded. Collect more now or review party credit limit.', 'paid_now');
        }
    }

    private function checkRateUnit(object $rate, object $item): void
    {
        if ($rate->unit_snapshot !== $item->unit_label || $rate->pos_unit !== $item->pos_unit || $rate->item_kind !== $item->kind) {
            $this->a->fail('Item unit changed. Update agreed/list price before use.');
        }
    }

    public function rate(int $contact, object $item, string $channel, int $qty = 0, ?int $listId = null, ?int $date = null): ?int
    {
        $pricing = $this->pricing($contact, $item, $channel, $qty, $listId, $date);
        if ($pricing['pricing_scheme'] === 'slab' && $qty > 0) {
            $this->a->fail('Slab prices use separate quantity ranges. Review quantity prices or use POS.');
        }

        return $pricing['customized'] ? $pricing['price_paisa'] : null;
    }

    private function pricingResult(int $qty, int $price, string $scheme, bool $customized, ?object $list = null, array $segments = []): array
    {
        if ($qty > 0 && ! $segments) {
            $segments = [['qty_milli' => $qty, 'price_paisa' => $price, 'from_qty_milli' => 0, 'to_qty_milli' => $qty]];
        }
        foreach ($segments as &$segment) {
            $segment['gross_paisa'] = Money::multiplyDivide($segment['qty_milli'], $segment['price_paisa'], 1000);
        }
        unset($segment);

        return ['pricing_scheme' => $scheme, 'customized' => $customized, 'price_paisa' => $price, 'qty_milli' => $qty, 'gross_paisa' => array_sum(array_column($segments, 'gross_paisa')), 'segments' => $segments, 'price_list_id' => $list?->id, 'list_version' => $list?->version];
    }

    public function pricing(int $contact, object $item, string $channel, int $qty = 0, ?int $listId = null, ?int $date = null): array
    {
        $party = $contact ? $this->a->requireRow('contacts', $contact) : null;
        if ($party && ($party->archived_at || ($channel === 'sale' ? ! $party->is_customer : ! $party->is_supplier))) {
            $this->a->fail('Choose active matching party.', 'contact_id');
        }
        $listId ??= $party ? ($channel === 'sale' ? $party->sales_price_list_id : $party->purchase_price_list_id) : null;
        $list = $listId ? $this->a->requireRow('price_lists', $listId) : null;
        $date ??= NepaliDate::today();
        if ($list && (! $list->enabled || $list->channel !== $channel || ($list->starts_bs && $date < $list->starts_bs) || ($list->ends_bs && $date > $list->ends_bs))) {
            $this->a->fail('Price list unavailable for this channel/date. Review list or party setup.', 'price_list_id');
        }
        $rate = $contact ? $this->a->rows('party_prices')->where('contact_id', $contact)->where('item_id', $item->id)->where('channel', $channel)->where('enabled', true)->first() : null;
        if ($rate) {
            $this->checkRateUnit($rate, $item);

            return $this->pricingResult($qty, (int) $rate->price_paisa, 'fixed', true, $list);
        }
        if (! $list) {
            return $this->pricingResult($qty, (int) ($channel === 'sale' ? $item->sale_price_paisa : ($item->last_purchase_price_paisa ?? $item->sale_price_paisa)), 'standard', false);
        }
        $rates = $this->a->rows('price_list_rates')->where('price_list_id', $list->id)->where('item_id', $item->id)->orderByDesc('min_qty_milli')->get();
        foreach ($rates as $row) {
            $this->checkRateUnit($row, $item);
        }
        if ($list->pricing_scheme === 'slab' && $rates->isNotEmpty()) {
            $ascending = $rates->reverse()->values();
            if ((int) $ascending[0]->min_qty_milli !== 0) {
                $this->a->fail('Slab pricing needs a zero minimum for each item. Review list.', 'price_list_id');
            }
            $segments = [];
            foreach ($ascending as $i => $row) {
                $from = (int) $row->min_qty_milli;
                $end = min($qty, (int) ($ascending[$i + 1]->min_qty_milli ?? $qty));
                if ($end > $from) {
                    $segments[] = ['qty_milli' => $end - $from, 'price_paisa' => (int) $row->price_paisa, 'from_qty_milli' => $from, 'to_qty_milli' => $end];
                }
            }

            return $this->pricingResult($qty, (int) $ascending[0]->price_paisa, 'slab', true, $list, $segments);
        }
        $tier = $rates->first(fn ($row) => $row->min_qty_milli <= $qty);
        if ($tier) {
            return $this->pricingResult($qty, (int) $tier->price_paisa, 'volume', true, $list);
        }
        $base = (int) ($channel === 'sale' ? $item->sale_price_paisa : ($item->last_purchase_price_paisa ?? $item->sale_price_paisa));
        $price = Money::multiplyDivide($base, 10000 + (int) $list->adjustment_bps, 10000);
        if ($price < 1 || $price > 1000000000) {
            $this->a->fail('Adjusted price must be positive and within supported limit.', 'price_list_id');
        }

        return $this->pricingResult($qty, $price, 'fixed', true, $list);
    }

    public function priceListData(int $id): array
    {
        $list = (array) $this->a->requireRow('price_lists', $id);
        $rates = $this->a->rows('price_list_rates')->where('price_list_id', $id)->orderBy('item_id')->orderBy('min_qty_milli')->get();
        $items = $this->a->rows('items')->whereIn('id', $rates->pluck('item_id'))->get(['id', 'name', 'unit_label', 'pos_unit', 'kind', 'archived_at'])->keyBy('id');
        $list['rules'] = $rates->map(function ($row) use ($items) {
            $item = $items[$row->item_id];
            $row->item_name = $item->name;
            $row->current_unit_snapshot = $item->unit_label;
            $row->current_pos_unit = $item->pos_unit;
            $row->current_item_kind = $item->kind;
            $row->item_archived = $item->archived_at !== null;

            return $row;
        });

        return $list;
    }

    public function savePriceList(int $actor, array $input, ?int $id = null): array
    {
        $this->manage($actor);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'party.price-list.'.($id ?? 'new'), $input, function (Tenant $tenant) use ($actor, $input, $id) {
            $this->manage($actor);
            [$old, $values, $rules] = $this->priceListValues($input, $id);
            if ($old) {
                $this->a->rows('price_lists')->where('id', $id)->update([...$values, 'updated_at' => now(), 'version' => $old->version + 1]);
                $this->a->rows('price_list_rates')->where('price_list_id', $id)->delete();
            } else {
                $id = DB::table('price_lists')->insertGetId(['tenant_id' => $tenant->id, 'created_at' => now(), 'updated_at' => now(), ...$values]);
            }
            foreach ($rules as $rule) {
                DB::table('price_list_rates')->insert([...$rule, 'tenant_id' => $tenant->id, 'price_list_id' => $id]);
            }

            return ['table' => 'price_lists', 'id' => $id];
        });
    }

    public function priceListValues(array $input, ?int $id = null): array
    {
        Validator::make($input, ['name' => 'required|string|max:100', 'channel' => 'required|in:sale,purchase', 'enabled' => 'required|boolean', 'adjustment_mode' => 'required|in:increase,decrease', 'adjustment_percent' => 'required|string|max:20', 'rules' => 'present|array|list|max:1000', 'rules.*.item_id' => 'required|integer|min:1', 'rules.*.min_qty' => 'required|string|max:20', 'rules.*.price' => 'required|string|max:20'])->validate();
        $old = $id ? $this->a->requireRow('price_lists', $id) : null;
        $scheme = $input['pricing_scheme'] ?? $old?->pricing_scheme ?? 'volume';
        if (! in_array($scheme, ['volume', 'slab'], true)) {
            $this->a->fail('Choose volume or slab pricing.', 'pricing_scheme');
        }
        if ($old) {
            abort_unless($old->version == ($input['version'] ?? 0), 409, 'Price list changed. Reload before saving.');
            if ($old->channel !== $input['channel']) {
                $this->a->fail('List channel cannot change. Create another list.');
            }
        }
        $name = trim($input['name']);
        if ($name === '' || $this->a->rows('price_lists')->where('name', $name)->where('channel', $input['channel'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
            $this->a->fail('Choose a unique list name for this channel.', 'name');
        }
        $bps = Money::parse($input['adjustment_percent'], 2, $input['adjustment_mode'] === 'decrease' ? 9999 : 100000);
        $start = isset($input['starts_bs']) ? NepaliDate::normalize($input['starts_bs']) : null;
        $end = isset($input['ends_bs']) ? NepaliDate::normalize($input['ends_bs']) : null;
        if ($start && $end && $end < $start) {
            $this->a->fail('End BS date must follow start date.', 'ends_bs');
        }
        $rules = [];
        $seen = [];
        foreach ($input['rules'] as $i => $rule) {
            $item = $this->a->requireRow('items', $rule['item_id']);
            if (isset($rule['unit_snapshot']) || isset($rule['pos_unit']) || isset($rule['item_kind'])) {
                abort_unless(($rule['unit_snapshot'] ?? null) === $item->unit_label && ($rule['pos_unit'] ?? null) === $item->pos_unit && ($rule['item_kind'] ?? null) === $item->kind, 409, 'Item unit changed. Remove old tiers and add the current item again.');
            }
            if ($item->archived_at) {
                $this->a->fail('Choose active item.', 'rules.'.$i.'.item_id');
            }
            $qty = Money::quantity($rule['min_qty']);
            $price = Money::parse($rule['price']);
            if (! $price || isset($seen[$item->id][$qty]) || count($seen[$item->id] ?? []) >= 10) {
                $this->a->fail('Use positive price, distinct minimums and at most 10 tiers per item.', 'rules.'.$i);
            }
            $seen[$item->id][$qty] = true;
            $rules[] = ['item_id' => $item->id, 'min_qty_milli' => $qty, 'price_paisa' => $price, 'unit_snapshot' => $item->unit_label, 'pos_unit' => $item->pos_unit, 'item_kind' => $item->kind];
        }
        if ($scheme === 'slab') {
            foreach ($seen as $minimums) {
                if (! isset($minimums[0])) {
                    $this->a->fail('Each slab item needs a zero minimum.', 'rules');
                }
            }
        }
        $values = ['name' => $name, 'channel' => $input['channel'], 'pricing_scheme' => $scheme, 'enabled' => $input['enabled'], 'adjustment_bps' => $input['adjustment_mode'] === 'decrease' ? -$bps : $bps, 'starts_bs' => $start, 'ends_bs' => $end];

        return [$old, $values, $rules];
    }

    public function assignPriceLists(int $actor, int $id, array $input): array
    {
        $this->manage($actor);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'party.price-lists.'.$id, $input, function () use ($actor, $id, $input) {
            $this->manage($actor);
            $party = $this->a->requireRow('contacts', $id);
            abort_if($party->is_system || $party->archived_at, 403);
            abort_unless($party->trading_version == $input['version'], 409, 'Trading setup changed. Reload first.');
            foreach (['sales_price_list_id' => 'sale', 'purchase_price_list_id' => 'purchase'] as $field => $channel) {
                if ($input[$field] !== null) {
                    $list = $this->a->requireRow('price_lists', $input[$field]);
                    if (! $list->enabled || $list->channel !== $channel || ($channel === 'sale' ? ! $party->is_customer : ! $party->is_supplier)) {
                        $this->a->fail('Choose enabled list and matching party role.', $field);
                    }
                }
            }
            $this->a->rows('contacts')->where('id', $id)->update(['sales_price_list_id' => $input['sales_price_list_id'], 'purchase_price_list_id' => $input['purchase_price_list_id'], 'trading_version' => $party->trading_version + 1, 'updated_at' => now()]);

            return ['table' => 'contacts', 'id' => $id];
        });
    }

    public function saveRate(int $actor, int $contact, array $input): array
    {
        $this->manage($actor);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'party.rate.'.$contact, $input, function (Tenant $tenant) use ($actor, $contact, $input) {
            $this->manage($actor);
            $party = $this->a->requireRow('contacts', $contact);
            $item = $this->a->requireRow('items', $input['item_id']);
            if ($party->is_system || $party->archived_at || $item->archived_at || ($input['channel'] === 'sale' ? ! $party->is_customer : ! $party->is_supplier)) {
                $this->a->fail('Choose active party/item and matching customer or supplier role.');
            }
            $price = Money::parse($input['price']);
            if (! $price) {
                $this->a->fail('Agreed price must be positive.');
            }
            $old = $this->a->rows('party_prices')->where('contact_id', $contact)->where('item_id', $item->id)->where('channel', $input['channel'])->first();
            $values = ['price_paisa' => $price, 'enabled' => $input['enabled'], 'unit_snapshot' => $item->unit_label, 'pos_unit' => $item->pos_unit, 'item_kind' => $item->kind, 'updated_at' => now()];
            if ($old) {
                abort_unless($old->version == ($input['version'] ?? 0), 409, 'Price changed. Reload before editing.');
                $id = $old->id;
                $this->a->rows('party_prices')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
            } else {
                $id = DB::table('party_prices')->insertGetId(['tenant_id' => $tenant->id, 'contact_id' => $contact, 'item_id' => $item->id, 'channel' => $input['channel'], 'created_at' => now(), ...$values]);
            }

            return ['table' => 'party_prices', 'id' => $id];
        });
    }

    public function followup(int $actor, array $input, ?int $id = null): array
    {
        $this->manage($actor);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'party.followup.'.($id ?? 'new'), $input, function (Tenant $tenant) use ($actor, $input, $id) {
            $this->manage($actor);
            $old = $id ? $this->a->requireRow('party_followups', $id) : null;
            if ($old) {
                abort_unless($old->version == $input['version'], 409, 'Follow-up changed. Reload before saving.');
            }
            $contact = $old?->contact_id ?? $input['contact_id'];
            $party = $this->a->requireRow('contacts', $contact);
            abort_if($party->is_system, 403);
            $assigned = $input['assigned_to'] ?? $old?->assigned_to ?? $actor;
            if (! $this->a->rows('tenant_user')->where('user_id', $assigned)->where('active', true)->whereIn('role', ['owner', 'manager', 'accountant'])->whereIn('user_id', DB::table('users')->whereNull('disabled_at')->select('id'))->exists()) {
                $this->a->fail('Assign active owner, manager or accountant.', 'assigned_to');
            }
            $document = $old?->document_id ?? ($input['document_id'] ?? null);
            if ($document) {
                $doc = $this->a->requireRow('documents', $document);
                if ($doc->contact_id != $contact || ! in_array($doc->type, ['sale', 'purchase', 'expense']) || (! $old && $doc->status !== 'posted')) {
                    $this->a->fail('Link a posted bill for this party.', 'document_id');
                }
            }
            $date = isset($input['due_date_bs']) ? NepaliDate::normalize($input['due_date_bs']) : (int) $old->due_date_bs;
            $status = $input['status'] ?? $old?->status ?? 'open';
            if (! $old && $party->archived_at) {
                $this->a->fail('Restore archived party before adding follow-up.');
            }
            $values = ['title' => $input['title'] ?? $old?->title, 'due_date_bs' => $date, 'channel' => $input['channel'] ?? $old?->channel, 'assigned_to' => $assigned, 'status' => $status, 'updated_at' => now()];
            if ($old) {
                $this->a->rows('party_followups')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
            } else {
                $id = DB::table('party_followups')->insertGetId(['tenant_id' => $tenant->id, 'contact_id' => $contact, 'document_id' => $document, 'created_by' => $actor, 'created_at' => now(), ...$values]);
            }
            DB::table('party_followup_events')->insert(['tenant_id' => $tenant->id, 'followup_id' => $id, 'action' => $old ? ($status !== $old->status ? $status : 'updated') : 'created', 'notes' => $input['notes'] ?? null, 'business_date_bs' => NepaliDate::today(), 'created_by' => $actor, 'created_at' => now()]);

            return ['table' => 'party_followups', 'id' => $id];
        });
    }
}
