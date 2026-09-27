<?php

namespace Database\Factories;

use App\Enums\PricingUnit;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'price' => 15000,
            'currency' => 'USD',
            'pricing_unit' => PricingUnit::PerTrip,
            'is_active' => true,
        ];
    }
}
