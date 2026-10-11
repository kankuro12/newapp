<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobCardTest extends TestCase
{
    use RefreshDatabase;

    private function garage(): array
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Ring road garage', 'business_type' => 'garage'])->assertCreated()->assertJsonPath('data.pos_profile', 'garage')->json('data');
        $base = '/api/app/'.$business['slug'];
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $party = $this->postJson($base.'/contacts', ['name' => 'Car owner', 'is_customer' => true, 'is_supplier' => false])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Servicing labour', 'kind' => 'service', 'unit_label' => 'job', 'sale_price' => '1500'])->assertCreated()->json('data.id');

        return compact('base', 'party', 'item');
    }

    private function job(array $s, array $details): array
    {
        return ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $s['party'], 'business_date_bs' => 20830102, 'niche' => 'garage', 'title' => 'Full service',
            'details' => $details, 'lines' => [['item_id' => $s['item'], 'qty' => '1', 'unit_price' => '1500', 'tax_bps' => 0, 'tax_category' => 'outside_scope']], 'expected_total_paisa' => '150000'];
    }

    public function test_garage_job_cards_keep_vehicle_details_and_find_service_history(): void
    {
        $s = $this->garage();
        $this->assertSame('vehicle_no', $this->getJson($s['base'].'/workflows/niches')->assertOk()->json('data.garage.0.key'));
        $first = $this->postJson($s['base'].'/workflows', $this->job($s, ['vehicle_no' => 'Ba 2 Pa 1234', 'make_model' => 'Suzuki Swift', 'odometer_km' => '45210']))->assertCreated()->json('data');
        $this->assertSame('BA2PA1234', $first['lookup_key']);
        $this->assertSame('45210', $first['details']['odometer_km']);
        $second = $this->postJson($s['base'].'/workflows', $this->job($s, ['vehicle_no' => 'BA2PA1234', 'odometer_km' => '50100']))->assertCreated()->json('data');
        $this->assertSame(1, $second['history_count']);
        $this->assertCount(2, $this->getJson($s['base'].'/workflows?lookup=ba-2-pa-1234')->assertOk()->json('data'));
        $this->assertCount(2, $this->getJson($s['base'].'/workflows?q='.urlencode('ba 2 pa 1234'))->assertOk()->json('data'));
    }

    public function test_iron_works_business_records_grill_job_with_size_weight_and_installation(): void
    {
        $this->actingAs(User::factory()->create(), 'tenant');
        $business = $this->postJson('/api/businesses', ['name' => 'Bagmati iron works', 'business_type' => 'fabrication'])->assertCreated()->assertJsonPath('data.pos_profile', 'fabrication')->json('data');
        $base = '/api/app/'.$business['slug'];
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [], 'parties' => []])->assertCreated();
        $s = ['base' => $base, 'party' => $this->postJson($base.'/contacts', ['name' => 'House owner', 'is_customer' => true, 'is_supplier' => false])->assertCreated()->json('data.id'),
            'item' => $this->postJson($base.'/items', ['name' => 'Grill fabrication per sq ft', 'kind' => 'service', 'unit_label' => 'sq ft', 'sale_price' => '450'])->assertCreated()->json('data.id')];
        $job = $this->postJson($base.'/workflows', [...$this->job($s, ['product' => 'Main gate', 'size' => '12 × 6', 'area_sqft' => '72', 'weight_kg' => '180', 'material' => '1" square pipe 16G', 'finish' => 'Powder coat', 'site' => 'Lalitpur', 'install_bs' => '20830115']), 'niche' => 'fabrication'])
            ->assertCreated()->json('data');
        $this->assertSame('Main gate', $job['details']['product']);
        $this->assertSame('180', $job['details']['weight_kg']);
        $this->assertNull($job['lookup_key']);
        $this->assertSame('size', $this->getJson($base.'/workflows/niches')->json('data.fabrication.1.key'));
    }

    public function test_job_details_must_match_the_job_type_and_never_take_passwords(): void
    {
        $s = $this->garage();
        $this->postJson($s['base'].'/workflows', $this->job($s, ['device_password' => '1234']))->assertUnprocessable();
        $this->postJson($s['base'].'/workflows', $this->job($s, ['vehicle_no' => str_repeat('A', 31)]))->assertUnprocessable();
        $this->postJson($s['base'].'/workflows', [...$this->job($s, ['imei_serial' => '356938035643809']), 'niche' => 'repair'])->assertCreated()->assertJsonPath('data.lookup_key', '356938035643809');
    }
}
