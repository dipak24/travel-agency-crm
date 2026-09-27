<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How a booking is sold. Fixed-group bookings take seats on a scheduled FixedDeparture; private
 * groups and individuals travel on dates of their choosing. Only group types earn discount tiers.
 */
enum BookingType: string implements HasColor, HasDescription, HasLabel
{
    case FixedGroup = 'fixed_group';
    case PrivateGroup = 'private_group';
    case Individual = 'individual';

    public function getLabel(): string
    {
        return match ($this) {
            self::FixedGroup => 'Fixed departure group',
            self::PrivateGroup => 'Private group',
            self::Individual => 'Individual',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::FixedGroup => 'Joins a scheduled departure; takes seats and earns group discounts.',
            self::PrivateGroup => 'Own dates for a private group; earns group discounts.',
            self::Individual => 'Own dates; no group discount. Package optional for custom trips.',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::FixedGroup => 'info',
            self::PrivateGroup => 'success',
            self::Individual => 'gray',
        };
    }

    public function usesFixedDeparture(): bool
    {
        return $this === self::FixedGroup;
    }

    public function earnsGroupDiscount(): bool
    {
        return $this !== self::Individual;
    }

    public function requiresPackage(): bool
    {
        return $this !== self::Individual;
    }
}
