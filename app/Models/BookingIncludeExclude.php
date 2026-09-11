<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingIncludeExclude extends Model
{
    use BelongsToTenant;

    protected $table = 'booking_include_exclude';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'include_exclude_id',
        'type',
        'title',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function includeExclude(): BelongsTo
    {
        return $this->belongsTo(IncludeExclude::class);
    }

    /**
     * Reconciles a booking's include/exclude rows against a checked set of catalog item ids —
     * used by BookingResource's checkbox-list selector instead of the old freeform repeater.
     * Picking a catalog item snapshots its title/description/type onto the booking; editing the
     * catalog item later does not retroactively change past bookings.
     *
     * @param  array<int, int|string>  $catalogIds
     */
    public static function syncForBooking(Booking $booking, array $catalogIds): void
    {
        $catalogIds = array_map('intval', $catalogIds);

        $existing = $booking->includeExcludes()->whereNotNull('include_exclude_id')->get();

        foreach ($existing as $row) {
            if (! in_array($row->include_exclude_id, $catalogIds, true)) {
                $row->delete();
            }
        }

        $toAdd = array_diff($catalogIds, $existing->pluck('include_exclude_id')->all());

        if ($toAdd === []) {
            return;
        }

        foreach (IncludeExclude::query()->whereIn('id', $toAdd)->get() as $item) {
            $booking->includeExcludes()->create([
                'include_exclude_id' => $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'description' => $item->description,
            ]);
        }
    }
}
