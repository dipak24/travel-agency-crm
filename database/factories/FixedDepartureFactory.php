<?php

namespace Database\Factories;

use App\Models\FixedDeparture;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixedDeparture>
 */
class FixedDepartureFactory extends Factory
{
    protected $model = FixedDeparture::class;

    public function definition(): array
    {
        $start = now()->addMonth()->startOfDay();

        return [
            'package_id' => Package::factory(),
            'start_date' => $start,
            'end_date' => $start->copy()->addDays(11),
            'total_slots' => 12,
            'overbooking_buffer' => 0,
            'booked_slots' => 0,
            'price_override' => null,
            'status' => 'open',
        ];
    }
}
