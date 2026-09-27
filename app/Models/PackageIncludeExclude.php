<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\PublicCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One catalog inclusion/exclusion item on a package. `is_included` items are covered by the
 * package's base price; the rest are listed as excluded but can be added to a booking for a charge.
 */
class PackageIncludeExclude extends Model
{
    use BelongsToTenant;

    protected $table = 'package_include_exclude';

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if (! Package::query()->whereKey($item->package_id)->exists()
                || ! IncludeExclude::query()->whereKey($item->include_exclude_id)->exists()) {
                throw new LogicException('The package and catalog item must belong to the current tenant.');
            }
        });

        $flushPublicCatalog = fn (self $item) => app(PublicCatalogCache::class)->flush($item->tenant_id);

        static::saved($flushPublicCatalog);
        static::deleted($flushPublicCatalog);
    }

    protected $fillable = [
        'tenant_id', 'package_id', 'include_exclude_id', 'is_included', 'unit_price_override', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_included' => 'boolean',
            'unit_price_override' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function includeExclude(): BelongsTo
    {
        return $this->belongsTo(IncludeExclude::class);
    }

    public function unitPrice(): int
    {
        return $this->unit_price_override ?? $this->includeExclude?->unit_price ?? 0;
    }
}
