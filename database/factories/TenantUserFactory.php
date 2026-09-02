<?php

namespace Database\Factories;

use App\Models\TenantUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantUser>
 */
class TenantUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'status' => 'active',
            'designation' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Sales', 'Operations', 'Finance']),
        ];
    }
}
