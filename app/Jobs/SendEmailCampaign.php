<?php

namespace App\Jobs;

use App\Services\Mail\CampaignSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one batch of a campaign and re-dispatches itself while recipients remain (see CampaignSender).
 *
 * Mail goes out through TenantMailer, which swaps mail config for each send and restores it in a
 * `finally` — all within this one handle() call — so a reused queue worker can't leak one tenant's
 * SMTP settings into the next job.
 */
class SendEmailCampaign implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $campaignId) {}

    public function handle(CampaignSender $sender): void
    {
        if ($sender->sendBatch($this->campaignId)) {
            self::dispatch($this->campaignId);
        }
    }
}
