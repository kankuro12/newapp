<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GymTest extends TestCase
{
    use RefreshDatabase;

    private function gym(string $type = 'gym'): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Iron gym', 'business_type' => $type])->assertCreated()->json('data');
        $url = '/api/app/'.$business['slug'];
        $cash = $this->getJson($url.'/lookup')->assertOk()->json('data.accounts.0.id');
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $item = $this->postJson($url.'/items', ['name' => 'Membership fee', 'kind' => 'service', 'unit_label' => 'plan', 'sale_price' => '0'])->assertCreated()->json('data.id');
        $plan = $this->postJson($url.'/gym/plans', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Monthly', 'item_id' => $item, 'fee' => '1500', 'duration_days' => 30, 'visit_limit' => 2])->assertCreated()->json('data');
        $member = $this->postJson($url.'/gym/members', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'name' => 'Asha Member', 'phone' => '9800000001'])->assertCreated()->json('data');

        return compact('owner', 'business', 'url', 'cash', 'item', 'plan', 'member');
    }

    private function join(array $s, string $date = '20830101'): array
    {
        $review = ['member_id' => $s['member']['id'], 'plan_id' => $s['plan']['id'], 'business_date_bs' => $date];
        $preview = $this->postJson($s['url'].'/gym/memberships/preview', $review)->assertOk()->json('data');

        return $this->postJson($s['url'].'/gym/memberships', [...$review, 'mutation_uuid' => (string) Str::uuid(), 'paid_now' => '1500', 'money_account_id' => $s['cash'], 'expected_total_paisa' => $preview['total_paisa'], 'expected_fingerprint' => $preview['fingerprint']])->assertCreated()->json('data');
    }

    public function test_join_posts_exact_fee_and_renewal_starts_after_current_term(): void
    {
        $s = $this->gym();
        $member = $this->join($s);
        $first = $member['memberships'][0];
        $this->assertSame(20830101, (int) $first['start_bs']);
        $this->assertSame(20830130, (int) $first['end_bs']);
        $this->assertDatabaseHas('documents', ['id' => $first['document_id'], 'type' => 'sale']);
        $this->assertSame(150000, (int) DB::table('documents')->where('id', $first['document_id'])->value('total_paisa'));
        // Editing the plan price never reprices the sold membership.
        $this->patchJson($s['url'].'/gym/plans/'.$s['plan']['id'], ['mutation_uuid' => (string) Str::uuid(), 'version' => 1, 'name' => 'Monthly', 'item_id' => $s['item'], 'fee' => '2000', 'duration_days' => 30])->assertCreated();
        $this->assertSame('150000', json_decode(DB::table('gym_memberships')->value('plan_snapshot'), true)['fee_paisa']);
        $renewed = $this->join($s, '20830120');
        $this->assertSame(20830131, (int) $renewed['memberships'][0]['start_bs']);
    }

    public function test_checkin_needs_active_membership_counts_once_per_day_and_respects_visit_limit(): void
    {
        $s = $this->gym();
        $check = fn ($date) => $this->postJson($s['url'].'/gym/checkins', ['mutation_uuid' => (string) Str::uuid(), 'member_id' => $s['member']['id'], 'business_date_bs' => $date]);
        $check(20830101)->assertUnprocessable();
        $this->join($s);
        $check(20830101)->assertCreated();
        $check(20830101)->assertUnprocessable();
        $check(20830102)->assertCreated();
        $check(20830103)->assertUnprocessable();
        $this->assertSame(2, (int) DB::table('gym_memberships')->value('visits_used'));
    }

    public function test_class_capacity_and_duplicate_booking_are_enforced(): void
    {
        $s = $this->gym();
        $this->join($s);
        $class = $this->postJson($s['url'].'/gym/classes', ['mutation_uuid' => (string) Str::uuid(), 'name' => 'Yoga', 'business_date_bs' => 20830105, 'start_minute' => 420, 'duration_minutes' => 60, 'capacity' => 1])->assertCreated()->json('data');
        $booking = $this->postJson($s['url'].'/gym/classes/'.$class['id'].'/bookings', ['mutation_uuid' => (string) Str::uuid(), 'member_id' => $s['member']['id']])->assertCreated()->json('data');
        $this->postJson($s['url'].'/gym/classes/'.$class['id'].'/bookings', ['mutation_uuid' => (string) Str::uuid(), 'member_id' => $s['member']['id']])->assertUnprocessable();
        $other = $this->postJson($s['url'].'/gym/members', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'name' => 'Second Member'])->assertCreated()->json('data');
        $this->postJson($s['url'].'/gym/classes/'.$class['id'].'/bookings', ['mutation_uuid' => (string) Str::uuid(), 'member_id' => $other['id']])->assertUnprocessable();
        $this->postJson($s['url'].'/gym/bookings/'.$booking['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(0, (int) DB::table('gym_classes')->value('booked'));
    }

    public function test_membership_cancel_requires_invoice_reversal_and_other_types_cannot_use_gym(): void
    {
        $s = $this->gym();
        $member = $this->join($s);
        $membership = $member['memberships'][0];
        $this->postJson($s['url'].'/gym/memberships/'.$membership['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1])->assertUnprocessable();
        $document = $this->getJson($s['url'].'/document/'.$membership['document_id'])->assertOk()->json('data');
        $this->postJson($s['url'].'/payments/'.$document['payments'][0]['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Refund membership fee'])->assertCreated();
        $this->postJson($s['url'].'/document/'.$membership['document_id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830102, 'reason' => 'Cancel membership bill'])->assertCreated();
        $this->postJson($s['url'].'/gym/memberships/'.$membership['id'].'/cancel', ['mutation_uuid' => (string) Str::uuid(), 'version' => 1])->assertCreated();
        $this->assertDatabaseHas('gym_memberships', ['id' => $membership['id'], 'status' => 'cancelled']);

        $shop = $this->postJson('/api/businesses', ['name' => 'Plain shop'])->assertCreated()->json('data');
        $this->getJson('/api/app/'.$shop['slug'].'/gym/plans')->assertForbidden();
    }
}
