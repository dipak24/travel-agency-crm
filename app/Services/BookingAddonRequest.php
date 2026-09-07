<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Service;
use App\Models\ServiceAvailability;
use Illuminate\Support\Facades\DB;
use LogicException;

class BookingAddonRequest
{
    public function request(Booking $booking, Service $service, int $quantity, string $requestedBy): BookingAddon
    {
        if ($quantity < 1) {
            throw new LogicException('At least one unit must be requested.');
        }

        if (! $service->is_active) {
            throw new LogicException('This add-on is no longer available.');
        }

        if ($service->has_limited_availability) {
            if (! $booking->start_date) {
                throw new LogicException('This booking has no travel date set yet — contact staff to request this add-on.');
            }

            $reserved = DB::transaction(function () use ($service, $booking, $quantity): bool {
                $availability = ServiceAvailability::query()
                    ->where('service_id', $service->id)
                    ->whereDate('date', $booking->start_date)
                    ->lockForUpdate()
                    ->first();

                if (! $availability || $availability->remainingSlots() < $quantity) {
                    return false;
                }

                $availability->increment('booked_slots', $quantity);

                return true;
            });

            if (! $reserved) {
                throw new LogicException('Not enough availability for this add-on on your travel date.');
            }
        }

        return $booking->addons()->create([
            'service_id' => $service->id,
            'price' => $service->price * $quantity,
            'quantity' => $quantity,
            'status' => 'requested',
            'added_by' => $requestedBy,
        ]);
    }
}
