<?php

namespace App\Http\Resources\V1;

use App\Models\FixedDeparture;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a fixed departure. Raw capacity (total/booked slots, overbooking buffer) stays
 * internal — visitors only see how many seats are left and whether they can book or waitlist.
 *
 * @mixin FixedDeparture
 */
class FixedDepartureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $seatsRemaining = $this->publicSeatsRemaining();

        return [
            'id' => $this->id,
            'package_slug' => $this->package->slug,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'price' => [
                'amount' => $this->price_override ?? $this->package->sales_price,
                'currency' => app(TenantContext::class)->get()?->currency,
            ],
            'seats_remaining' => $seatsRemaining,
            'is_full' => $seatsRemaining === 0,
            'waitlist_available' => $seatsRemaining === 0,
        ];
    }
}
