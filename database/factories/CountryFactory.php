<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    public function definition(): array
    {
        $iso2 = strtoupper(fake()->unique()->lexify('??'));

        return [
            'name' => fake()->unique()->country(),
            'iso2' => $iso2,
            'iso3' => $iso2.'X',
            'phone_code' => '+'.fake()->numberBetween(1, 999),
            'nationality' => fake()->word(),
            'is_active' => true,
        ];
    }
}
