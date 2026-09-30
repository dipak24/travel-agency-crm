<?php

namespace App\Http\Resources\V1;

use App\Models\Package;
use App\Models\PackageIncludeExclude;
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
            'category' => $this->category?->value,
            'duration_days' => $this->duration_days,
            'price' => [
                'amount' => $this->sales_price,
                'currency' => app(TenantContext::class)->get()?->currency,
            ],
            'itinerary' => $this->whenHas('itinerary', fn (): ?string => $this->itineraryHtml()),
            'inclusions' => $this->whenLoaded('includeExcludeItems', fn (): array => $this->publicItemTitles(true)),
            'exclusions' => $this->whenLoaded('includeExcludeItems', fn (): array => $this->publicItemTitles(false)),
            'departures' => FixedDepartureResource::collection($this->whenLoaded('fixedDepartures')),
        ];
    }

    /**
     * Titles only — per-item prices are internal.
     *
     * @return array<int, string>
     */
    private function publicItemTitles(bool $included): array
    {
        return $this->includeExcludeItems
            ->filter(fn (PackageIncludeExclude $item): bool => $item->is_included === $included && $item->includeExclude !== null)
            ->map(fn (PackageIncludeExclude $item): string => $item->includeExclude->title)
            ->values()
            ->all();
    }
}
