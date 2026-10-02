<?php

namespace Tests\Feature;

use App\Models\User;
use App\NepaliDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentPostingTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_cannot_spend_same_cash_and_database_rejects_cross_tenant_fk(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner, 'tenant');
        $tenant = $this->postJson('/api/businesses', ['name' => 'Concurrent shop'])->assertCreated()->json('data');
        $url = '/api/app/'.$tenant['slug'];
        $cash = $this->getJson($url.'/lookup')->json('data.accounts.0.id');
        $date = NepaliDate::today();
        $this->postJson($url.'/settings/opening-balances/finalize', ['mutation_uuid' => (string) Str::uuid(), 'business_date_bs' => $date, 'money' => [['account_id' => $cash, 'amount' => '2']], 'stock' => [], 'parties' => []])->assertCreated();
        $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => 'localhost', 'DB_PORT' => '3306', 'DB_DATABASE' => 'business_book_testing', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $processes[] = new Process(['php84', 'tests/Support/concurrent-worker.php', $tenant['id'], (string) $owner->id, $cash, (string) $date, (string) Str::uuid()], base_path(), $environment, timeout: 30);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        } sort($results);
        $this->assertSame(['insufficient', 'posted'], $results);
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'owner')->count());
        $regular = $this->postJson($url.'/regular-expenses', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'salary', 'label' => 'Race salary', 'payee_name' => 'Employee', 'amount' => '1', 'first_date_bs' => $date, 'monthly_day' => $date % 100, 'auto_generate' => true, 'enabled' => true])->assertCreated()->json('data.id');
        $processes = [];
        foreach (['auto', 'manual'] as $mode) {
            $processes[] = new Process(['php84', 'tests/Support/concurrent-worker.php', $tenant['id'], (string) $owner->id, $cash, (string) $date, (string) Str::uuid(), $regular, $mode], base_path(), $environment, timeout: 30);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('recorded', $process->getOutput());
        }
        $this->assertSame(1, DB::table('recurring_expense_occurrences')->count());
        $this->assertSame(1, DB::table('documents')->where('type', 'expense')->count());
        $resource = $this->postJson($url.'/pos/resources', ['mutation_uuid' => (string) Str::uuid(), 'kind' => 'staff', 'name' => 'Concurrent chair', 'start_minute' => 540, 'end_minute' => 1200])->assertCreated()->json('data.id');
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $processes[] = new Process(['php84', 'tests/Support/concurrent-worker.php', $tenant['id'], (string) $owner->id, $cash, (string) $date, (string) Str::uuid(), $resource, 'appointment'], base_path(), $environment, timeout: 30);
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
        $this->assertSame(['booked', 'occupied'], $results);
        $this->assertSame(1, DB::table('appointments')->count());
        $other = $this->postJson('/api/businesses', ['name' => 'Other shop'])->assertCreated()->json('data.id');
        try {
            DB::table('appointments')->insert(['tenant_id' => $other, 'resource_id' => $resource, 'business_date_bs' => $date, 'start_minute' => 600, 'end_minute' => 630, 'client_name' => 'Foreign resource', 'services' => '[]', 'created_by' => $owner->id]);
            $this->fail('Appointment accepted foreign branch resource.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }
        try {
            DB::table('journal_lines')->insert(['tenant_id' => $other, 'journal_entry_id' => DB::table('journal_entries')->value('id'), 'account_id' => $cash, 'debit_paisa' => 1, 'credit_paisa' => 0]);
            $this->fail('Composite tenant FK accepted foreign row.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }
        try {
            DB::table('recurring_expense_occurrences')->insert(['tenant_id' => $other, 'recurring_expense_id' => $regular, 'period_bs' => NepaliDate::monthRange($date)[0], 'document_id' => DB::table('documents')->value('id')]);
            $this->fail('Occurrence accepted foreign rule/document.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }
    }
}
