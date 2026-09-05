<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => 'trial',
            'billing_email' => fake()->unique()->companyEmail(),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'trial_ends_at' => now()->addDays(14),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => 'active', 'trial_ends_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => 'suspended']);
    }
}
