<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\PublicCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An agency's public website configuration (tenant Settings → Website): theme, contact details,
 * whether the site is published, and an optional custom domain. The agency is always reachable on
 * its own subdomain ({slug}.{agency.domain}); a custom domain is only served once its DNS TXT
 * record has been verified (see App\Services\CustomDomainVerifier).
 */
class PublicLeadPage extends Model
{
    use BelongsToTenant;

    public const VERIFICATION_RECORD_PREFIX = '_travelcrm-verification';

    /**
     * @var array<string, string>
     */
    public const THEMES = [
        'classic' => 'Classic — full-width hero image, centred headline',
        'modern' => 'Modern — split hero, bold typography',
        'minimal' => 'Minimal — text-first, lots of white space',
    ];

    protected $fillable = [
        'custom_domain',
        'theme',
        'contact_settings',
        'is_active',
    ];

    protected static function booted(): void
    {
        // A new or changed domain must prove ownership again before it is served.
        static::saving(function (PublicLeadPage $page): void {
            if ($page->isDirty('custom_domain')) {
                $page->custom_domain = filled($page->custom_domain) ? Str::lower(trim($page->custom_domain)) : null;
                $page->domain_verified_at = null;
                $page->domain_verification_token = $page->custom_domain === null ? null : Str::random(40);
            }
        });

        // The public API's /site response is cached per tenant.
        static::saved(fn (PublicLeadPage $page) => app(PublicCatalogCache::class)->flush($page->tenant_id));
        static::deleted(fn (PublicLeadPage $page) => app(PublicCatalogCache::class)->flush($page->tenant_id));
    }

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'contact_settings' => 'array',
            'is_active' => 'boolean',
            'domain_verified_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function hasVerifiedDomain(): bool
    {
        return $this->custom_domain !== null && $this->domain_verified_at !== null;
    }

    /**
     * The DNS name the agency adds a TXT record to, e.g. _travelcrm-verification.trips.example.com.
     */
    public function verificationRecordName(): ?string
    {
        return $this->custom_domain === null ? null : self::VERIFICATION_RECORD_PREFIX.'.'.$this->custom_domain;
    }

    public function verificationRecordValue(): ?string
    {
        return $this->domain_verification_token === null ? null : 'travelcrm-site-verification='.$this->domain_verification_token;
    }
}
