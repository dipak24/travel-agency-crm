<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'package_code', 'description', 'itinerary',
        'base_price', 'sales_price', 'duration_days', 'is_public', 'status',
    ];

    protected function casts(): array
    {
        return [
            'itinerary' => 'array',
            'base_price' => 'integer',
            'sales_price' => 'integer',
            'is_public' => 'boolean',
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

    public function discountTiers(): HasMany
    {
        return $this->hasMany(GroupDiscountTier::class);
    }
}
