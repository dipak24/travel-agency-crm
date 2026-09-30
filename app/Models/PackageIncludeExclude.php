<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\PublicCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One catalog inclusion/exclusion item picked for a package. `is_included` items are covered by the
 * package's base price; the rest are listed as excluded but can be added to a booking for a charge.
 * Title, description and unit always come from the catalog item; `unit_price` is the package's own
 * price, copied from the catalog when the item is picked, so later catalog price changes don't
 * change the package.
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

        static::creating(function (self $item): void {
            $item->unit_price ??= (int) IncludeExclude::query()->whereKey($item->include_exclude_id)->value('unit_price');
        });

        $flushPublicCatalog = fn (self $item) => app(PublicCatalogCache::class)->flush($item->tenant_id);

        static::saved($flushPublicCatalog);
        static::deleted($flushPublicCatalog);
    }

    protected $fillable = [
        'tenant_id', 'package_id', 'include_exclude_id', 'is_included', 'unit_price', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_included' => 'boolean',
            'unit_price' => 'integer',
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
}
