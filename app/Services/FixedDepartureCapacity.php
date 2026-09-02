<?php

namespace App\Services;

use App\Models\FixedDeparture;
use Illuminate\Support\Facades\DB;
use LogicException;

class FixedDepartureCapacity
{
    public function reserve(FixedDeparture $departure, int $pax): FixedDeparture
    {
        if ($pax < 1) {
            throw new LogicException('At least one slot must be reserved.');
        }

        return DB::transaction(function () use ($departure, $pax): FixedDeparture {
            $lockedDeparture = FixedDeparture::query()
                ->whereKey($departure->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedDeparture->status === 'cancelled' || $lockedDeparture->status === 'closed') {
                throw new LogicException('This fixed departure is not bookable.');
            }

            if ($lockedDeparture->booked_slots + $pax > $lockedDeparture->total_slots + $lockedDeparture->overbooking_buffer) {
                throw new LogicException('The fixed departure has insufficient capacity.');
            }

            $lockedDeparture->booked_slots += $pax;
            $lockedDeparture->status = $lockedDeparture->booked_slots >= $lockedDeparture->total_slots ? 'full' : 'open';
            $lockedDeparture->save();

            return $lockedDeparture;
        });
    }
}
