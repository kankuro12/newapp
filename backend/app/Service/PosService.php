<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PosService
{
    public const PROFILES = ['general', 'meat', 'restaurant', 'barber', 'salon', 'milk', 'glass', 'wood', 'gym'];

    public const METHODS = ['quantity', 'amount', 'pack', 'length', 'area', 'volume'];

    // Rational factors in metres, square metres, cubic metres, kilograms, litres or count.
    public const UNITS = [
        'unit' => ['count', '1', '1'], 'pair' => ['count', '2', '1'], 'dozen' => ['count', '12', '1'],
        'kg' => ['mass', '1', '1'], 'g' => ['mass', '1', '1000'], 'mg' => ['mass', '1', '1000000'], 'tonne' => ['mass', '1000', '1'],
        'lb' => ['mass', '45359237', '100000000'], 'oz' => ['mass', '45359237', '1600000000'],
        'l' => ['liquid', '1', '1'], 'ml' => ['liquid', '1', '1000'], 'cl' => ['liquid', '1', '100'],
        'us_gal' => ['liquid', '473176473', '125000000'], 'imp_gal' => ['liquid', '454609', '100000'],
        'mm' => ['length', '1', '1000'], 'cm' => ['length', '1', '100'], 'm' => ['length', '1', '1'], 'in' => ['length', '127', '5000'], 'ft' => ['length', '381', '1250'], 'yd' => ['length', '1143', '1250'],
        'sq_mm' => ['area', '1', '1000000'], 'sq_cm' => ['area', '1', '10000'], 'sq_m' => ['area', '1', '1'],
        'sq_in' => ['area', '16129', '25000000'], 'sq_ft' => ['area', '145161', '1562500'], 'sq_yd' => ['area', '1306449', '1562500'],
        'cu_mm' => ['volume', '1', '1000000000'], 'cu_cm' => ['volume', '1', '1000000'], 'cu_m' => ['volume', '1', '1'],
        'cu_in' => ['volume', '2048383', '125000000000'], 'cu_ft' => ['volume', '55306341', '1953125000'], 'cu_yd' => ['volume', '1493271207', '1953125000'], 'board_ft' => ['volume', '55306341', '23437500000'],
    ];

    public function __construct(private AccountingService $a, private PartyService $parties) {}

    private function round(string $n, string $d): int
    {
        return Money::checked(bcdiv(bcadd(bcmul($n, '2'), $d), bcmul($d, '2'), 0));
    }

    public function measure(object $item, array $m): int
    {
        $mode = $m['mode'];
        $allowed = json_decode($item->pos_methods ?? 'null', true) ?? self::METHODS;
        if (! in_array($mode, $allowed, true)) {
            $this->a->fail('Entry method disabled for this item.');
        }
        $base = self::UNITS[$item->pos_unit] ?? self::UNITS['unit'];
        if ($mode === 'amount') {
            $price = (int) $item->sale_price_paisa;
            if ($price <= 0) {
                $this->a->fail('Amount entry needs positive price.');
            } $qty = $this->round(bcmul((string) Money::parse($m['value']), '1000'), (string) $price);
        } elseif ($mode === 'pack') {
            $units = json_decode($item->pos_custom_units ?? '[]', true);
            $unit = $units[$m['custom_index'] ?? -1] ?? null;
            if (! $unit) {
                $this->a->fail('Choose configured pack/custom unit.');
            } $qty = Money::multiplyDivide(Money::quantity($m['value']), Money::quantity($unit['qty']), 1000);
        } elseif ($mode === 'quantity') {
            $qty = Money::quantity($m['value']);
            if (! empty($m['unit']) && $m['unit'] !== $item->pos_unit) {
                $unit = self::UNITS[$m['unit']] ?? null;
                if (! $unit || $unit[0] !== $base[0]) {
                    $this->a->fail('Quantity unit incompatible with item base unit.');
                } $qty = $this->round(bcmul(bcmul((string) $qty, $unit[1]), $base[2]), bcmul($unit[2], $base[1]));
            }
        } else {
            $dimension = ['length' => 1, 'area' => 2, 'volume' => 3][$mode] ?? 0;
            if (! $dimension || $base[0] !== $mode) {
                $this->a->fail('Measurement does not match item base unit.');
            }
            $n = bcmul((string) Money::quantity($m['pieces'] ?? '1'), $base[2]);
            $d = bcmul('1000', $base[1]);
            foreach (array_slice(['length', 'width', 'thickness'], 0, $dimension) as $key) {
                $unit = self::UNITS[$m[$key.'_unit'] ?? ''] ?? null;
                if (! $unit || $unit[0] !== 'length') {
                    $this->a->fail('Choose dimension units.');
                } $value = Money::quantity($m[$key] ?? '0');
                if ($value <= 0) {
                    $this->a->fail('Dimensions must be positive.');
                } $n = bcmul(bcmul($n, (string) $value), $unit[1]);
                $d = bcmul(bcmul($d, '1000'), $unit[2]);
            }
            $qty = $this->round(bcmul($n, '1000'), $d);
        }
        if ($qty <= 0 || $qty > 1000000000) {
            $this->a->fail('Quantity rounds to zero or exceeds limit.');
        }

        return $qty;
    }

    public function normalize(array $input): array
    {
        $lines = [];
        $snapshots = [];
        $items = [];
        $amountPrices = [];
        $contact = (int) ($input['contact_id'] ?? 0);
        $listId = isset($input['price_list_id']) ? (int) $input['price_list_id'] : null;
        $date = isset($input['business_date_bs']) ? NepaliDate::normalize($input['business_date_bs']) : NepaliDate::today();
        foreach ($input['lines'] as $row) {
            $barcodeRule = null;
            if (isset($row['measurement']['barcode'])) {
                $scan = app(BarcodeService::class)->resolve($row['measurement']['barcode'], $input['contact_id'] ?? null, $listId, $date);
                if ((int) $scan['item_id'] !== (int) $row['item_id']) {
                    $this->a->fail('Scanned code does not match selected item.', 'code');
                }
                abort_unless(hash_equals($scan['measurement']['barcode_fingerprint'], $row['measurement']['barcode_fingerprint'] ?? ''), 409, 'Barcode settings changed. Remove line and scan again.');
                $row['measurement'] = $scan['measurement'];
                $barcodeRule = $scan['rule'];
            }
            $item = $this->a->requireRow('items', $row['item_id']);
            $items[$item->id] = clone $item;
            $item->sale_price_paisa = $this->parties->rate($contact, $item, 'sale', 0, $listId, $date) ?? $item->sale_price_paisa;
            if ($item->archived_at) {
                $this->a->fail('Item archived.');
            } $qty = $this->measure($item, $row['measurement']);
            $id = (int) $item->id;
            if ($row['measurement']['mode'] === 'amount') {
                $amountPrices[$id][] = (int) $item->sale_price_paisa;
            }
            $old = isset($lines[$id]) ? Money::quantity($lines[$id]['qty']) : 0;
            if ($old + $qty > 1000000000) {
                $this->a->fail('Combined quantity exceeds limit.');
            }
            $lines[$id] = ['item_id' => $id, 'qty' => Money::format($old + $qty, 3), 'unit_price' => Money::format((int) $item->sale_price_paisa), 'tax_bps' => (int) $item->default_tax_bps, 'tax_category' => $item->default_tax_category, 'description' => $item->name, 'unit_snapshot' => $item->unit_label];
            $snapshot = [...$row['measurement'], 'qty_milli' => (string) $qty, 'base_unit' => $item->pos_unit, 'note' => $row['note'] ?? ''];
            if ($barcodeRule) {
                $snapshot['barcode_rule'] = $barcodeRule;
            }
            if ($row['measurement']['mode'] === 'pack') {
                $custom = json_decode($item->pos_custom_units, true)[$row['measurement']['custom_index']];
                $snapshot += ['pack_label' => $custom['label'], 'pack_qty' => $custom['qty']];
            }
            $snapshots[$id][] = $snapshot;
        }
        $expanded = [];
        $positionSnapshots = [];
        $pricing = [];
        foreach ($lines as $id => $line) {
            $quote = $this->parties->pricing($contact, $items[$id], 'sale', Money::quantity($line['qty']), $listId, $date);
            foreach ($amountPrices[$id] ?? [] as $amountPrice) {
                if (array_filter($quote['segments'], fn ($segment) => $segment['price_paisa'] !== $amountPrice)) {
                    $this->a->fail('Quantity pricing changes this amount. Enter quantity instead and review total.', 'lines');
                }
            }
            $pricing[] = ['item_id' => $id, ...$quote];
            foreach ($quote['segments'] as $segment) {
                $expanded[] = [...$line, 'qty' => Money::format($segment['qty_milli'], 3), 'unit_price' => Money::format($segment['price_paisa'])];
                if ($quote['pricing_scheme'] === 'slab') {
                    $positionSnapshots[count($expanded)] = ['mode' => 'quantity', 'value' => Money::format($segment['qty_milli'], 3), 'unit' => $items[$id]->pos_unit, 'base_unit' => $items[$id]->pos_unit, 'qty_milli' => (string) $segment['qty_milli'], 'pricing_scheme' => 'slab', 'price_list_id' => (string) $quote['price_list_id'], 'list_version' => $quote['list_version'], 'from_qty_milli' => (string) $segment['from_qty_milli'], 'to_qty_milli' => (string) $segment['to_qty_milli'], 'note' => implode(' ; ', array_unique(array_filter(array_column($snapshots[$id], 'note')))), 'original_measurements' => $snapshots[$id]];
                }
            }
        }
        if (count($expanded) > 100) {
            $this->a->fail('Quantity ranges exceed 100 bill rows. Split this sale.', 'lines');
        }

        $normal = ['lines' => $expanded, 'snapshots' => $snapshots, 'position_snapshots' => $positionSnapshots, 'pricing' => $pricing];
        if (! empty($input['basket_offer_id'])) {
            $offer = app(BasketService::class)->quote(auth('tenant')->id(), (int) $input['basket_offer_id'], $date, $expanded);
            $normal['basket_offer'] = $offer;
            $normal['invoice_discount'] = $offer['allocation_mode'] === 'invoice' ? Money::format($offer['discount_paisa']) : '0';
            foreach ($offer['allocations'] as $allocation) {
                $i = $allocation['position'] - 1;
                $normal['lines'][$i]['discount'] = Money::format($offer['line_bases'][$i]['line_discount_paisa'] + $allocation['discount_paisa']);
                unset($normal['lines'][$i]['discount_bps']);
            }
        }
        $normal['fingerprint'] = hash('sha256', json_encode($normal, JSON_THROW_ON_ERROR));
        if (isset($input['expected_fingerprint'])) {
            abort_unless(hash_equals($normal['fingerprint'], $input['expected_fingerprint']), 409, 'POS quantities or prices changed. Review current cart before payment.');
        }

        return $normal;
    }

    public function preview(array $input): array
    {
        $normal = $this->normalize($input);

        $totals = app(DocumentService::class)->calculate($normal);
        if (isset($normal['basket_offer'])) {
            foreach ($totals['lines'] as $i => &$line) {
                $line['before_offer_paisa'] = $normal['basket_offer']['line_bases'][$i]['before_offer_paisa'];
            } unset($line);
        }

        return [...$totals, 'fingerprint' => $normal['fingerprint'], 'pricing' => $normal['pricing'], 'basket_offer' => $normal['basket_offer'] ?? null];
    }

    public function attachSnapshots(int $doc, array $snapshots, array $positions = []): void
    {
        foreach ($this->a->rows('document_lines')->where('document_id', $doc)->get() as $line) {
            if (isset($positions[$line->position])) {
                $this->a->rows('document_lines')->where('id', $line->id)->update(['measurement_snapshot' => json_encode($positions[$line->position])]);
            } elseif (isset($snapshots[$line->item_id])) {
                $entries = $snapshots[$line->item_id];
                $snapshot = count($entries) === 1 ? $entries[0] : ['mode' => 'multiple', 'entries' => $entries];
                $this->a->rows('document_lines')->where('id', $line->id)->update(['measurement_snapshot' => json_encode($snapshot)]);
            }
        }
    }

    public function sale(int $actor, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'pos.sale', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'accountant', 'cashier']);
            $normal = $this->normalize($input);
            $result = app(DocumentService::class)->save($actor, [...$input, 'type' => 'sale', 'lines' => $normal['lines'], 'invoice_discount' => $normal['invoice_discount'] ?? '0'], (string) Str::uuid(), true, $normal['basket_offer'] ?? null);
            $this->attachSnapshots($result['id'], $normal['snapshots'], $normal['position_snapshots']);

            return $result;
        });
    }

    public function resource(int $actor, array $input, string $uuid, ?int $id = null): array
    {
        $this->a->authorize($actor, ['owner', 'manager']);

        return $this->a->mutate($actor, $uuid, 'pos.resource.'.($id ?? 'new'), $input, function (Tenant $tenant) use ($input, $id) {
            if ($input['start_minute'] >= $input['end_minute']) {
                $this->a->fail('Closing time must follow opening time.');
            }
            $values = array_diff_key($input, ['mutation_uuid' => true, 'version' => true]);
            if ($id) {
                $old = $this->a->requireRow('pos_resources', $id);
                abort_unless($old->version == ($input['version'] ?? 0), 409, 'Resource changed.');
                if ($old->kind !== $input['kind']) {
                    $this->a->fail('Resource type cannot change.');
                }
                if ($this->a->rows('appointments')->where('resource_id', $id)->whereIn('status', ['booked', 'arrived', 'in_service', 'blocked'])->where(fn ($q) => $q->where('start_minute', '<', $input['start_minute'])->orWhere('end_minute', '>', $input['end_minute']))->exists()) {
                    $this->a->fail('Existing appointments lie outside these hours.');
                }
                if (($input['active'] ?? true) === false && ($this->a->rows('restaurant_orders')->where('resource_id', $id)->where('status', 'open')->exists() || $this->a->rows('appointments')->where('resource_id', $id)->whereIn('status', ['booked', 'arrived', 'in_service', 'blocked'])->exists())) {
                    $this->a->fail('Resolve open orders/appointments before disabling.');
                }
                $this->a->rows('pos_resources')->where('id', $id)->update([...$values, 'version' => $old->version + 1, 'updated_at' => now()]);
            } else {
                $id = DB::table('pos_resources')->insertGetId(['tenant_id' => $tenant->id, ...$values, 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['table' => 'pos_resources', 'id' => $id];
        });
    }
}
