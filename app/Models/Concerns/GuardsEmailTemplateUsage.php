<?php

namespace App\Models\Concerns;

use App\Models\EmailCampaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Integrity rules shared by tenant (EmailTemplate) and platform (PlatformEmailTemplate) templates,
 * enforced at the model level so no code path — UI, tinker, a future API — can bypass them:
 *
 * - A transactional/system template is always `active` (an email the app must send can't be
 *   switched off), and can never be deleted. Only its subject/body are editable.
 * - A marketing template used by a scheduled/queued/sending campaign is locked: a running
 *   campaign re-reads its template for every batch, so a mid-run edit or deactivation would send
 *   different content to later recipients, or fail the campaign halfway.
 * - A marketing template referenced by any campaign can't be deleted — archive it instead.
 *
 * The using model implements isAlwaysOn() and campaignsUsingThisTemplate().
 */
trait GuardsEmailTemplateUsage
{
    /**
     * Fields a running campaign depends on.
     */
    private const CAMPAIGN_CONTENT_FIELDS = ['subject', 'body_html', 'status'];

    abstract public function isAlwaysOn(): bool;

    /**
     * @return Builder<EmailCampaign>
     */
    abstract public function campaignsUsingThisTemplate(): Builder;

    protected static function bootGuardsEmailTemplateUsage(): void
    {
        static::saving(function (Model $template): void {
            if ($template->isAlwaysOn()) {
                $template->setAttribute('status', 'active');
            }
        });

        static::updating(function (Model $template): void {
            if (! $template->isAlwaysOn() && $template->isDirty(self::CAMPAIGN_CONTENT_FIELDS) && $template->isLockedByRunningCampaign()) {
                throw new LogicException('This template is used by a scheduled or sending campaign and can\'t be changed until it finishes.');
            }
        });

        static::deleting(function (Model $template): void {
            if (! $template->isDeletable()) {
                throw new LogicException('This template can\'t be deleted — it\'s either a required email or used by a campaign. Archive it instead.');
            }
        });
    }

    public function isLockedByRunningCampaign(): bool
    {
        return ! $this->isAlwaysOn()
            && $this->campaignsUsingThisTemplate()->whereIn('status', EmailCampaign::RUNNING_STATUSES)->exists();
    }

    public function isDeletable(): bool
    {
        return ! $this->isAlwaysOn() && ! $this->campaignsUsingThisTemplate()->exists();
    }
}
