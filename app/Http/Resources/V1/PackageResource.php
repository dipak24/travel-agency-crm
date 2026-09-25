<?php

namespace App\Http\Resources\V1;

use App\Models\Package;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a published package. Internal pricing (`base_price`) is never exposed — only
 * the sales price, in minor units of the tenant's currency.
 *
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'code' => $this->package_code,
            'name' => $this->name,
            'description' => $this->description,
            'duration_days' => $this->duration_days,
            'price' => [
                'amount' => $this->sales_price,
                'currency' => app(TenantContext::class)->get()?->currency,
            ],
            'itinerary' => $this->whenHas('itinerary', fn (): array => collect($this->itinerary ?? [])
                ->values()
                ->map(fn (array $day, int $index): array => [
                    'day' => $index + 1,
                    'title' => $day['title'] ?? null,
                    'description' => $day['description'] ?? null,
                ])
                ->all()),
            'departures' => FixedDepartureResource::collection($this->whenLoaded('fixedDepartures')),
        ];
    }
}
