<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class BasketService
{
    public function __construct(private AccountingService $a) {}

    public function data(object $offer): array
    {
        $date = NepaliDate::fromAd(now()->timezone('Asia/Kathmandu')->format('Y-m-d'));

        return [...(array) $offer, 'rules' => json_decode($offer->rules ?? '[]', true) ?? [], 'weekdays' => json_decode($offer->weekdays ?? '[]', true) ?? [], 'available_now' => (bool) $offer->enabled && (! $offer->starts_bs || $date >= $offer->starts_bs) && (! $offer->ends_bs || $date <= $offer->ends_bs) && $this->schedule($offer, $date)['available']];
    }

    private function minute(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', Money::digits($time), $parts)) {
            $this->a->fail('Use Nepal clock time HH:MM.', 'starts_time');
        }

        return (int) $parts[1] * 60 + (int) $parts[2];
    }

    private function schedule(object $offer, int $date): array
    {
        $start = isset($offer->starts_minute) ? (int) $offer->starts_minute : null;
        $end = isset($offer->ends_minute) ? (int) $offer->ends_minute : null;
        $days = json_decode($offer->weekdays ?? '[]', true) ?? [];
        $active = true;
        $scheduled = $start !== null || count($days);
        if ($scheduled) {
            $clock = now()->timezone('Asia/Kathmandu');
            $today = NepaliDate::fromAd($clock->format('Y-m-d'));
            $minute = (int) $clock->format('H') * 60 + (int) $clock->format('i');
            $weekday = (int) $clock->format('w');
            if ($start !== null) {
                $active = $start < $end ? $minute >= $start && $minute < $end : $minute >= $start || $minute < $end;
                if ($start > $end && $minute < $end) {
                    $weekday = ($weekday + 6) % 7;
                }
            }
            $active = $active && $date === $today && (! count($days) || in_array($weekday, $days, true));
        }

        return ['starts_minute' => $start, 'ends_minute' => $end, 'weekdays' => $days, 'scheduled_for_bs' => $scheduled ? $date : null, 'available' => $active];
    }

    private function rules(array $rows, string $kind): array
    {
        if ($kind === 'items') {
            if (count($rows) !== 1 || ($rows[0]['role'] ?? '') !== 'target' || isset($rows[0]['item_id']) || isset($rows[0]['qty'])) {
                $this->a->fail('Choose one item/category target selection.', 'rules');
            }
            $row = $rows[0];
            $items = array_values(array_unique(array_map('intval', $row['item_ids'] ?? [])));
            $categories = array_values(array_unique(array_map('intval', $row['category_ids'] ?? [])));
            if ((! count($items) && ! count($categories)) || count($items) > 100 || count($categories) > 20) {
                $this->a->fail('Choose up to100 items and20 categories, with at least one selection.', 'rules');
            }
            sort($items);
            sort($categories);
            $names = [];
            $categoryNames = [];
            foreach ($items as $id) {
                $item = $this->a->requireRow('items', $id);
                if ($item->archived_at) {
                    $this->a->fail('Selected offer item is archived. Review selection.', 'rules');
                }
                $names[] = $item->name;
            }
            foreach ($categories as $id) {
                $category = $this->a->requireRow('item_categories', $id);
                if ($category->archived_at) {
                    $this->a->fail('Selected offer category is archived. Review selection.', 'rules');
                }
                $categoryNames[] = $category->name;
            }

            return [['role' => 'target', 'item_ids' => $items, 'item_names' => $names, 'category_ids' => $categories, 'category_names' => $categoryNames]];
        }
        if (($kind === 'basket' && count($rows)) || ($kind === 'bundle' && (count($rows) < 2 || count($rows) > 20)) || ($kind === 'buy_get' && count($rows) !== 2)) {
            $this->a->fail('Choose 2–20 bundle components or one buy and one get rule.', 'rules');
        }
        $rules = [];
        $seen = [];
        foreach ($rows as $row) {
            if (array_key_exists('item_ids', $row) || array_key_exists('category_ids', $row)) {
                $role = $row['role'] ?? '';
                $unit = $row['pos_unit'] ?? '';
                if (isset($row['item_id']) || ! isset(PosService::UNITS[$unit]) || ($kind === 'bundle' ? $role !== 'component' : ! in_array($role, ['buy', 'get'], true) || isset($seen[$role]))) {
                    $this->a->fail('Choose a choice group role and billed base unit.', 'rules');
                }
                $selection = $this->rules([['role' => 'target', 'item_ids' => $row['item_ids'] ?? [], 'category_ids' => $row['category_ids'] ?? []]], 'items')[0];
                $snapshots = array_column($row['item_snapshots'] ?? [], null, 'item_id');
                if (isset($row['item_snapshots']) && (count($snapshots) !== count($selection['item_ids']) || array_diff(array_keys($snapshots), $selection['item_ids']))) {
                    $this->a->fail('Review every explicitly selected item snapshot.', 'rules');
                }
                $members = [];
                foreach ($selection['item_ids'] as $id) {
                    $item = $this->a->requireRow('items', $id);
                    if ($item->pos_unit !== $unit) {
                        $this->a->fail('Choice group items must share its billed base unit.', 'rules');
                    }
                    $member = ['item_id' => $id, 'unit_snapshot' => $item->unit_label, 'pos_unit' => $item->pos_unit, 'item_kind' => $item->kind];
                    if (isset($snapshots[$id]) && array_intersect_key($snapshots[$id], $member) != $member) {
                        $this->a->fail('Offer item units changed. Remove and reselect that item.', 'rules');
                    }
                    $members[] = $member;
                }
                $qty = isset($row['qty']) ? Money::quantity($row['qty']) : (int) ($row['qty_milli'] ?? 0);
                if ($qty <= 0 || $qty > 1000000000) {
                    $this->a->fail('Offer quantities must be positive base units within limit.', 'rules');
                }
                $seen[$role] = true;
                $rules[] = [...$selection, 'role' => $role, 'qty_milli' => $qty, 'unit_snapshot' => $unit, 'pos_unit' => $unit, 'item_snapshots' => $members];

                continue;
            }
            if (! isset($row['item_id'], $row['role']) || (! isset($row['qty']) && ! isset($row['qty_milli'])) || ! empty($row['item_ids']) || ! empty($row['category_ids'])) {
                $this->a->fail('Choose item and positive required quantity.', 'rules');
            }
            $item = $this->a->requireRow('items', $row['item_id']);
            $role = $row['role'];
            $key = $kind === 'bundle' ? (string) $item->id : $role;
            if ($item->archived_at || isset($seen[$key]) || ($kind === 'bundle' ? $role !== 'component' : ! in_array($role, ['buy', 'get'], true))) {
                $this->a->fail('Choose distinct active components or one buy and one get item.', 'rules');
            }
            foreach (['unit_snapshot' => 'unit_label', 'pos_unit' => 'pos_unit', 'item_kind' => 'kind'] as $snapshot => $field) {
                if (isset($row[$snapshot]) && $row[$snapshot] !== $item->$field) {
                    $this->a->fail('Offer item units changed. Remove and reselect that item.', 'rules');
                }
            }
            $qty = isset($row['qty']) ? Money::quantity($row['qty']) : (int) $row['qty_milli'];
            if ($qty <= 0 || $qty > 1000000000) {
                $this->a->fail('Offer quantities must be positive base units within limit.', 'rules');
            }
            $seen[$key] = true;
            $rules[] = ['item_id' => (int) $item->id, 'item_name' => $item->name, 'role' => $role, 'qty_milli' => $qty, 'unit_snapshot' => $item->unit_label, 'pos_unit' => $item->pos_unit, 'item_kind' => $item->kind];
        }

        return $rules;
    }

    // Capacity edges avoid expanding individual units or offer repetitions.
    // Reverse edges reroute broad groups around restricted selections.
    private function match(array $rules, array $lines, array $choices, int $applications, bool $rewardPrices = false): ?array
    {
        $source = 0;
        $lineStart = count($rules) + 1;
        $sink = $lineStart + count($lines);
        $graph = array_fill(0, $sink + 1, []);
        $edges = [];
        $add = function (int $from, int $to, int $capacity, int $cost = 0) use (&$graph, &$edges): int {
            $index = count($edges);
            $edges[] = ['to' => $to, 'capacity' => $capacity, 'cost' => $cost];
            $edges[] = ['to' => $from, 'capacity' => 0, 'cost' => -$cost];
            $graph[$from][] = $index;
            $graph[$to][] = $index + 1;

            return $index;
        };
        $links = [];
        $needed = 0;
        foreach ($rules as $r => $rule) {
            $quota = $rule['qty_milli'] * $applications;
            $needed += $quota;
            $add($source, $r + 1, $quota);
            foreach ($choices[$r] as $i) {
                $edge = $add($r + 1, $lineStart + $i, $lines[$i]['qty_milli'], $rewardPrices && $rule['role'] === 'get' ? $lines[$i]['unit_price_paisa'] : 0);
                $links[] = ['edge' => $edge, 'rule_position' => $r + 1, 'line_position' => $i + 1, 'role' => $rule['role']];
            }
        }
        foreach ($lines as $i => $line) {
            $add($lineStart + $i, $sink, $line['qty_milli']);
        }
        while ($needed > 0) {
            $distance = array_fill(0, $sink + 1, PHP_INT_MAX);
            $previous = array_fill(0, $sink + 1, -1);
            $queued = array_fill(0, $sink + 1, false);
            $distance[$source] = 0;
            $queue = [$source];
            $queued[$source] = true;
            for ($head = 0; $head < count($queue); $head++) {
                $node = $queue[$head];
                $queued[$node] = false;
                foreach ($graph[$node] as $index) {
                    $edge = $edges[$index];
                    if ($edge['capacity'] > 0 && $distance[$edge['to']] > $distance[$node] + $edge['cost']) {
                        $distance[$edge['to']] = $distance[$node] + $edge['cost'];
                        $previous[$edge['to']] = $index;
                        if (! $queued[$edge['to']]) {
                            $queue[] = $edge['to'];
                            $queued[$edge['to']] = true;
                        }
                    }
                }
            }
            if ($previous[$sink] === -1) {
                return null;
            }
            $amount = $needed;
            for ($node = $sink; $node !== $source; $node = $edges[$previous[$node] ^ 1]['to']) {
                $amount = min($amount, $edges[$previous[$node]]['capacity']);
            }
            for ($node = $sink; $node !== $source; $node = $edges[$previous[$node] ^ 1]['to']) {
                $edges[$previous[$node]]['capacity'] -= $amount;
                $edges[$previous[$node] ^ 1]['capacity'] += $amount;
            }
            $needed -= $amount;
        }
        $assignments = [];
        foreach ($links as $link) {
            $qty = $edges[$link['edge'] ^ 1]['capacity'];
            if ($qty) {
                unset($link['edge']);
                $assignments[] = [...$link, 'qty_milli' => $qty];
            }
        }

        return $assignments;
    }

    public function save(int $actor, array $input, ?int $id = null): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant']);

        return $this->a->mutate($actor, $input['mutation_uuid'], 'basket.offer.'.($id ?? 'new'), $input, function (Tenant $tenant) use ($actor, $input, $id) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant']);
            $old = $id ? $this->a->requireRow('basket_offers', $id) : null;
            abort_if($old && (int) $old->version !== (int) ($input['version'] ?? 0), 409, 'Offer changed. Reload before saving.');
            $name = trim($input['name']);
            if (! $name || $this->a->rows('basket_offers')->where('name', $name)->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                $this->a->fail('Enter a distinct offer name.', 'name');
            }
            if (! $id && $this->a->rows('basket_offers')->count() >= 100) {
                $this->a->fail('At most 100 basket offers per business.');
            }
            $value = Money::parse($input['discount_value']);
            $kind = $input['offer_kind'] ?? $old->offer_kind ?? 'basket';
            if (! in_array($kind, ['basket', 'bundle', 'buy_get', 'items'], true)) {
                $this->a->fail('Choose basket, bundle or buy/get offer.');
            }
            $max = array_key_exists('maximum_applications', $input) ? $input['maximum_applications'] : ($kind === ($old->offer_kind ?? 'basket') ? ($old->maximum_applications ?? null) : null);
            $rules = $this->rules($input['rules'] ?? ($kind === ($old->offer_kind ?? 'basket') ? json_decode($old->rules ?? '[]', true) ?? [] : []), $kind);
            $minimum = Money::parse($input['minimum_spend']);
            $cap = isset($input['maximum_discount']) && $input['maximum_discount'] !== '' ? Money::parse($input['maximum_discount']) : null;
            if (! $value || ($input['discount_mode'] === 'percent' && $value > (in_array($kind, ['buy_get', 'items'], true) ? 10000 : 9999)) || ($kind === 'bundle' && $input['discount_mode'] !== 'fixed') || ($kind === 'buy_get' && $input['discount_mode'] !== 'percent') || ($cap !== null && ($cap <= 0 || $input['discount_mode'] !== 'percent')) || ($max !== null && (in_array($kind, ['basket', 'items'], true) || $max < 1 || $max > 1000000000))) {
                $this->a->fail('Use a positive amount or percentage below 100; caps apply to percentages.');
            }
            $start = ! empty($input['starts_bs']) ? NepaliDate::normalize($input['starts_bs']) : null;
            $end = ! empty($input['ends_bs']) ? NepaliDate::normalize($input['ends_bs']) : null;
            if ($start && $end && $end < $start) {
                $this->a->fail('Offer end precedes start.', 'ends_bs');
            }
            $startMinute = array_key_exists('starts_time', $input) ? $this->minute($input['starts_time']) : ($old->starts_minute ?? null);
            $endMinute = array_key_exists('ends_time', $input) ? $this->minute($input['ends_time']) : ($old->ends_minute ?? null);
            if (($startMinute === null) !== ($endMinute === null) || ($startMinute !== null && $startMinute === $endMinute)) {
                $this->a->fail('Enter both different start and end times.', 'starts_time');
            }
            $days = array_values(array_unique(array_map('intval', $input['weekdays'] ?? json_decode($old->weekdays ?? '[]', true) ?? [])));
            sort($days);
            if (count($days) > 7 || array_filter($days, fn ($day) => $day < 0 || $day > 6)) {
                $this->a->fail('Choose weekdays Sunday0 through Saturday6.', 'weekdays');
            }
            $values = ['name' => $name, 'enabled' => $input['enabled'], 'offer_kind' => $kind, 'maximum_applications' => $max, 'rules' => json_encode($rules, JSON_THROW_ON_ERROR), 'discount_mode' => $input['discount_mode'], 'discount_value' => $value, 'minimum_spend_paisa' => $minimum, 'maximum_discount_paisa' => $cap, 'starts_bs' => $start, 'ends_bs' => $end, 'cashier_allowed' => $input['cashier_allowed'], 'version' => ($old->version ?? 0) + 1, 'updated_at' => now()];
            $values += ['starts_minute' => $startMinute, 'ends_minute' => $endMinute, 'weekdays' => json_encode($days, JSON_THROW_ON_ERROR)];
            if ($id) {
                $this->a->rows('basket_offers')->where('id', $id)->update($values);
            } else {
                $id = DB::table('basket_offers')->insertGetId(['tenant_id' => $tenant->id, 'created_at' => now(), ...$values]);
            }

            return ['table' => 'basket_offers', 'id' => $id];
        });
    }

    public function checkout(int $actor, array $input, array $lines, array $context, bool $posting = false): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant', 'cashier']);
        $date = NepaliDate::normalize($input['business_date_bs']);
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());
        [$party, $initial] = app(DocumentService::class)->prepare($actor, $tenant, ['type' => 'sale', 'business_date_bs' => $date, 'contact_id' => $input['contact_id'] ?? null, 'lines' => $lines, 'promotional_confirmed' => $input['promotional_confirmed'] ?? false]);
        $normal = ['invoice_discount' => '0', 'lines' => array_map(fn ($line) => ['item_id' => (int) $line['item_id'], 'qty' => Money::format($line['qty_milli'], 3), 'unit_price' => Money::format($line['unit_price_paisa']), 'discount' => Money::format($line['line_discount_paisa']), 'description' => $line['description'], 'unit_snapshot' => $line['unit_snapshot'], 'tax_category' => $line['tax_category'], 'tax_bps' => $line['tax_bps']], $initial['lines'])];
        $offer = null;
        if (! empty($input['basket_offer_id'])) {
            $offer = $this->quote($actor, (int) $input['basket_offer_id'], $date, $normal['lines']);
            $normal['invoice_discount'] = $offer['allocation_mode'] === 'invoice' ? Money::format($offer['discount_paisa']) : '0';
            foreach ($offer['allocations'] as $allocation) {
                $i = $allocation['position'] - 1;
                $normal['lines'][$i]['discount'] = Money::format($offer['line_bases'][$i]['line_discount_paisa'] + $allocation['discount_paisa']);
            }
            $offer['checkout_source'] = $context;
        }
        $fingerprint = hash('sha256', json_encode(['source' => $context, 'business_date_bs' => $date, 'contact_id' => (int) $party->id, 'input' => $normal, 'offer' => $offer], JSON_THROW_ON_ERROR));
        if ($posting) {
            if ($offer && empty($input['expected_fingerprint'])) {
                $this->a->fail('Review selected offer total before payment.', 'expected_fingerprint');
            }
            if (isset($input['expected_fingerprint'])) {
                abort_unless(hash_equals($fingerprint, $input['expected_fingerprint']), 409, 'Checkout changed. Review current total before payment.');
            }
        }
        $preview = app(DocumentService::class)->calculate($normal);
        foreach ($preview['lines'] as $i => &$line) {
            $line['before_offer_paisa'] = $initial['lines'][$i]['net_base_paisa'];
        }
        unset($line);

        return ['input' => $normal, 'preview' => [...$preview, 'fingerprint' => $fingerprint, 'basket_offer' => $offer]];
    }

    public function quote(int $actor, int $id, int $date, array $lines): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'accountant', 'cashier']);
        $offer = $this->a->requireRow('basket_offers', $id);
        abort_if($this->a->role($actor) === 'cashier' && ! $offer->cashier_allowed, 403, 'Offer requires manager or accountant.');
        if (! $offer->enabled || ($offer->starts_bs && $date < $offer->starts_bs) || ($offer->ends_bs && $date > $offer->ends_bs)) {
            $this->a->fail('Offer disabled or outside BS validity. Remove it or review setup.', 'basket_offer_id');
        }
        $schedule = $this->schedule($offer, $date);
        if (! $schedule['available']) {
            $this->a->fail('Offer outside Nepal-time schedule or current BS date. Remove or review offer.', 'basket_offer_id');
        }
        $totals = app(DocumentService::class)->calculate(['lines' => $lines]);
        $base = $totals['subtotal_paisa'] - $totals['line_discount_paisa'];
        if ($base < (int) $offer->minimum_spend_paisa) {
            $this->a->fail('Basket needs at least NPR '.Money::format((int) $offer->minimum_spend_paisa).' before tax.', 'basket_offer_id');
        }
        $kind = $offer->offer_kind;
        $rules = $this->rules(json_decode($offer->rules ?? '[]', true) ?? [], $kind);
        $eligible = array_fill(0, count($lines), 0);
        $matched = array_fill(0, count($lines), 0);
        $applications = 1;
        $matchedItems = [];
        $assignments = [];
        if ($kind === 'items') {
            $selection = $rules[0];
            $catalog = $this->a->rows('items')->whereIn('id', array_unique(array_column($totals['lines'], 'item_id')))->get()->keyBy('id');
            foreach ($totals['lines'] as $i => $line) {
                $item = $catalog[$line['item_id']] ?? null;
                if ($item && ! $item->archived_at && (in_array((int) $item->id, $selection['item_ids'], true) || in_array((int) $item->category_id, $selection['category_ids'], true))) {
                    $matched[$i] = $line['qty_milli'];
                    $eligible[$i] = $line['net_base_paisa'];
                    $matchedItems[$item->id] = ['item_id' => (int) $item->id, 'item_name' => $item->name, 'category_id' => $item->category_id, 'unit_snapshot' => $item->unit_label, 'pos_unit' => $item->pos_unit, 'item_kind' => $item->kind];
                }
            }
            if (! array_sum($eligible)) {
                $this->a->fail('Add an item from the selected offer items/categories.', 'basket_offer_id');
            }
        } elseif ($kind !== 'basket') {
            $catalog = $this->a->rows('items')->whereIn('id', array_unique(array_column($totals['lines'], 'item_id')))->get()->keyBy('id');
            $choices = [];
            $upper = $offer->maximum_applications === null ? 1000000000 : (int) $offer->maximum_applications;
            foreach ($rules as $r => $rule) {
                $choices[$r] = [];
                $available = 0;
                foreach ($totals['lines'] as $i => $line) {
                    $item = $catalog[$line['item_id']] ?? null;
                    $matches = isset($rule['item_id']) ? $line['item_id'] === $rule['item_id'] : $item && $item->pos_unit === $rule['pos_unit'] && (in_array((int) $item->id, $rule['item_ids'], true) || in_array((int) $item->category_id, $rule['category_ids'], true));
                    if ($item && ! $item->archived_at && $matches) {
                        $choices[$r][] = $i;
                        $available += $line['qty_milli'];
                    }
                }
                $upper = min($upper, intdiv($available, $rule['qty_milli']));
            }
            $applications = 0;
            while ($applications < $upper) {
                $middle = intdiv($applications + $upper + 1, 2);
                if ($this->match($rules, $totals['lines'], $choices, $middle) !== null) {
                    $applications = $middle;
                } else {
                    $upper = $middle - 1;
                }
            }
            if (! $applications) {
                $this->a->fail('Add all required buy and reward or bundle quantities to the cart.', 'basket_offer_id');
            }
            $assignments = $this->match($rules, $totals['lines'], $choices, $applications, $kind === 'buy_get');
            foreach ($assignments as $assignment) {
                $i = $assignment['line_position'] - 1;
                $item = $catalog[$totals['lines'][$i]['item_id']];
                $matchedItems[$item->id] = ['item_id' => (int) $item->id, 'item_name' => $item->name, 'category_id' => $item->category_id, 'unit_snapshot' => $item->unit_label, 'pos_unit' => $item->pos_unit, 'item_kind' => $item->kind];
                if ($kind === 'bundle' || $assignment['role'] === 'get') {
                    $matched[$i] += $assignment['qty_milli'];
                }
            }
            foreach ($totals['lines'] as $i => $line) {
                if ($matched[$i]) {
                    $eligible[$i] = min($line['net_base_paisa'], Money::multiplyDivide($line['net_base_paisa'], $matched[$i], $line['qty_milli']));
                }
            }
        }
        if ($kind === 'bundle') {
            $discount = array_sum($eligible) - Money::multiplyDivide((int) $offer->discount_value, $applications, 1);
            if ($discount <= 0) {
                $this->a->fail('Bundle price does not reduce current matched prices. Remove or review offer.', 'basket_offer_id');
            }
        } else {
            $discountBase = in_array($kind, ['buy_get', 'items'], true) ? array_sum($eligible) : $base;
            $discount = $offer->discount_mode === 'percent' ? Money::multiplyDivide($discountBase, (int) $offer->discount_value, 10000) : (int) $offer->discount_value;
        }
        if ($offer->maximum_discount_paisa !== null) {
            $discount = min($discount, (int) $offer->maximum_discount_paisa);
        }
        if ($kind === 'items' && $discount > array_sum($eligible)) {
            $this->a->fail('Selected-item saving exceeds matching item value. Review offer.', 'basket_offer_id');
        }
        if ($discount >= $base) {
            $this->a->fail('Basket offer must leave a positive bill amount.', 'basket_offer_id');
        }

        $allocations = [];
        if ($kind !== 'basket') {
            foreach (Money::allocate($discount, $eligible) as $i => $share) {
                if ($matched[$i]) {
                    $allocations[] = ['position' => $i + 1, 'matched_qty_milli' => $matched[$i], 'matched_base_paisa' => $eligible[$i], 'discount_paisa' => $share];
                }
            }
        }
        $lineBases = array_map(fn ($line) => ['position' => $line['position'], 'before_offer_paisa' => $line['net_base_paisa'], 'line_discount_paisa' => $line['line_discount_paisa']], $totals['lines']);

        return ['assignments' => $assignments, 'schedule' => $schedule, 'matched_items' => array_values($matchedItems), 'id' => (int) $offer->id, 'name' => $offer->name, 'version' => (int) $offer->version, 'offer_kind' => $kind, 'rules' => $rules, 'maximum_applications' => $offer->maximum_applications === null ? null : (int) $offer->maximum_applications, 'applications' => $applications, 'allocations' => $allocations, 'line_bases' => $lineBases, 'allocation_mode' => $kind === 'basket' ? 'invoice' : 'line', 'discount_mode' => $offer->discount_mode, 'discount_value' => (string) $offer->discount_value, 'minimum_spend_paisa' => (int) $offer->minimum_spend_paisa, 'maximum_discount_paisa' => $offer->maximum_discount_paisa === null ? null : (int) $offer->maximum_discount_paisa, 'starts_bs' => $offer->starts_bs, 'ends_bs' => $offer->ends_bs, 'cashier_allowed' => (bool) $offer->cashier_allowed, 'base_paisa' => $base, 'discount_paisa' => $discount];
    }
}
