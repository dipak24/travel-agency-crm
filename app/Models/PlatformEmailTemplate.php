<?php

namespace App\Models;

use App\Models\Concerns\GuardsEmailTemplateUsage;
use App\Support\SystemEmailTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A Super Admin-authored email template: either a marketing template for the platform's own
 * campaigns (active/draft/archived), or (`category = system`, with a `key`) the editable copy of
 * one platform system email such as the admin/staff password reset — always active, content-only
 * edits. See App\Support\SystemEmailTypes and GuardsEmailTemplateUsage.
 */
class PlatformEmailTemplate extends Model
{
    use GuardsEmailTemplateUsage;

    protected $fillable = ['key', 'category', 'name', 'subject', 'body_html', 'status', 'created_by'];

    protected static function booted(): void
    {
        // Only EmailTemplates::seedSystemTemplates() makes system rows (always with a known key) —
        // a marketing template can never be turned into, or forged as, a system email.
        static::saving(function (PlatformEmailTemplate $template): void {
            if ($template->isSystem() !== array_key_exists((string) $template->key, SystemEmailTypes::all())) {
                throw new LogicException('System email templates are managed by the platform and can\'t be created or re-categorised by hand.');
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'created_by');
    }

    public function isSystem(): bool
    {
        return $this->category === SystemEmailTypes::CATEGORY;
    }

    public function isAlwaysOn(): bool
    {
        return $this->isSystem();
    }

    /**
     * @return Builder<EmailCampaign>
     */
    public function campaignsUsingThisTemplate(): Builder
    {
        return EmailCampaign::query()->forPlatform()->where('template_id', $this->getKey());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeSystem(Builder $query): void
    {
        $query->where('category', SystemEmailTypes::CATEGORY);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeMarketing(Builder $query): void
    {
        $query->where('category', '!=', SystemEmailTypes::CATEGORY);
    }
}
