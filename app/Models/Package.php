<?php

namespace App\Models;

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
        'base_price', 'sales_price', 'duration_days', 'status', 'category',
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'integer',
            'sales_price' => 'integer',
            'duration_days' => 'integer',
            'category' => PackageCategory::class,
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
        return $this->belongsToMany(Service::class)->withPivot(['price', 'is_featured']);
    }

    public function promoCodes(): BelongsToMany
    {
        return $this->belongsToMany(PromoCode::class);
    }

    /**
     * Only published packages appear on the agency's public website and booking pages.
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * The package itinerary (free-text HTML), used as the starting point for a booking's own
     * itinerary (which CST can then change for that booking only).
     */
    public function itineraryHtml(): ?string
    {
        return blank($this->itinerary) ? null : $this->itinerary;
    }
}
