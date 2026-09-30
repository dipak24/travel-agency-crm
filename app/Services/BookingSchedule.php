<?php

namespace App\Services;

use App\Enums\BookingType;
use App\Models\Booking;
use App\Models\FixedDeparture;
use App\Models\Package;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Applies the per-booking-type rules before a booking is saved, and moves fixed-departure seats
 * afterwards:
 *
 * - Fixed group: must join a departure; package and dates come from it; seats are reserved.
 * - Private group: needs a package; own dates; at least 2 travellers.
 * - Individual: package optional (custom trip); own dates.
 *
 * The end date is always start date + duration − 1, worked out here rather than trusted from input.
 */
class BookingSchedule
{
    /**
     * @param  array<string, mixed>  $data  Form data (keys as on the bookings table).
     * @param  string  $errorPrefix  Prefix for validation error keys, e.g. "data." on a Filament page.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function normalize(array $data, string $errorPrefix = ''): array
    {
        $type = $data['booking_type'] instanceof BookingType
            ? $data['booking_type']
            : (BookingType::tryFrom((string) ($data['booking_type'] ?? '')) ?? BookingType::Individual);
        $data['booking_type'] = $type;
        $pax = (int) ($data['pax_count'] ?? 1);

        if ($type->usesFixedDeparture()) {
            $departure = filled($data['fixed_departure_id'] ?? null)
                ? FixedDeparture::query()->find($data['fixed_departure_id'])
                : null;

            if ($departure === null) {
                throw ValidationException::withMessages([$errorPrefix.'fixed_departure_id' => 'Choose the fixed departure this group joins.']);
            }

            $data['package_id'] = $departure->package_id;
            $data['start_date'] = $departure->start_date->toDateString();
            $data['end_date'] = $departure->end_date->toDateString();
            $data['duration_days'] = (int) $departure->start_date->diffInDays($departure->end_date) + 1;
        } else {
            $data['fixed_departure_id'] = null;

            if (filled($data['start_date'] ?? null) && (int) ($data['duration_days'] ?? 0) > 0) {
                $data['end_date'] = CarbonImmutable::parse($data['start_date'])->addDays((int) $data['duration_days'] - 1)->toDateString();
            }
        }

        $package = filled($data['package_id'] ?? null) ? Package::query()->find($data['package_id']) : null;

        if ($type->requiresPackage() && $package === null) {
            throw ValidationException::withMessages([$errorPrefix.'package_id' => 'Choose the package for this booking.']);
        }

        $minimumPax = $type === BookingType::PrivateGroup ? 2 : 1;

        if ($pax < $minimumPax) {
            throw ValidationException::withMessages([$errorPrefix.'pax_count' => "This booking needs at least {$minimumPax} travellers."]);
        }

        return $data;
    }

    /**
     * The departure seats a booking holds right now: a live booking on a departure holds its pax
     * there; cancelled bookings hold nothing. (normalize() clears the departure on every type but
     * fixed group, so only fixed-group bookings ever have one.)
     *
     * @return array{0: ?FixedDeparture, 1: int}
     */
    public function seatsHeld(Booking $booking): array
    {
        if ($booking->status === 'cancelled' || $booking->fixed_departure_id === null) {
            return [null, 0];
        }

        return [FixedDeparture::query()->find($booking->fixed_departure_id), (int) $booking->pax_count];
    }

    /**
     * Moves seats from what the booking held before a save to what it holds after it.
     *
     * @param  array{0: ?FixedDeparture, 1: int}  $before
     *
     * @throws ValidationException when the departure hasn't enough seats left.
     */
    public function moveSeats(array $before, Booking $after, string $errorPrefix = ''): void
    {
        [$toDeparture, $toPax] = $this->seatsHeld($after);

        try {
            app(FixedDepartureCapacity::class)->adjust($before[0], $before[1], $toDeparture, $toPax);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([$errorPrefix.'pax_count' => $exception->getMessage()]);
        }
    }
}
