<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FulfilmentConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        try {
            if ($this->app && Schema::hasTable('workflow_fulfilments') && DB::table('workflow_fulfilments')->exists()) {
                $this->assertSame('business_book_testing', DB::connection()->getDatabaseName());
                // Production rollback preserves history; remove only the isolated race fixture by refreshing its test database.
                $this->artisan('migrate:fresh')->assertExitCode(0);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_processes_cannot_deliver_the_same_remaining_order_quantity(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Concurrent delivery'])->assertCreated()->json('data');
        $base = '/api/app/'.$tenant['slug'];
        $party = $this->postJson($base.'/contacts', ['name' => 'Race customer', 'is_customer' => true, 'is_supplier' => false])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Race stock', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [['item_id' => $item, 'qty' => '10', 'value' => '50']], 'parties' => []])->assertCreated();
        $order = $this->postJson($base.'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $party, 'business_date_bs' => 20830102, 'lines' => [['item_id' => $item, 'qty' => '3.501', 'unit_price' => '100']], 'expected_total_paisa' => '35010'])->assertCreated()->json('data');
        $input = ['version' => $order['version'], 'business_date_bs' => 20830102, 'lines' => [['position' => 1, 'qty' => '3.501']]];
        $preview = $this->postJson($base.'/workflow/'.$order['id'].'/fulfilments/preview', $input)->assertOk()->json('data');
        $payload = json_encode([...$input, 'expected_fingerprint' => $preview['fingerprint']], JSON_THROW_ON_ERROR);
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => 'business_book_testing', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $processes[] = new Process(['php84', 'tests/Support/concurrent-worker.php', $tenant['id'], (string) $owner->id, '0', '20830102', (string) Str::uuid(), $order['id'], 'fulfilment', $payload], base_path(), $environment, timeout: 30);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        }
        sort($results);
        $this->assertSame(['conflict', 'fulfilled'], $results);
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'fulfilment')->count());
        $this->assertSame(0, DB::table('documents')->count());
        $this->getJson($base.'/items/'.$item)->assertOk()->assertJsonPath('data.qty_milli', '6499')->assertJsonPath('data.value_paisa', '3249');
        $this->getJson($base.'/workflow/'.$order['id'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '0');
        $sourceLine = DB::table('workflow_fulfilment_lines')->value('id');
        $billInput = ['version' => 2, 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['fulfilment_line_id' => $sourceLine, 'qty' => '3.501']]];
        $billPreview = $this->postJson($base.'/workflow/'.$order['id'].'/staged-bills/preview', $billInput)->assertOk()->json('data');
        $billPayload = json_encode([...$billInput, 'expected_fingerprint' => $billPreview['fingerprint'], 'expected_total_paisa' => '35010'], JSON_THROW_ON_ERROR);
        $billProcesses = [];
        for ($i = 0; $i < 2; $i++) {
            $billProcesses[] = new Process(['php84', 'tests/Support/concurrent-worker.php', $tenant['id'], (string) $owner->id, '0', '20830102', (string) Str::uuid(), $order['id'], 'staged_bill', $billPayload], base_path(), $environment, timeout: 30);
        }
        foreach ($billProcesses as $process) {
            $process->start();
        }
        $billResults = [];
        foreach ($billProcesses as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $billResults[] = $process->getOutput();
        }
        sort($billResults);
        $this->assertSame(['billed', 'conflict'], $billResults);
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(1, DB::table('workflow_bill_allocations')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
    }
}
