<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupDiscountTier extends Model
{
    use BelongsToTenant;

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
