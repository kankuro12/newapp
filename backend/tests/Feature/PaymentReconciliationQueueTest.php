<?php

namespace Tests\Feature;

use App\Jobs\ReconcileSubscriptionPayment;
use App\Service\BillingService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentReconciliationQueueTest extends TestCase
{
    public function test_schedule_enqueues_ids_without_verifying_providers_in_scheduler(): void
    {
        Queue::fake();
        $this->mock(BillingService::class, function ($mock) {
            $mock->shouldReceive('reconciliationCandidates')->once()->with(10)->andReturn([11, 12]);
            $mock->shouldNotReceive('reconcile');
        });
        $this->artisan('app:reconcile-subscription-payments')->assertSuccessful();
        Queue::assertPushed(ReconcileSubscriptionPayment::class, 2);
        Queue::assertPushed(ReconcileSubscriptionPayment::class, fn ($job) => $job->attemptId === 11 && $job->afterCommit === true);
    }
}
