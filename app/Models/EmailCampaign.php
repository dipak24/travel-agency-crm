<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mass email, owned either by a tenant (`owner_type = tenant`, audience: its own customers or
 * staff, template: one of its marketing EmailTemplates) or by the platform (`owner_type =
 * platform`, audience: tenants or saas_leads, template: a PlatformEmailTemplate). Not
 * BelongsToTenant, since platform campaigns have no tenant — tenant-panel queries must scope
 * with forTenant() explicitly.
 */
class EmailCampaign extends Model
{
    public const OWNER_TENANT = 'tenant';

    public const OWNER_PLATFORM = 'platform';

    /**
     * Statuses in which the campaign still depends on its template's current content.
     */
    public const RUNNING_STATUSES = ['scheduled', 'queued', 'sending'];

    protected $fillable = [
        'owner_type', 'tenant_id', 'template_id', 'name', 'subject_override', 'audience_type',
        'audience_filter', 'scheduled_at', 'status', 'total_recipients', 'sent_count', 'failed_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'audience_filter' => 'array',
            'scheduled_at' => 'datetime',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForTenant(Builder $query, int $tenantId): void
    {
        $query->where('owner_type', self::OWNER_TENANT)->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForPlatform(Builder $query): void
    {
        $query->where('owner_type', self::OWNER_PLATFORM);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class, 'campaign_id');
    }

    public function isPlatform(): bool
    {
        return $this->owner_type === self::OWNER_PLATFORM;
    }

    /**
     * Only a campaign that hasn't started sending can still be edited, rescheduled, or deleted.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }
}
