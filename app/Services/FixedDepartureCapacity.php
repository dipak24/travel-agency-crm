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

    /**
     * Moves a booking's seats when its departure or pax count changes: releases what the booking
     * held before and reserves what it needs now, in one transaction, so a failed reservation
     * (not enough seats) leaves the old seats untouched.
     */
    public function adjust(?FixedDeparture $from, int $fromPax, ?FixedDeparture $to, int $toPax): void
    {
        DB::transaction(function () use ($from, $fromPax, $to, $toPax): void {
            if ($from !== null && $to !== null && $from->is($to)) {
                $difference = $toPax - $fromPax;

                if ($difference > 0) {
                    $this->reserve($to, $difference);
                } elseif ($difference < 0) {
                    $this->release($to, -$difference);
                }

                return;
            }

            if ($from !== null && $fromPax > 0) {
                $this->release($from, $fromPax);
            }

            if ($to !== null && $toPax > 0) {
                $this->reserve($to, $toPax);
            }
        });
    }

    /**
     * Releases slots previously reserved via `reserve()` — used when a booking against this
     * departure is cancelled. Never re-opens a departure that was explicitly `closed`/`cancelled`
     * on its own terms, only flips a `full` departure back to `open` once it has room again.
     */
    public function release(FixedDeparture $departure, int $pax): FixedDeparture
    {
        if ($pax < 1) {
            throw new LogicException('At least one slot must be released.');
        }

        return DB::transaction(function () use ($departure, $pax): FixedDeparture {
            $lockedDeparture = FixedDeparture::query()
                ->whereKey($departure->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDeparture->booked_slots = max(0, $lockedDeparture->booked_slots - $pax);

            if ($lockedDeparture->status === 'full' && $lockedDeparture->booked_slots < $lockedDeparture->total_slots) {
                $lockedDeparture->status = 'open';
            }

            $lockedDeparture->save();

            return $lockedDeparture;
        });
    }
}
