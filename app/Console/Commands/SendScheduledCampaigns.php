<?php

namespace App\Console\Commands;

use App\Services\Mail\CampaignSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:send-scheduled-campaigns')]
#[Description('Queue every scheduled email campaign (tenant or platform) whose send time has arrived')]
class SendScheduledCampaigns extends Command
{
    public function handle(CampaignSender $sender): int
    {
        $count = $sender->dispatchDue();

        $this->info("Queued {$count} campaign(s).");

        return self::SUCCESS;
    }
}
