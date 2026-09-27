<?php

namespace Database\Factories;

use App\Enums\PricingUnit;
use App\Models\IncludeExclude;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncludeExclude>
 */
class IncludeExcludeFactory extends Factory
{
    protected $model = IncludeExclude::class;

    public function definition(): array
    {
        return [
            'type' => 'include',
            'title' => fake()->unique()->words(3, true),
            'description' => null,
            'unit_price' => 2500,
            'pricing_unit' => PricingUnit::PerPerson,
            'sort_order' => 0,
        ];
    }

    public function exclude(): static
    {
        return $this->state(['type' => 'exclude']);
    }
}
