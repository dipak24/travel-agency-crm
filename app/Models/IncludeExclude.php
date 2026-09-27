<?php

namespace App\Models;

use App\Enums\PricingUnit;
use App\Models\Concerns\BelongsToTenant;
use App\Services\PublicCatalogCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncludeExclude extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Item titles appear in the public API's package detail, so a catalog edit must refresh it.
     */
    protected static function booted(): void
    {
        $flushPublicCatalog = fn (self $item) => app(PublicCatalogCache::class)->flush($item->tenant_id);

        static::saved($flushPublicCatalog);
        static::deleted($flushPublicCatalog);
    }

    protected $fillable = ['tenant_id', 'type', 'title', 'description', 'unit_price', 'pricing_unit', 'sort_order'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'pricing_unit' => PricingUnit::class,
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
