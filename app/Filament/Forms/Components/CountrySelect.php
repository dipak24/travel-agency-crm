<?php

namespace App\Filament\Forms\Components;

use App\Models\Country;
use Filament\Forms\Components\Select;
use Illuminate\Validation\Rule;

/**
 * A searchable Select over the central `countries` master data (App\Models\Country), storing the
 * country id — use it for every country or nationality field instead of free text, so all portals
 * and modules share the same list. `nationality: true` labels options by nationality
 * ("Nepali (Nepal)") instead of the country name. Fully chainable like a normal Select.
 */
class CountrySelect
{
    public static function make(string $name, bool $nationality = false): Select
    {
        return Select::make($name)
            ->options(fn (): array => Country::options($nationality ? 'nationality' : 'name'))
            ->searchable()
            ->rules([Rule::exists('countries', 'id')->where('is_active', true)]);
    }
}
