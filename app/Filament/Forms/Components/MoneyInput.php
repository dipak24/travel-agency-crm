<?php

namespace App\Filament\Forms\Components;

use App\Support\Money;
use Filament\Forms\Components\TextInput;

/**
 * A plain TextInput pre-wired to show/accept a normal decimal amount (e.g. "100.00") while the
 * underlying model attribute stores an integer in minor units (cents), as every money column in
 * this app does — converts on the way in (afterStateHydrated) and out (dehydrateStateUsing) via
 * App\Support\Money, so the cents/dollars conversion isn't reinvented per field. Fully chainable
 * like a normal TextInput::make() — add ->label(), ->required(), ->live(), etc. as usual.
 *
 * Not suitable for a field whose "money-ness" is conditional on a sibling field (e.g. a discount
 * that's either a flat amount or a percentage, like PromoCode/GroupDiscountTier's discount_value)
 * — build that field's own afterStateHydrated/dehydrateStateUsing directly against
 * Money::toDecimal()/toCents(), gated on the sibling field's value, instead.
 */
class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->step(0.01)
            ->afterStateHydrated(function (TextInput $component, $state): void {
                $component->state(Money::toDecimal($state));
            })
            ->dehydrateStateUsing(fn ($state) => Money::toCents($state));
    }
}
