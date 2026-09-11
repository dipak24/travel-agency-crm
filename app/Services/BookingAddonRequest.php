<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Service;
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

        return $booking->addons()->create([
            'service_id' => $service->id,
            'price' => $service->price * $quantity,
            'quantity' => $quantity,
            'status' => 'requested',
            'added_by' => $requestedBy,
        ]);
    }
}
