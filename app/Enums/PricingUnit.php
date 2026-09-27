<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a priced line item (inclusion, exclusion, add-on service) scales with a booking's size.
 */
enum PricingUnit: string implements HasLabel
{
    case PerTrip = 'per_trip';
    case PerDay = 'per_day';
    case PerPerson = 'per_person';
    case PerPersonPerDay = 'per_person_per_day';

    public function getLabel(): string
    {
        return match ($this) {
            self::PerTrip => 'Per trip',
            self::PerDay => 'Per day',
            self::PerPerson => 'Per person',
            self::PerPersonPerDay => 'Per person per day',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::PerTrip => 'trip',
            self::PerDay => 'day',
            self::PerPerson => 'person',
            self::PerPersonPerDay => 'person/day',
        };
    }

    /**
     * How many units of this item a booking of the given size consumes.
     */
    public function units(int $pax, int $days): int
    {
        $pax = max(1, $pax);
        $days = max(1, $days);

        return match ($this) {
            self::PerTrip => 1,
            self::PerDay => $days,
            self::PerPerson => $pax,
            self::PerPersonPerDay => $pax * $days,
        };
    }
}
