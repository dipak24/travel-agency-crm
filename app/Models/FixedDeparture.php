<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class FixedDeparture extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::saving(function (FixedDeparture $departure): void {
            if (! Package::query()->whereKey($departure->package_id)->exists()) {
                throw new LogicException('The package must belong to the current tenant.');
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'package_id', 'start_date', 'end_date', 'total_slots',
        'overbooking_buffer', 'booked_slots', 'price_override', 'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_slots' => 'integer',
            'overbooking_buffer' => 'integer',
            'booked_slots' => 'integer',
            'price_override' => 'integer',
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

    public function remainingSlots(): int
    {
        return max(0, $this->total_slots + $this->overbooking_buffer - $this->booked_slots);
    }
}
