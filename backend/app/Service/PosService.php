<?php

namespace App\Service;

use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PosService
{
    public const PROFILES = ['general', 'meat', 'restaurant', 'barber', 'salon', 'milk', 'glass', 'wood'];

    public const METHODS = ['quantity', 'amount', 'pack', 'length', 'area', 'volume'];

    // Rational factors in metres, square metres, cubic metres, kilograms, litres or count.
    public const UNITS = [
        'unit' => ['count', '1', '1'], 'kg' => ['mass', '1', '1'], 'g' => ['mass', '1', '1000'],
        'l' => ['liquid', '1', '1'], 'ml' => ['liquid', '1', '1000'],
        'mm' => ['length', '1', '1000'], 'cm' => ['length', '1', '100'], 'm' => ['length', '1', '1'], 'in' => ['length', '127', '5000'], 'ft' => ['length', '381', '1250'],
        'sq_m' => ['area', '1', '1'], 'sq_ft' => ['area', '145161', '1562500'],
        'cu_m' => ['volume', '1', '1'], 'cu_ft' => ['volume', '55306341', '1953125000'], 'board_ft' => ['volume', '55306341', '23437500000'],
    ];

    public function __construct(private AccountingService $a) {}

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
        foreach ($input['lines'] as $row) {
            $item = $this->a->requireRow('items', $row['item_id']);
            if ($item->archived_at) {
                $this->a->fail('Item archived.');
            } $qty = $this->measure($item, $row['measurement']);
            $id = (int) $item->id;
            $old = isset($lines[$id]) ? Money::quantity($lines[$id]['qty']) : 0;
            if ($old + $qty > 1000000000) {
                $this->a->fail('Combined quantity exceeds limit.');
            }
            $lines[$id] = ['item_id' => $id, 'qty' => Money::format($old + $qty, 3), 'unit_price' => Money::format((int) $item->sale_price_paisa), 'tax_bps' => (int) $item->default_tax_bps, 'tax_category' => $item->default_tax_category, 'description' => $item->name, 'unit_snapshot' => $item->unit_label];
            $snapshot = [...$row['measurement'], 'qty_milli' => (string) $qty, 'base_unit' => $item->pos_unit, 'note' => $row['note'] ?? ''];
            if ($row['measurement']['mode'] === 'pack') {
                $custom = json_decode($item->pos_custom_units, true)[$row['measurement']['custom_index']];
                $snapshot += ['pack_label' => $custom['label'], 'pack_qty' => $custom['qty']];
            }
            $snapshots[$id][] = $snapshot;
        }

        return ['lines' => array_values($lines), 'snapshots' => $snapshots];
    }

    public function preview(array $input): array
    {
        $normal = $this->normalize($input);

        return app(DocumentService::class)->calculate($normal);
    }

    public function attachSnapshots(int $doc, array $snapshots): void
    {
        foreach ($this->a->rows('document_lines')->where('document_id', $doc)->get() as $line) {
            if (isset($snapshots[$line->item_id])) {
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
            $result = app(DocumentService::class)->save($actor, [...$input, 'type' => 'sale', 'lines' => $normal['lines']], (string) Str::uuid());
            $this->attachSnapshots($result['id'], $normal['snapshots']);

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
