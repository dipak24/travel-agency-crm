<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GroupDiscountTier extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (GroupDiscountTier $tier): void {
            if ($tier->package_id !== null && ! Package::query()->whereKey($tier->package_id)->exists()) {
                throw new LogicException('The package must belong to the current tenant.');
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'package_id', 'min_pax', 'max_pax', 'discount_type',
        'discount_value',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
