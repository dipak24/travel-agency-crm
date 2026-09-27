<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\PackageCategory;
use App\Models\Concerns\BelongsToTenant;
use App\Services\PublicCatalogCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use BelongsToTenant, HasFactory;

    protected static function booted(): void
    {
        $flushPublicCatalog = fn (Package $package) => app(PublicCatalogCache::class)->flush($package->tenant_id);

        static::saved($flushPublicCatalog);
        static::deleted($flushPublicCatalog);
    }

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'package_code', 'description', 'itinerary',
        'base_price', 'sales_price', 'duration_days', 'is_public', 'status',
        'category', 'min_pax', 'max_pax', 'document_requirements',
    ];

    protected function casts(): array
    {
        return [
            'itinerary' => 'array',
            'base_price' => 'integer',
            'sales_price' => 'integer',
            'is_public' => 'boolean',
            'duration_days' => 'integer',
            'min_pax' => 'integer',
            'max_pax' => 'integer',
            'category' => PackageCategory::class,
            'document_requirements' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fixedDepartures(): HasMany
    {
        return $this->hasMany(FixedDeparture::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function discountTiers(): HasMany
    {
        return $this->hasMany(GroupDiscountTier::class);
    }

    /**
     * The package's own inclusion/exclusion list: which catalog items are covered by the base
     * price and which are listed as excluded (but can be added to a booking for a charge).
     */
    public function includeExcludeItems(): HasMany
    {
        return $this->hasMany(PackageIncludeExclude::class)->orderBy('sort_order');
    }

    /**
     * Add-on services offered with this package, with optional package-specific prices.
     */
    public function serviceLinks(): HasMany
    {
        return $this->hasMany(PackageService::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withPivot(['price_override', 'is_featured']);
    }

    public function promoCodes(): BelongsToMany
    {
        return $this->belongsToMany(PromoCode::class);
    }

    /**
     * The package itinerary as simple HTML, used as the starting point for a booking's own
     * free-text itinerary (which CST can then change for that booking only).
     */
    public function itineraryHtml(): ?string
    {
        if (blank($this->itinerary)) {
            return null;
        }

        return collect($this->itinerary)
            ->map(fn (array $day, int $index): string => sprintf(
                '<p><strong>Day %d: %s</strong></p><p>%s</p>',
                $index + 1,
                e($day['title'] ?? ''),
                e($day['description'] ?? ''),
            ))
            ->implode('');
    }

    /**
     * Required/optional flag per document type, falling back to the platform defaults for any
     * type the package hasn't set.
     *
     * @return array<string, bool>
     */
    public function documentRequirements(): array
    {
        return DocumentType::normalizeRequirements(array_merge(DocumentType::defaultRequirements(), array_map('boolval', $this->document_requirements ?? [])));
    }
}
