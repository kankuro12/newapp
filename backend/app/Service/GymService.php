<?php

namespace App\Service;

use App\Models\Tenant;
use App\NepaliDate;
use App\Support\CurrentTenant;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gym daily operations: plans, members, memberships billed through normal sales, check-ins and classes.
 */
class GymService
{
    public function __construct(private AccountingService $a) {}

    /**
     * Tenant filter qualified by table name, for queries that join other tables.
     */
    private function scoped(string $table): Builder
    {
        return DB::table($table)->where($table.'.tenant_id', app(CurrentTenant::class)->id());
    }

    private function requireGym(): void
    {
        $profile = DB::table('tenants')->where('id', app(CurrentTenant::class)->id())->value('pos_profile');
        abort_unless($profile === 'gym', 403, 'Gym tools are available for gym businesses.');
    }

    public function plans(): array
    {
        $this->requireGym();

        return $this->a->rows('gym_plans')->orderByDesc('active')->orderBy('name')->get()->map(fn ($plan) => $this->plan($plan))->all();
    }

    private function plan(object $row): array
    {
        return [...(array) $row, 'item_name' => $this->a->rows('items')->where('id', $row->item_id)->value('name')];
    }

    public function savePlan(int $actor, ?int $id, array $input, string $uuid): array
    {
        $this->requireGym();

        return $this->a->mutate($actor, $uuid, 'gym.plan.'.($id ?? 'new'), $input, function (Tenant $tenant) use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager']);
            $item = $this->a->requireRow('items', $input['item_id']);
            if ($item->kind !== 'service' || $item->archived_at) {
                $this->a->fail('Choose an active service item to bill this plan.', 'item_id');
            }
            $fee = Money::parse($input['fee']);
            if ($fee < 1) {
                $this->a->fail('Enter a plan fee above zero.', 'fee');
            }
            $values = ['name' => trim($input['name']), 'item_id' => $item->id, 'fee_paisa' => $fee, 'duration_days' => $input['duration_days'], 'visit_limit' => $input['visit_limit'] ?? null, 'class_access' => $input['class_access'] ?? true, 'active' => $input['active'] ?? true, 'updated_at' => now()];
            if ($this->a->rows('gym_plans')->where('name', $values['name'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                $this->a->fail('Plan name already used.', 'name');
            }
            if ($id) {
                $old = $this->a->requireRow('gym_plans', $id);
                abort_unless($old->version == ($input['version'] ?? 0), 409, 'Plan changed. Reload before saving.');
                $this->a->rows('gym_plans')->where('id', $id)->update([...$values, 'version' => $old->version + 1]);
            } else {
                $id = DB::table('gym_plans')->insertGetId([...$values, 'tenant_id' => $tenant->id, 'created_at' => now()]);
            }

            return ['table' => 'gym_plans', 'id' => $id];
        });
    }

    public function members(?string $search): array
    {
        $this->requireGym();
        $today = NepaliDate::today();
        $query = $this->scoped('gym_members')->join('contacts', 'contacts.id', '=', 'gym_members.contact_id')
            ->select('gym_members.*', 'contacts.name', 'contacts.phone')->orderBy('contacts.name')->limit(200);
        if ($search) {
            $query->where(fn ($q) => $q->where('contacts.name', 'like', '%'.$search.'%')->orWhere('gym_members.member_code', $search)->orWhere('contacts.phone', 'like', '%'.$search.'%'));
        }

        return $query->get()->map(fn ($member) => [...(array) $member, 'current' => $this->currentMembership((int) $member->id, $today)])->all();
    }

    public function member(int $id): array
    {
        $this->requireGym();
        $member = $this->scoped('gym_members')->where('gym_members.id', $id)->join('contacts', 'contacts.id', '=', 'gym_members.contact_id')->select('gym_members.*', 'contacts.name', 'contacts.phone')->first() ?? abort(404);

        return [...(array) $member, 'current' => $this->currentMembership($id, NepaliDate::today()),
            'memberships' => $this->a->rows('gym_memberships')->where('member_id', $id)->orderByDesc('start_bs')->limit(50)->get()->map(fn ($row) => $this->membershipData($row))->all(),
            'checkins' => $this->a->rows('gym_checkins')->where('member_id', $id)->orderByDesc('business_date_bs')->limit(30)->get()->all()];
    }

    private function membershipData(object $row): array
    {
        $document = $row->document_id ? $this->a->rows('documents')->where('id', $row->document_id)->first(['id', 'number', 'cancellation_date_bs']) : null;

        return [...(array) $row, 'plan_snapshot' => json_decode($row->plan_snapshot, true), 'document_number' => $document?->number, 'document_cancelled' => (bool) $document?->cancellation_date_bs];
    }

    public function currentMembership(int $member, int $day): ?array
    {
        $row = $this->a->rows('gym_memberships')->where('member_id', $member)->where('status', 'active')->where('start_bs', '<=', $day)->where('end_bs', '>=', $day)->orderBy('end_bs')->first();

        return $row ? $this->membershipData($row) : null;
    }

    public function createMember(int $actor, array $input, string $uuid): array
    {
        $this->requireGym();

        return $this->a->mutate($actor, $uuid, 'operations.gym.member', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            if (! empty($input['contact_id'])) {
                $contact = $this->a->requireRow('contacts', $input['contact_id']);
                if (! $contact->is_customer || $contact->archived_at || $contact->is_system) {
                    $this->a->fail('Choose an active customer.', 'contact_id');
                }
                if ($this->a->rows('gym_members')->where('contact_id', $contact->id)->exists()) {
                    $this->a->fail('This customer is already a member.', 'contact_id');
                }
                $contactId = (int) $contact->id;
            } else {
                if (empty($input['name'])) {
                    $this->a->fail('Enter the member name or choose a customer.', 'name');
                }
                $contactId = DB::table('contacts')->insertGetId(['tenant_id' => $tenant->id, 'name' => trim($input['name']), 'phone' => $input['phone'] ?? null, 'is_customer' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $code = trim((string) ($input['member_code'] ?? '')) ?: 'M'.str_pad((string) ($this->a->rows('gym_members')->count() + 1), 4, '0', STR_PAD_LEFT);
            if ($this->a->rows('gym_members')->where('member_code', $code)->exists()) {
                $this->a->fail('Member code already used.', 'member_code');
            }
            $id = DB::table('gym_members')->insertGetId(['tenant_id' => $tenant->id, 'contact_id' => $contactId, 'member_code' => $code, 'joined_bs' => NepaliDate::normalize($input['business_date_bs']), 'notes' => $input['notes'] ?? null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return ['table' => 'gym_members', 'id' => $id];
        });
    }

    /**
     * Join or renew: a renewal starts the day after the latest active membership ends.
     */
    private function prepareJoin(int $actor, array $input, bool $posting = false): array
    {
        $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
        $member = $this->a->requireRow('gym_members', $input['member_id']);
        if (! $member->active) {
            $this->a->fail('Member is inactive.', 'member_id');
        }
        $plan = $this->a->requireRow('gym_plans', $input['plan_id']);
        if (! $plan->active) {
            $this->a->fail('Choose an active plan.', 'plan_id');
        }
        abort_if(isset($input['plan_version']) && (int) $input['plan_version'] !== (int) $plan->version, 409, 'Plan changed. Review the fee again.');
        $item = $this->a->requireRow('items', $plan->item_id);
        $day = NepaliDate::normalize($input['business_date_bs']);
        $latest = $this->a->rows('gym_memberships')->where('member_id', $member->id)->where('status', 'active')->max('end_bs');
        $start = $latest && $latest >= $day ? NepaliDate::addDays((int) $latest, 1) : $day;
        $end = NepaliDate::addDays($start, $plan->duration_days - 1);
        $snapshot = ['plan_id' => (string) $plan->id, 'name' => $plan->name, 'fee_paisa' => (string) $plan->fee_paisa, 'duration_days' => (int) $plan->duration_days, 'visit_limit' => $plan->visit_limit === null ? null : (int) $plan->visit_limit, 'class_access' => (bool) $plan->class_access, 'item_id' => (string) $item->id, 'version' => (int) $plan->version];
        $lines = [['item_id' => $item->id, 'qty' => '1', 'unit_price' => Money::format((int) $plan->fee_paisa), 'tax_category' => $item->default_tax_category, 'tax_bps' => (int) $item->default_tax_bps]];
        $checkout = app(BasketService::class)->checkout($actor, [...$input, 'contact_id' => $member->contact_id], $lines, ['type' => 'gym_membership', 'member_id' => (int) $member->id, 'plan_version' => (int) $plan->version, 'start_bs' => $start], $posting);

        return ['member' => $member, 'plan' => $plan, 'start' => $start, 'end' => $end, 'snapshot' => $snapshot, 'checkout' => $checkout];
    }

    public function joinPreview(int $actor, array $input): array
    {
        $this->requireGym();
        $prepared = $this->prepareJoin($actor, $input);

        return [...$prepared['checkout']['preview'], 'start_bs' => $prepared['start'], 'end_bs' => $prepared['end'], 'plan' => $prepared['snapshot']];
    }

    public function join(int $actor, array $input, string $uuid): array
    {
        $this->requireGym();

        return $this->a->mutate($actor, $uuid, 'operations.gym.join', $input, function (Tenant $tenant) use ($actor, $input) {
            $prepared = $this->prepareJoin($actor, $input, true);
            $contact = $this->a->requireRow('contacts', $prepared['member']->contact_id);
            $bill = app(DocumentService::class)->save($actor, [...$input, ...$prepared['checkout']['input'], 'type' => 'sale', 'contact_id' => $prepared['member']->contact_id,
                'notes' => 'Gym membership · '.$contact->name.' · '.$prepared['plan']->name.' · '.$prepared['start'].'–'.$prepared['end']], (string) Str::uuid(), true, $prepared['checkout']['preview']['basket_offer']);
            $id = DB::table('gym_memberships')->insertGetId(['tenant_id' => $tenant->id, 'member_id' => $prepared['member']->id, 'plan_id' => $prepared['plan']->id,
                'plan_snapshot' => json_encode($prepared['snapshot'], JSON_THROW_ON_ERROR), 'start_bs' => $prepared['start'], 'end_bs' => $prepared['end'], 'document_id' => $bill['id'],
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return ['table' => 'gym_memberships', 'id' => $id];
        });
    }

    /**
     * Ending a membership requires its invoice to be cancelled or returned first, keeping reversal rules in one place.
     */
    public function cancelMembership(int $actor, int $id, array $input, string $uuid): array
    {
        $this->requireGym();

        return $this->a->mutate($actor, $uuid, 'gym.membership.cancel.'.$id, $input, function () use ($actor, $id, $input) {
            $this->a->authorize($actor, ['owner', 'manager']);
            $row = $this->a->rows('gym_memberships')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($row->version == $input['version'] && $row->status === 'active', 409, 'Membership changed. Reload.');
            if ($row->document_id) {
                $document = $this->a->requireRow('documents', $row->document_id);
                $returned = (int) $this->a->rows('documents')->where('source_document_id', $row->document_id)->whereNull('cancellation_date_bs')->count();
                if (! $document->cancellation_date_bs && ! $returned) {
                    $this->a->fail('Cancel or return the membership invoice first, then end the membership.');
                }
            }
            $this->a->rows('gym_memberships')->where('id', $id)->update(['status' => 'cancelled', 'version' => $row->version + 1, 'updated_at' => now()]);

            return ['table' => 'gym_memberships', 'id' => $id];
        });
    }

    public function checkIn(int $actor, array $input, string $uuid): array
    {
        $this->requireGym();

        return $this->a->mutate($actor, $uuid, 'operations.gym.checkin', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $day = NepaliDate::normalize($input['business_date_bs']);
            $member = $this->a->requireRow('gym_members', $input['member_id']);
            $current = $this->a->rows('gym_memberships')->where('member_id', $member->id)->where('status', 'active')->where('start_bs', '<=', $day)->where('end_bs', '>=', $day)->orderBy('end_bs')->lockForUpdate()->first();
            if (! $member->active || ! $current) {
                $this->a->fail('No active membership for this day. Renew before check-in.', 'member_id');
            }
            if ($this->a->rows('gym_checkins')->where('membership_id', $current->id)->where('business_date_bs', $day)->exists()) {
                $this->a->fail('Member already checked in today.', 'member_id');
            }
            $limit = json_decode($current->plan_snapshot, true)['visit_limit'] ?? null;
            if ($limit !== null && $current->visits_used >= $limit) {
                $this->a->fail('Visit allowance used up. Renew the membership.', 'member_id');
            }
            $id = DB::table('gym_checkins')->insertGetId(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'membership_id' => $current->id, 'business_date_bs' => $day, 'minute' => $input['minute'] ?? 0, 'created_by' => $actor, 'created_at' => now()]);
            $this->a->rows('gym_memberships')->where('id', $current->id)->update(['visits_used' => $current->visits_used + 1, 'updated_at' => now()]);

            return ['table' => 'gym_checkins', 'id' => $id];
        });
    }

    public function day(int $day): array
    {
        $this->requireGym();
        $day = NepaliDate::normalize($day);

        return [
            'checkins' => $this->scoped('gym_checkins')->where('gym_checkins.business_date_bs', $day)->join('gym_members', 'gym_members.id', '=', 'gym_checkins.member_id')
                ->join('contacts', 'contacts.id', '=', 'gym_members.contact_id')->orderByDesc('gym_checkins.id')->get(['gym_checkins.*', 'contacts.name', 'gym_members.member_code'])->all(),
            'classes' => $this->a->rows('gym_classes')->where('business_date_bs', $day)->orderBy('start_minute')->get()->all(),
            'expiring' => $this->scoped('gym_memberships')->where('gym_memberships.status', 'active')->whereBetween('gym_memberships.end_bs', [$day, NepaliDate::addDays($day, 7)])
                ->join('gym_members', 'gym_members.id', '=', 'gym_memberships.member_id')->join('contacts', 'contacts.id', '=', 'gym_members.contact_id')
                ->orderBy('end_bs')->limit(50)->get(['gym_memberships.id', 'gym_memberships.member_id', 'gym_memberships.end_bs', 'contacts.name', 'gym_members.member_code'])->all(),
        ];
    }

    public function createClass(int $actor, array $input, string $uuid): array
    {
        $this->requireGym();

        return $this->a->mutate($actor, $uuid, 'operations.gym.class', $input, function (Tenant $tenant) use ($actor, $input) {
            $this->a->authorize($actor, ['owner', 'manager']);
            $id = DB::table('gym_classes')->insertGetId(['tenant_id' => $tenant->id, 'name' => trim($input['name']), 'trainer_name' => $input['trainer_name'] ?? null, 'business_date_bs' => NepaliDate::normalize($input['business_date_bs']),
                'start_minute' => $input['start_minute'], 'duration_minutes' => $input['duration_minutes'], 'capacity' => $input['capacity'], 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return ['table' => 'gym_classes', 'id' => $id];
        });
    }

    public function book(int $actor, int $classId, array $input, string $uuid): array
    {
        $this->requireGym();

        return $this->a->mutate($actor, $uuid, 'operations.gym.book.'.$classId, $input, function (Tenant $tenant) use ($actor, $classId, $input) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            // Lock the class row so two desks cannot take the last seat.
            $class = $this->a->rows('gym_classes')->where('id', $classId)->lockForUpdate()->first() ?? abort(404);
            if ($class->status !== 'scheduled') {
                $this->a->fail('Class is not open for booking.');
            }
            $member = $this->a->requireRow('gym_members', $input['member_id']);
            $current = $this->currentMembership((int) $member->id, (int) $class->business_date_bs);
            if (! $current || ! ($current['plan_snapshot']['class_access'] ?? false)) {
                $this->a->fail('Member needs an active membership that includes classes on the class day.', 'member_id');
            }
            $existing = $this->a->rows('gym_bookings')->where('class_id', $classId)->where('member_id', $member->id)->first();
            if ($existing && $existing->status === 'booked') {
                $this->a->fail('Member already booked this class.', 'member_id');
            }
            if ($class->booked >= $class->capacity) {
                $this->a->fail('Class is full.');
            }
            if ($existing) {
                $this->a->rows('gym_bookings')->where('id', $existing->id)->update(['status' => 'booked', 'updated_at' => now()]);
                $id = $existing->id;
            } else {
                $id = DB::table('gym_bookings')->insertGetId(['tenant_id' => $tenant->id, 'class_id' => $classId, 'member_id' => $member->id, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->a->rows('gym_classes')->where('id', $classId)->update(['booked' => $class->booked + 1, 'version' => $class->version + 1, 'updated_at' => now()]);

            return ['table' => 'gym_bookings', 'id' => $id];
        });
    }

    public function cancelBooking(int $actor, int $bookingId, array $input, string $uuid): array
    {
        $this->requireGym();

        return $this->a->mutate($actor, $uuid, 'operations.gym.unbook.'.$bookingId, $input, function () use ($actor, $bookingId) {
            $this->a->authorize($actor, ['owner', 'manager', 'cashier']);
            $booking = $this->a->requireRow('gym_bookings', $bookingId);
            $class = $this->a->rows('gym_classes')->where('id', $booking->class_id)->lockForUpdate()->first();
            if ($booking->status !== 'booked') {
                $this->a->fail('Booking already cancelled.');
            }
            $this->a->rows('gym_bookings')->where('id', $bookingId)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->a->rows('gym_classes')->where('id', $class->id)->update(['booked' => max(0, $class->booked - 1), 'version' => $class->version + 1, 'updated_at' => now()]);

            return ['table' => 'gym_bookings', 'id' => $bookingId];
        });
    }

    public function bookings(int $classId): array
    {
        $this->requireGym();
        $this->a->requireRow('gym_classes', $classId);

        return $this->scoped('gym_bookings')->where('gym_bookings.class_id', $classId)->join('gym_members', 'gym_members.id', '=', 'gym_bookings.member_id')
            ->join('contacts', 'contacts.id', '=', 'gym_members.contact_id')->orderBy('contacts.name')->get(['gym_bookings.*', 'contacts.name', 'gym_members.member_code'])->all();
    }
}
