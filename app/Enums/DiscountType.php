<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Values stored in group_discount_tiers.discount_type. Fixed is an amount off per person.
 * (Promo codes use their own percent/flat values.)
 */
enum DiscountType: string implements HasLabel
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage',
            self::Fixed => 'Fixed amount per person',
        };
    }
}
