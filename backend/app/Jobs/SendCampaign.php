<?php

namespace App\Jobs;

use App\Service\CampaignService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCampaign implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 80;

    public int $uniqueFor = 3600;

    public function __construct(public int $campaignId, public int $offset = 0)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'campaign:'.$this->campaignId.':'.$this->offset;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Re-running is safe: each recipient/channel delivery is keyed by the campaign.
     */
    public function handle(CampaignService $campaigns): void
    {
        $campaigns->send($this->campaignId, $this->offset);
    }
}
