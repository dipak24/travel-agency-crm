<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\Lead;
use App\Models\Package;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

class LeadConversion
{
    public function convert(
        Lead $lead,
        Customer $customer,
        ?int $packageId = null,
        ?FixedDeparture $fixedDeparture = null,
        ?TenantUser $staff = null,
    ): Booking {
        return DB::transaction(function () use ($lead, $customer, $packageId, $fixedDeparture, $staff): Booking {
            $lockedLead = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedLead->status === 'won') {
                throw new LogicException('This lead has already been converted.');
            }

            if (app(TenantContext::class)->id() !== $lockedLead->tenant_id) {
                throw new LogicException('The lead must belong to the current tenant.');
            }

            if ($customer->tenant_id !== $lockedLead->tenant_id) {
                throw new LogicException('The customer must belong to the lead tenant.');
            }

            $package = $packageId === null ? null : Package::query()->findOrFail($packageId);

            if ($fixedDeparture !== null && $fixedDeparture->tenant_id !== $lockedLead->tenant_id) {
                throw new LogicException('The fixed departure must belong to the lead tenant.');
            }

            if ($fixedDeparture !== null && $package !== null && $fixedDeparture->package_id !== $package->id) {
                throw new LogicException('The fixed departure must belong to the selected package.');
            }

            if ($staff !== null && $staff->tenant_id !== $lockedLead->tenant_id) {
                throw new LogicException('The staff member must belong to the lead tenant.');
            }

            if ($fixedDeparture !== null) {
                app(FixedDepartureCapacity::class)->reserve($fixedDeparture, $lockedLead->pax_count);
            }

            $booking = Booking::query()->create([
                'lead_id' => $lockedLead->id,
                'customer_id' => $customer->id,
                'package_id' => $package?->id,
                'fixed_departure_id' => $fixedDeparture?->id,
                'trip_name' => $package?->name ?? $lockedLead->destination ?? 'Custom trip',
                'booked_itinerary' => $this->renderPackageItineraryAsHtml($package),
                'start_date' => $fixedDeparture?->start_date,
                'end_date' => $fixedDeparture?->end_date,
                'pax_count' => $lockedLead->pax_count,
                'status' => 'pending',
                'total_amount' => $fixedDeparture?->price_override ?? $package?->sales_price ?? 0,
                'created_by_staff_id' => $staff?->id,
            ]);

            $lockedLead->update([
                'customer_id' => $customer->id,
                'status' => 'won',
            ]);

            return $booking;
        });
    }

    /**
     * The package's own itinerary is only ever a starting point — this booking's itinerary is
     * free text staff can diverge from it (or write from scratch for a custom, package-less trip),
     * so it's rendered into plain HTML here rather than kept as a structured snapshot.
     */
    private function renderPackageItineraryAsHtml(?Package $package): ?string
    {
        $days = $package?->itinerary;

        if (blank($days)) {
            return null;
        }

        return collect($days)
            ->map(fn (array $day, int $index): string => sprintf(
                '<p><strong>Day %d: %s</strong></p><p>%s</p>',
                $index + 1,
                e($day['title'] ?? ''),
                e($day['description'] ?? ''),
            ))
            ->implode('');
    }
}
