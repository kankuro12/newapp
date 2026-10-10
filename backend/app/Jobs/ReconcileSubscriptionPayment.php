<?php

namespace App\Jobs;

use App\Service\BillingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileSubscriptionPayment implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(public int $attemptId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'subscription-payment:'.$this->attemptId;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(BillingService $billing): void
    {
        $billing->reconcile($this->attemptId);
    }
}
