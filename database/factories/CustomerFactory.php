<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // e164PhoneNumber(), not phoneNumber() — the latter can produce extension suffixes
            // like " x1234" that fail the tel-regex validation on `->tel()` phone form fields
            // (e.g. BuyGiftVoucher's purchaser_phone) once every so often, an intermittent test
            // failure that has nothing to do with whatever the test itself is actually checking.
            'phone' => fake()->e164PhoneNumber(),
            'type' => 'individual',
        ];
    }
}
