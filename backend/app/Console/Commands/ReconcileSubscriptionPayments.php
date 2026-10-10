<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileSubscriptionPayment;
use App\Service\BillingService;
use Illuminate\Console\Command;

class ReconcileSubscriptionPayments extends Command
{
    protected $signature = 'app:reconcile-subscription-payments {--limit=10 : Maximum due attempts per run (1-100)}';

    protected $description = 'Verify unresolved subscription payments without initiating replacement charges';

    public function handle(BillingService $billing): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
            $this->error('Limit must be between 1 and 100.');

            return self::INVALID;
        }
        $counts = ['queued' => 0, 'failed' => 0];
        foreach ($billing->reconciliationCandidates((int) $limit) as $id) {
            try {
                ReconcileSubscriptionPayment::dispatch((int) $id);
                $counts['queued']++;
            } catch (\Throwable) {
                $counts['failed']++;
            }
        }
        $this->info('Queued: '.$counts['queued'].'; dispatch failed: '.$counts['failed'].'.');

        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
