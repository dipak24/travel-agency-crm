<?php

namespace Database\Factories;

use App\Enums\BookingType;
use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $start = now()->addMonth()->startOfDay();

        return [
            'customer_id' => Customer::factory(),
            'booking_type' => BookingType::Individual,
            'trip_name' => fake()->words(3, true),
            'start_date' => $start,
            'duration_days' => 5,
            'end_date' => $start->copy()->addDays(4),
            'pax_count' => 1,
            'status' => 'pending',
            'total_amount' => 0,
        ];
    }
}
