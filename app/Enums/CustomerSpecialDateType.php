<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Birthdays are not listed here — they come from the customer's own date_of_birth.
 */
enum CustomerSpecialDateType: string implements HasLabel
{
    case WeddingAnniversary = 'wedding_anniversary';
    case TravelAnniversary = 'travel_anniversary';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::WeddingAnniversary => 'Wedding anniversary',
            self::TravelAnniversary => 'Travel anniversary',
            self::Other => 'Other',
        };
    }
}
