<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class FulfilmentBillFirstConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        try {
            if ($this->app && Schema::hasTable('business_workflows') && DB::table('business_workflows')->exists()) {
                $this->assertSame('business_book_testing', DB::connection()->getDatabaseName());
                // History rollback intentionally refuses posted bill-first sources. Refresh only this isolated fixture.
                $this->artisan('migrate:fresh')->assertExitCode(0);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $this->actingAs($actor, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Concurrent prebilling'])->assertCreated()->json('data');
        $base = '/api/app/'.$tenant['slug'];
        $party = $this->postJson($base.'/contacts', ['name' => 'Named race customer', 'is_customer' => true, 'is_supplier' => false])->assertCreated()->json('data.id');
        $item = $this->postJson($base.'/items', ['name' => 'Race stock', 'kind' => 'stock', 'unit_label' => 'kg', 'pos_unit' => 'kg', 'sale_price' => '100'])->assertCreated()->json('data.id');
        $this->postJson($base.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => 20830101, 'money' => [], 'stock' => [['item_id' => $item, 'qty' => '10', 'value' => '500']], 'parties' => []])->assertCreated();
        $order = $this->postJson($base.'/workflows', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'sales_order', 'contact_id' => $party, 'business_date_bs' => 20830102, 'lines' => [['item_id' => $item, 'qty' => '3.501', 'unit_price' => '100']], 'expected_total_paisa' => '35010'])->assertCreated()->json('data');

        return compact('actor', 'tenant', 'base', 'item', 'order');
    }

    private function billInput(array $s): array
    {
        $input = ['version' => 1, 'business_date_bs' => 20830102, 'paid_now' => '0', 'lines' => [['position' => 1, 'qty' => '3.501']]];
        $preview = $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills/preview', $input)->assertOk()->json('data');

        return [...$input, 'expected_total_paisa' => '35010', 'expected_fingerprint' => $preview['fingerprint']];
    }

    private function dispatchInput(array $s, int $line): array
    {
        $input = ['version' => 2, 'business_date_bs' => 20830102, 'handover_confirmed' => true, 'lines' => [['document_line_id' => $line, 'qty' => '3.501']]];
        $preview = $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/billed-fulfilments/preview', $input)->assertOk()->json('data');

        return [...$input, 'expected_fingerprint' => $preview['fingerprint']];
    }

    private function race(array $s, array $actions): array
    {
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => 'business_book_testing', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $processes = [];
        foreach ($actions as [$operation, $id, $input]) {
            $processes[] = new Process(['php84', 'tests/Support/concurrent-worker.php', (string) $s['tenant']['id'], (string) $s['actor']->id, '0', '20830102', (string) Str::uuid(), (string) $id, $operation, json_encode($input, JSON_THROW_ON_ERROR)], base_path(), $environment, timeout: 30);
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

        return $results;
    }

    private function balance(array $s, string $key): int
    {
        $account = DB::table('accounts')->where('tenant_id', $s['tenant']['id'])->where('system_key', $key)->value('id');
        $this->assertNotNull($account);

        return (int) DB::table('journal_lines')->where('tenant_id', $s['tenant']['id'])->where('account_id', $account)->selectRaw('COALESCE(SUM(debit_paisa-credit_paisa),0) AS value')->value('value');
    }

    public function test_two_terminals_cannot_bill_or_dispatch_the_same_remaining_quantity(): void
    {
        $s = $this->fixture();
        $input = $this->billInput($s);
        $results = $this->race($s, array_fill(0, 2, ['ordered_bill', $s['order']['id'], $input]));
        sort($results);
        $this->assertSame(['billed', 'conflict'], $results);
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(1, DB::table('workflow_bill_allocations')->count());
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->assertSame(-35010, $this->balance($s, 'sales_unfulfilled'));
        $line = (int) DB::table('document_lines')->value('id');
        $input = $this->dispatchInput($s, $line);
        $results = $this->race($s, array_fill(0, 2, ['billed_fulfilment', $s['order']['id'], $input]));
        sort($results);
        $this->assertSame(['conflict', 'fulfilled'], $results);
        $this->assertSame(1, DB::table('workflow_fulfilments')->count());
        $this->assertSame(1, DB::table('workflow_dispatch_allocations')->count());
        $this->assertSame(2, DB::table('stock_movements')->count());
        $this->assertSame(0, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame(-35010, $this->balance($s, 'sales'));
        $this->assertSame(17505, $this->balance($s, 'cogs'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', '6499')->assertJsonPath('data.value_paisa', '32495');
        $this->getJson($s['base'].'/workflow/'.$s['order']['id'])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.fulfilment.lines.0.remaining_qty_milli', '0');
    }

    public function test_dispatch_and_unfulfilled_credit_compete_for_the_same_source_capacity(): void
    {
        $s = $this->fixture();
        $bill = $this->postJson($s['base'].'/workflow/'.$s['order']['id'].'/ordered-bills', [...$this->billInput($s), 'mutation_uuid' => (string) Str::uuid()])->assertCreated()->json('data');
        $dispatch = $this->dispatchInput($s, $bill['lines'][0]['id']);
        $credit = ['business_date_bs' => 20830102, 'reason' => 'Concurrent unfulfilled credit', 'lines' => [['source_line_id' => $bill['lines'][0]['id'], 'qty' => '3.501', 'return_source' => 'unfulfilled']]];
        [$deliveryResult, $creditResult] = $this->race($s, [['billed_fulfilment', $s['order']['id'], $dispatch], ['unfulfilled_credit', $bill['id'], $credit]]);
        $this->assertContains([$deliveryResult, $creditResult], [['fulfilled', 'insufficient'], ['conflict', 'credited']]);
        $delivered = $deliveryResult === 'fulfilled';
        $this->assertSame($delivered ? 1 : 0, DB::table('workflow_dispatch_allocations')->count());
        $this->assertSame($delivered ? 0 : 1, DB::table('workflow_return_allocations')->count());
        $this->assertSame($delivered ? 2 : 1, DB::table('stock_movements')->count());
        $this->assertSame(0, $this->balance($s, 'sales_unfulfilled'));
        $this->assertSame($delivered ? 35010 : 0, $this->balance($s, 'receivables'));
        $this->assertSame($delivered ? -35010 : 0, $this->balance($s, 'sales'));
        $this->assertSame($delivered ? 17505 : 0, $this->balance($s, 'cogs'));
        $this->getJson($s['base'].'/items/'.$s['item'])->assertOk()->assertJsonPath('data.qty_milli', $delivered ? '6499' : '10000')->assertJsonPath('data.value_paisa', $delivered ? '32495' : '50000');
        $this->getJson($s['base'].'/document/'.$bill['id'])->assertOk()->assertJsonPath('data.lines.0.unfulfilled_qty_milli', '0');
    }
}
