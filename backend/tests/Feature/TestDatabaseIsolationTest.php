<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class TestDatabaseIsolationTest extends TestCase
{
    public function test_another_process_cannot_claim_the_shared_database_suite_lock(): void
    {
        if (config('database.connections.'.config('database.default').'.database') !== 'business_book_testing') {
            $this->markTestSkipped('Shared MySQL test database lock only.');
        }
        $process = new Process(['php84', '-r', '$handle = fopen($argv[1], "c"); exit(flock($handle, LOCK_EX | LOCK_NB) ? 0 : 7);', storage_path('framework/testing-suite.lock')], base_path(), timeout: 10);
        $process->run();
        $this->assertSame(7, $process->getExitCode(), $process->getErrorOutput());
    }
}
