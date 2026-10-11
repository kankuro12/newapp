<?php

namespace App\Jobs;

use App\Service\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One FCM send per device, so one slow or failing device cannot hold up the rest of a fan-out.
 */
class SendPushToDevice implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $deliveryId, public int $deviceId)
    {
        $this->afterCommit();
    }

    public function handle(NotificationService $notifications): void
    {
        $notifications->pushToDevice($this->deliveryId, $this->deviceId);
    }
}
