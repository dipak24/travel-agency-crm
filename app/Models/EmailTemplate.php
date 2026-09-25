<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GuardsEmailTemplateUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A tenant's own email template: either its editable copy of a transactional type
 * (`category = transactional`, one per type — always active, content-only edits) or a marketing
 * template used by campaigns (active/draft/archived). See GuardsEmailTemplateUsage for the rules.
 */
class EmailTemplate extends Model
{
    use BelongsToTenant, GuardsEmailTemplateUsage;

    public const CATEGORY_TRANSACTIONAL = 'transactional';

    public const CATEGORY_MARKETING = 'marketing';

    protected $fillable = [
        'tenant_id', 'email_template_type_id', 'category', 'name', 'subject', 'body_html', 'status', 'updated_by',
    ];

    protected static function booted(): void
    {
        // A transactional row always belongs to exactly one type and a marketing row to none, so
        // neither can be turned into (or forged as) the other.
        static::saving(function (EmailTemplate $template): void {
            if ($template->isTransactional() === ($template->email_template_type_id === null)) {
                throw new LogicException('Transactional templates are seeded per email type and can\'t be created or re-categorised by hand.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EmailTemplateType::class, 'email_template_type_id');
    }

    public function isTransactional(): bool
    {
        return $this->category === self::CATEGORY_TRANSACTIONAL;
    }

    public function isAlwaysOn(): bool
    {
        return $this->isTransactional();
    }

    /**
     * @return Builder<EmailCampaign>
     */
    public function campaignsUsingThisTemplate(): Builder
    {
        return EmailCampaign::query()->forTenant((int) $this->tenant_id)->where('template_id', $this->getKey());
    }
}
