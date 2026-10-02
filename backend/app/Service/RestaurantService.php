<?php

namespace App\Service;

use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RestaurantService
{
    public function __construct(private AccountingService $a) {}

    public function data(int $id): array
    {
        $order = $this->a->requireRow('restaurant_orders', $id);
        $tickets = $this->a->rows('kitchen_tickets')->where('order_id', $id)->orderBy('id')->get()->map(function ($ticket) {
            $ticket->lines = json_decode($ticket->lines, true);
            $ticket->created_at = (new \DateTimeImmutable($ticket->created_at, new \DateTimeZone(config('app.timezone'))))->format(DATE_ATOM);

            return (array) $ticket;
        });
        $billLines = $this->billLines($tickets->all());
        $total = $billLines ? app(DocumentService::class)->calculate(['lines' => $billLines])['total_paisa'] : 0;

        return [...(array) $order, 'resource_name' => $order->resource_id ? $this->a->requireRow('pos_resources', $order->resource_id)->name : 'Takeaway', 'tickets' => $tickets->all(), 'total_paisa' => $total];
    }

    private function billLines(array $tickets): array
    {
        $lines = [];
        foreach ($tickets as $ticket) {
            if ($ticket['status'] === 'cancelled') {
                continue;
            }
            foreach ($ticket['lines'] as $row) {
                $id = $row['item_id'];
                $qty = (int) $row['qty_milli'] + (isset($lines[$id]) ? Money::quantity($lines[$id]['qty']) : 0);
                if ($qty > 1000000000) {
                    $this->a->fail('Order quantity exceeds bill limit.');
                }
                $lines[$id] = ['item_id' => $id, 'qty' => Money::format($qty, 3), 'unit_price' => Money::format((int) $row['unit_price_paisa']), 'tax_category' => $row['tax_category'], 'tax_bps' => (int) $row['tax_bps']];
            }
        }
        if (count($lines) > 100) {
            $this->a->fail('Maximum 100 distinct menu items per order.');
        }

        return array_values($lines);
    }

    private function open(int $id, array $input): object
    {
        $order = $this->a->requireRow('restaurant_orders', $id);
        abort_unless($order->status === 'open' && $order->version == $input['version'], 409, 'Order changed or closed. Refresh before continuing.');

        return $order;
    }

    public function create(int $actor, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'operations.order.create', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $resource = $input['resource_id'] ?? null;
            if ($input['kind'] === 'dine_in') {
                if (! $resource) {
                    $this->a->fail('Choose a table.');
                } $table = $this->a->requireRow('pos_resources', $resource);
                if ($table->kind !== 'table' || ! $table->active) {
                    $this->a->fail('Choose active table.');
                } if ($this->a->rows('restaurant_orders')->where('resource_id', $resource)->where('status', 'open')->exists()) {
                    $this->a->fail('Table already has an open order. Open that order instead.');
                }
            } elseif ($resource) {
                $this->a->fail('Takeaway order does not use a table.');
            }
            $id = DB::table('restaurant_orders')->insertGetId(['tenant_id' => $tenant->id, 'kind' => $input['kind'], 'resource_id' => $resource, 'guest_name' => $input['guest_name'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return ['table' => 'restaurant_orders', 'id' => $id];
        });
    }

    public function send(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'operations.order.send.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $order = $this->open($id, $input);
            $prior = [];
            foreach ($this->data($id)['tickets'] as $ticket) {
                foreach ($ticket['lines'] as $line) {
                    $prior[$line['item_id']] = $line;
                }
            }
            $lines = [];
            foreach ($input['lines'] as $row) {
                $item = $this->a->requireRow('items', $row['item_id']);
                if ($item->archived_at) {
                    $this->a->fail('Item archived.');
                } $qty = Money::quantity($row['qty']);
                if (! $qty) {
                    $this->a->fail('Order quantity must be positive.');
                } $old = $prior[$item->id] ?? null;
                $price = (int) ($old['unit_price_paisa'] ?? $item->sale_price_paisa);
                if ($price <= 0) {
                    $this->a->fail('Menu item needs positive price.');
                }
                $lines[] = ['item_id' => (string) $item->id, 'name' => $item->name, 'qty_milli' => (string) $qty, 'unit_price_paisa' => (string) $price, 'tax_bps' => (string) ($old['tax_bps'] ?? $item->default_tax_bps), 'tax_category' => $old['tax_category'] ?? $item->default_tax_category, 'note' => $row['note'] ?? ''];
            }
            $candidate = $this->billLines([...$this->data($id)['tickets'], ['status' => 'new', 'lines' => $lines]]);
            app(DocumentService::class)->calculate(['lines' => $candidate]);
            DB::table('kitchen_tickets')->insert(['tenant_id' => $tenant->id, 'order_id' => $id, 'lines' => json_encode($lines), 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            $this->a->rows('restaurant_orders')->where('id', $id)->update(['version' => $order->version + 1, 'updated_at' => now()]);

            return ['table' => 'restaurant_orders', 'id' => $id];
        });
    }

    public function ticket(int $actor, int $id, int $ticketId, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'operations.ticket.'.$ticketId, $input, function (Tenant $tenant) use ($actor, $id, $ticketId, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $order = $this->open($id, $input);
            $ticket = $this->a->requireRow('kitchen_tickets', $ticketId);
            abort_unless($ticket->order_id == $id, 404);
            $next = $input['status'];
            $allowed = ['new' => ['preparing', 'cancelled'], 'preparing' => ['ready', 'cancelled'], 'ready' => ['served'], 'served' => [], 'cancelled' => []];
            if (! in_array($next, $allowed[$ticket->status], true)) {
                $this->a->fail('Invalid kitchen status transition.');
            } if ($next === 'cancelled') {
                $this->a->authorize($actor, ['owner', 'manager']);
                if (empty($input['reason'])) {
                    $this->a->fail('Cancellation needs a reason.');
                } $this->a->audit($actor, 'kitchen.cancelled', 'kitchen_tickets', $ticketId, ['reason' => $input['reason']]);
            }
            $this->a->rows('kitchen_tickets')->where('id', $ticketId)->update(['status' => $next, 'updated_at' => now()]);
            $this->a->rows('restaurant_orders')->where('id', $id)->update(['version' => $order->version + 1, 'updated_at' => now()]);

            return ['table' => 'restaurant_orders', 'id' => $id];
        });
    }

    public function cancel(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'operations.order.cancel.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager']);
            $order = $this->open($id, $input);
            if ($this->a->rows('kitchen_tickets')->where('order_id', $id)->whereIn('status', ['ready', 'served'])->exists()) {
                $this->a->fail('Ready/served food must be billed; cancellation cannot hide it.');
            } $this->a->rows('kitchen_tickets')->where('order_id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->a->rows('restaurant_orders')->where('id', $id)->update(['status' => 'cancelled', 'version' => $order->version + 1, 'updated_at' => now()]);
            $this->a->audit($actor, 'restaurant.cancelled', 'restaurant_orders', $id, ['reason' => $input['reason']]);

            return ['table' => 'restaurant_orders', 'id' => $id];
        });
    }

    public function checkout(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'pos.restaurant.checkout.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $order = $this->open($id, $input);
            $lines = [];
            $snapshots = [];
            foreach ($this->data($id)['tickets'] as $ticket) {
                if ($ticket['status'] === 'cancelled') {
                    continue;
                } if ($ticket['status'] !== 'served') {
                    $this->a->fail('Serve or resolve all kitchen tickets before checkout.');
                } foreach ($ticket['lines'] as $row) {
                    $item = $row['item_id'];
                    $qty = (int) $row['qty_milli'] + (isset($lines[$item]) ? Money::quantity($lines[$item]['qty']) : 0);
                    if ($qty > 1000000000) {
                        $this->a->fail('Quantity exceeds bill limit.');
                    } $lines[$item] = ['item_id' => $item, 'qty' => Money::format($qty, 3), 'unit_price' => Money::format((int) $row['unit_price_paisa']), 'tax_category' => $row['tax_category'], 'tax_bps' => (int) $row['tax_bps']];
                    $snapshots[$item][] = ['mode' => 'quantity', 'value' => Money::format((int) $row['qty_milli'], 3), 'qty_milli' => $row['qty_milli'], 'note' => $row['note'], 'ticket_id' => (string) $ticket['id']];
                }
            }
            if (! $lines) {
                $this->a->fail('No served items to bill.');
            } $bill = app(DocumentService::class)->save($actor, [...$input, 'type' => 'sale', 'lines' => array_values($lines), 'notes' => 'Restaurant order #'.$id.' · '.$this->data($id)['resource_name']], (string) Str::uuid());
            app(PosService::class)->attachSnapshots($bill['id'], $snapshots);
            $this->a->rows('restaurant_orders')->where('id', $id)->update(['status' => 'closed', 'document_id' => $bill['id'], 'version' => $order->version + 1, 'updated_at' => now()]);

            return $bill;
        });
    }
}
