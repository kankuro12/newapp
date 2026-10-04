<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentService
{
    public function __construct(private AccountingService $a) {}

    public function data(int $id): array
    {
        $row = $this->a->requireRow('appointments', $id);

        return [...(array) $row, 'services' => json_decode($row->services, true), 'resource_name' => $this->a->requireRow('pos_resources', $row->resource_id)->name];
    }

    private function slot(int $resource, int $day, int $start, int $end, ?int $id = null): void
    {
        NepaliDate::normalize($day);
        $staff = $this->a->requireRow('pos_resources', $resource);
        if ($staff->kind !== 'staff' || ! $staff->active) {
            $this->a->fail('Choose active staff/chair.');
        } if ($start < $staff->start_minute || $end > $staff->end_minute || $start >= $end) {
            $this->a->fail('Slot lies outside staff working hours.');
        }
        $q = $this->a->rows('appointments')->where('resource_id', $resource)->where('business_date_bs', $day)->whereIn('status', ['booked', 'arrived', 'in_service', 'blocked'])->where('start_minute', '<', $end)->where('end_minute', '>', $start);
        if ($id) {
            $q->where('id', '<>', $id);
        } if ($q->exists()) {
            $this->a->fail('Staff/chair already booked at this time. Choose another slot.', 'start_minute');
        }
    }

    public function create(int $actor, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'operations.appointment.create', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $services = [];
            $duration = 0;
            $seen = [];
            if ($input['status'] === 'blocked') {
                $duration = $input['duration_minutes'] ?? 0;
            } else {
                foreach ($input['item_ids'] ?? [] as $itemId) {
                    $item = $this->a->requireRow('items', $itemId);
                    if ($item->kind !== 'service' || $item->archived_at || isset($seen[$itemId])) {
                        $this->a->fail('Choose distinct active services.');
                    } $seen[$itemId] = true;
                    $duration += (int) $item->service_minutes;
                    $services[] = ['item_id' => (string) $item->id, 'name' => $item->name, 'duration_minutes' => (int) $item->service_minutes, 'unit_price_paisa' => (string) $item->sale_price_paisa, 'tax_bps' => (string) $item->default_tax_bps, 'tax_category' => $item->default_tax_category];
                }
            }
            if ($duration < 1 || $duration > 720) {
                $this->a->fail('Choose services or blocked duration (1–720 minutes).');
            }
            $day = NepaliDate::normalize($input['business_date_bs']);
            $end = $input['start_minute'] + $duration;
            $this->slot((int) $input['resource_id'], $day, $input['start_minute'], $end);
            $contact = $input['contact_id'] ?? null;
            if ($contact) {
                $party = $this->a->requireRow('contacts', $contact);
                if (! $party->is_customer || $party->archived_at) {
                    $this->a->fail('Choose active customer.');
                }
            }
            $id = DB::table('appointments')->insertGetId(['tenant_id' => $tenant->id, 'resource_id' => $input['resource_id'], 'contact_id' => $contact, 'client_name' => $input['client_name'], 'phone' => $input['phone'] ?? null, 'business_date_bs' => $day, 'start_minute' => $input['start_minute'], 'end_minute' => $end, 'services' => json_encode($services), 'notes' => $input['notes'] ?? null, 'status' => $input['status'], 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return ['table' => 'appointments', 'id' => $id];
        });
    }

    public function update(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'operations.appointment.update.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $old = $this->a->requireRow('appointments', $id);
            abort_unless($old->version == $input['version'] && ! $old->document_id, 409, 'Booking changed or billed.');
            $next = $input['status'] ?? $old->status;
            $allowed = ['booked' => ['booked', 'arrived', 'cancelled', 'no_show'], 'arrived' => ['arrived', 'in_service', 'cancelled'], 'in_service' => ['in_service', 'cancelled'], 'blocked' => ['blocked', 'cancelled'], 'no_show' => [], 'cancelled' => [], 'completed' => []];
            if (! in_array($next, $allowed[$old->status], true)) {
                $this->a->fail('Invalid appointment status transition.');
            }
            $resource = (int) ($input['resource_id'] ?? $old->resource_id);
            $staff = $this->a->requireRow('pos_resources', $resource);
            if ($staff->kind !== 'staff') {
                $this->a->fail('Appointment requires staff/chair resource.');
            }
            $day = NepaliDate::normalize($input['business_date_bs'] ?? $old->business_date_bs);
            $start = (int) ($input['start_minute'] ?? $old->start_minute);
            $end = $start + $old->end_minute - $old->start_minute;
            if (! in_array($next, ['cancelled', 'no_show'])) {
                $this->slot($resource, $day, $start, $end, $id);
            }
            $this->a->rows('appointments')->where('id', $id)->update(['status' => $next, 'resource_id' => $resource, 'business_date_bs' => $day, 'start_minute' => $start, 'end_minute' => $end, 'version' => $old->version + 1, 'updated_at' => now()]);

            return ['table' => 'appointments', 'id' => $id];
        });
    }

    private function prepareCheckout(int $actor, int $id, array $input, bool $posting = false): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
        $old = $this->a->requireRow('appointments', $id);
        abort_unless($old->version == $input['version'] && ! $old->document_id, 409, 'Booking changed or billed.');
        if (! in_array($old->status, ['arrived', 'in_service'])) {
            $this->a->fail('Client must arrive before checkout.');
        }
        if (NepaliDate::normalize($input['business_date_bs']) !== $old->business_date_bs) {
            $this->a->fail('Checkout date must match appointment day. Reschedule before checkout.');
        }
        $lines = array_map(fn ($service) => ['item_id' => $service['item_id'], 'qty' => '1', 'unit_price' => Money::format((int) $service['unit_price_paisa']), 'tax_category' => $service['tax_category'], 'tax_bps' => (int) $service['tax_bps']], json_decode($old->services, true));

        return app(BasketService::class)->checkout($actor, [...$input, 'contact_id' => $old->contact_id], $lines, ['type' => 'appointment', 'id' => $id, 'version' => (int) $old->version], $posting);
    }

    public function preview(int $actor, int $id, array $input): array
    {
        return $this->prepareCheckout($actor, $id, $input)['preview'];
    }

    public function checkout(int $actor, int $id, array $input, string $uuid): array
    {
        return $this->a->mutate($actor, $uuid, 'pos.appointment.checkout.'.$id, $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $old = $this->a->requireRow('appointments', $id);
            $review = $this->prepareCheckout($actor, $id, $input, true);
            $bill = app(DocumentService::class)->save($actor, [...$input, ...$review['input'], 'type' => 'sale', 'contact_id' => $old->contact_id, 'notes' => 'Appointment #'.$id.' · '.$old->client_name.' · '.$this->data($id)['resource_name']], (string) Str::uuid(), true, $review['preview']['basket_offer']);
            $this->a->rows('appointments')->where('id', $id)->update(['status' => 'completed', 'document_id' => $bill['id'], 'version' => $old->version + 1, 'updated_at' => now()]);

            return $bill;
        });
    }
}
