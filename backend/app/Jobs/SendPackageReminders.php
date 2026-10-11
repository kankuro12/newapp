<?php

namespace App\Jobs;

use App\Service\ReminderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendPackageReminders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 80;

    public int $uniqueFor = 3600;

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Re-running is safe: reminder deliveries are keyed by subscription and threshold.
     */
    public function handle(ReminderService $reminders): void
    {
        $reminders->queueDue();
    }
}
