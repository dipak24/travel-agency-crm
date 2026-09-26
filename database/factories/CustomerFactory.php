<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Defaults to an already-activated, verified account — the common case in portal tests.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'status' => CustomerStatus::Active,
            // e164PhoneNumber(), not phoneNumber() — the latter can produce extension suffixes
            // like " x1234" that fail the tel-regex validation on `->tel()` phone form fields
            // (e.g. BuyGiftVoucher's purchaser_phone) once every so often, an intermittent test
            // failure that has nothing to do with whatever the test itself is actually checking.
            'phone' => fake()->e164PhoneNumber(),
            'type' => 'individual',
        ];
    }

    /**
     * A staff-created customer that hasn't opened their setup link yet.
     */
    public function pending(): static
    {
        return $this->state([
            'password' => null,
            'status' => CustomerStatus::Pending,
            'email_verified_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => CustomerStatus::Suspended]);
    }
}
