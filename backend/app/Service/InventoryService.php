<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(private AccountingService $a) {}

    /**
     * Cost of an outflow at the moving average. Selling below zero is allowed: the shortfall is costed at
     * the current average, or at the fallback unit cost (paisa per base unit) when stock is exactly zero.
     */
    public function outflowCost(int $poolQty, int $poolValue, int $qty, int $fallbackUnitPaisa = 0): int
    {
        if ($qty <= 0) {
            $this->a->fail('Enter a quantity above zero.', 'lines');
        }
        if ($poolQty > 0) {
            return $qty === $poolQty ? $poolValue : Money::multiplyDivide($poolValue, $qty, $poolQty);
        }
        if ($poolQty < 0) {
            return Money::multiplyDivide(-$poolValue, $qty, -$poolQty);
        }

        return Money::multiplyDivide(max(0, $fallbackUnitPaisa), $qty, 1000);
    }

    public function pool(int $item): object
    {
        $master = $this->a->requireRow('items', $item);
        if ($master->kind !== 'stock') {
            $this->a->fail('Service has no stock.');
        }
        $this->a->rows('inventory_balances')->where('item_id', $item)->first() ?? DB::table('inventory_balances')->insert(['tenant_id' => app(CurrentTenant::class)->id(), 'item_id' => $item, 'qty_milli' => 0, 'value_paisa' => 0]);

        return $this->a->rows('inventory_balances')->where('item_id', $item)->lockForUpdate()->first();
    }

    private function movement(int $actor, int $item, int $date, int $qty, int $value, array $source): int
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Stock transaction required.');
        }
        $tenant = Tenant::findOrFail(app(CurrentTenant::class)->id());
        if ($date < ($tenant->last_stock_date_bs ?? 0)) {
            $this->a->fail('Stock date precedes last stock transaction.', 'business_date_bs');
        }
        $pool = $this->pool($item);
        $newQty = Money::checked(bcadd((string) $pool->qty_milli, (string) $qty, 0));
        $newValue = Money::checked(bcadd((string) $pool->value_paisa, (string) $value, 0));
        // Positive stock has non-negative value; sold-ahead (negative) stock has zero or negative value.
        if (! (($newQty > 0 && $newValue >= 0) || ($newQty === 0 && $newValue === 0) || ($newQty < 0 && $newValue <= 0))) {
            $this->a->fail('Stock reversal would invalidate valuation.');
        }
        $this->a->rows('inventory_balances')->where('item_id', $item)->update(['qty_milli' => $newQty, 'value_paisa' => $newValue]);
        $id = DB::table('stock_movements')->insertGetId(['tenant_id' => $tenant->id, 'item_id' => $item, 'business_date_bs' => $date, 'qty_delta_milli' => $qty, 'value_delta_paisa' => $value, 'qty_after_milli' => $newQty, 'value_after_paisa' => $newValue, 'created_by' => $actor, 'created_at' => now(), ...$source]);
        $tenant->update(['last_stock_date_bs' => $date]);

        return $id;
    }

    public function receive(int $actor, int $item, int $date, int $qty, int $value, array $source): int
    {
        if ($qty <= 0 || $value < 0) {
            $this->a->fail('Invalid inbound stock.');
        }
        $pool = $this->pool($item);
        $variance = 0;
        if ($pool->qty_milli < 0) {
            // Units covering stock already sold below zero: their purchase cost replaces the cost charged at sale.
            $covered = min(-$pool->qty_milli, $qty);
            $charged = Money::multiplyDivide(-$pool->value_paisa, $covered, -$pool->qty_milli);
            $variance = Money::multiplyDivide($value, $covered, $qty) - $charged;
        }
        $id = $this->movement($actor, $item, $date, $qty, $value - $variance, $source);
        if ($variance) {
            $journal = $this->a->post($actor, $date, ['type' => 'stock_cost', 'id' => $id], [
                $this->a->line('cogs', $variance),
                $this->a->line('inventory', -$variance),
            ], 'Cost difference for stock sold before it arrived');
            $this->a->rows('stock_movements')->where('id', $id)->update(['variance_journal_id' => $journal]);
        }

        return $id;
    }

    public function issue(int $actor, int $item, int $date, int $qty, array $source): array
    {
        $pool = $this->pool($item);
        $fallback = (int) ($this->a->requireRow('items', $item)->last_purchase_price_paisa ?? 0);
        $cost = $this->outflowCost((int) $pool->qty_milli, (int) $pool->value_paisa, $qty, $fallback);

        return ['id' => $this->movement($actor, $item, $date, -$qty, -$cost, $source), 'cost' => $cost];
    }

    public function reverse(int $actor, int $movement, int $date): int
    {
        $old = $this->a->requireRow('stock_movements', $movement);
        if ($this->a->rows('stock_movements')->where('reversal_of_id', $movement)->exists()) {
            $this->a->fail('Movement already reversed.');
        }
        $reversed = $this->a->rows('stock_movements')->whereNotNull('reversal_of_id')->select('reversal_of_id');
        abort_if($this->a->rows('stock_movements')->where('item_id', $old->item_id)->where('id', '>', $movement)->where('source_event', 'post')->whereNotIn('id', $reversed)->exists(), 409, 'Later stock transactions exist; use return or stock count.');
        $pool = $this->pool((int) $old->item_id);
        abort_unless($pool->qty_milli == $old->qty_after_milli && $pool->value_paisa == $old->value_after_paisa, 409, 'Stock changed; reverse later activity first.');

        $id = $this->movement($actor, (int) $old->item_id, $date, -(int) $old->qty_delta_milli, -(int) $old->value_delta_paisa, ['document_line_id' => $old->document_line_id, 'stock_adjustment_id' => $old->stock_adjustment_id, 'opening_balance_id' => $old->opening_balance_id, 'fulfilment_line_id' => $old->fulfilment_line_id, 'source_event' => 'reverse', 'reversal_of_id' => $movement]);
        if ($old->variance_journal_id) {
            $this->a->reverse($actor, (int) $old->variance_journal_id, $date, 'Stock movement reversed');
        }

        return $id;
    }

    public function adjust(int $actor, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'stock.count', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->a->authorize($actor, ['owner', 'manager']);
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->a->assertOpenDate($tenant, $date);
            $item = $this->a->requireRow('items', $input['item_id']);
            if ($item->archived_at) {
                $this->a->fail('Archived item unavailable.');
            }
            $pool = $this->pool((int) $item->id);
            abort_unless((string) $pool->qty_milli === (string) $input['expected_qty_milli'], 409, 'Stock changed; review count again.');
            $delta = Money::quantity($input['counted_qty']) - $pool->qty_milli;
            if (! $delta) {
                $this->a->fail('Count matches current stock.');
            }
            $value = $delta > 0 ? Money::multiplyDivide($delta, Money::parse($input['unit_cost'] ?? '0'), 1000) : $this->outflowCost((int) $pool->qty_milli, (int) $pool->value_paisa, -$delta, (int) ($item->last_purchase_price_paisa ?? 0));
            if ($delta > 0 && ! $value && empty($input['zero_cost_confirmed'])) {
                $this->a->fail('Confirm zero cost or enter inbound cost.');
            }
            $id = DB::table('stock_adjustments')->insertGetId(['tenant_id' => $tenant->id, 'item_id' => $item->id, 'qty_delta_milli' => $delta, 'business_date_bs' => $date, 'reason' => $input['reason'], 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            if ($delta > 0) {
                $this->receive($actor, (int) $item->id, $date, $delta, $value, ['stock_adjustment_id' => $id]);
            } else {
                $this->movement($actor, (int) $item->id, $date, $delta, -$value, ['stock_adjustment_id' => $id]);
            }
            $journal = $value ? $this->a->post($actor, $date, ['type' => 'stock_adjustment', 'id' => $id], [$this->a->line('inventory', $delta > 0 ? $value : -$value), $this->a->line($delta > 0 ? 'inventory_gain' : 'inventory_loss', $delta > 0 ? -$value : $value)], $input['reason']) : null;
            $this->a->rows('stock_adjustments')->where('id', $id)->update(['journal_id' => $journal]);

            return ['table' => 'stock_adjustments', 'id' => $id];
        });
    }

    public function cancel(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'stock.cancel.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager']);
            $old = $this->a->requireRow('stock_adjustments', $id);
            if ($old->status === 'cancelled') {
                return ['table' => 'stock_adjustments', 'id' => $id];
            }
            $date = NepaliDate::normalize($input['business_date_bs']);
            $this->a->assertOpenDate($tenant, $old->business_date_bs);
            $this->a->assertOpenDate($tenant, $date);
            if ($date < $old->business_date_bs) {
                $this->a->fail('Reversal precedes original date.');
            }
            $move = $this->a->rows('stock_movements')->where('stock_adjustment_id', $id)->where('source_event', 'post')->first();
            $this->reverse($actor, (int) $move->id, $date);
            $journal = $old->journal_id ? $this->a->reverse($actor, (int) $old->journal_id, $date, $input['reason']) : null;
            $this->a->rows('stock_adjustments')->where('id', $id)->update(['status' => 'cancelled', 'cancellation_date_bs' => $date, 'cancellation_reason' => $input['reason'], 'reversal_journal_id' => $journal]);

            return ['table' => 'stock_adjustments', 'id' => $id];
        });
    }
}
