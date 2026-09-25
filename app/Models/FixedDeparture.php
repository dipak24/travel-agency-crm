<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\PublicCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

        $flushPublicCatalog = fn (FixedDeparture $departure) => app(PublicCatalogCache::class)->flush($departure->tenant_id);

        static::saved($flushPublicCatalog);
        static::deleted($flushPublicCatalog);
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

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(BookingWaitlist::class);
    }

    public function remainingSlots(): int
    {
        return max(0, $this->total_slots + $this->overbooking_buffer - $this->booked_slots);
    }

    /**
     * Seats offered on the public website. Unlike remainingSlots(), this excludes the overbooking
     * buffer, which is headroom for staff discretion rather than advertised capacity.
     */
    public function publicSeatsRemaining(): int
    {
        return $this->status === 'full' ? 0 : max(0, $this->total_slots - $this->booked_slots);
    }
}
