<?php

namespace App\Services;

use App\Models\BookingWaitlist;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\Lead;
use App\Models\Package;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns unauthenticated public API submissions (inquiry form, departure waitlist) into CRM records
 * for the current tenant. Contact details are matched to an existing customer by email, but an
 * existing customer's profile is never overwritten from a public request — whatever was submitted
 * is kept verbatim in the lead's notes for staff to review instead.
 */
class PublicInquiry
{
    public const ORIGIN = 'public_website';

    /**
     * @param  array{name: string, email: string, phone?: ?string, package_slug?: ?string, destination?: ?string, trip_type?: ?string, pax_count?: ?int, budget_range?: ?string, travel_date?: ?string, message?: ?string}  $data
     */
    public function submitInquiry(array $data): Lead
    {
        return DB::transaction(function () use ($data): Lead {
            $package = empty($data['package_slug'])
                ? null
                : $this->publicPackages()->where('slug', $data['package_slug'])->first();

            if (! empty($data['package_slug']) && $package === null) {
                throw ValidationException::withMessages(['package_slug' => 'The selected package is not available.']);
            }

            return Lead::query()->create([
                'customer_id' => $this->resolveCustomer($data)->id,
                'origin' => self::ORIGIN,
                'source' => 'Inquiry form',
                'destination' => $data['destination'] ?? $package?->name,
                'trip_type' => $data['trip_type'] ?? null,
                'pax_count' => $data['pax_count'] ?? 1,
                'budget_range' => $data['budget_range'] ?? null,
                'status' => 'new',
                'notes' => $this->notes($data, array_filter([
                    'Package' => $package ? "{$package->name} ({$package->package_code})" : null,
                    'Preferred travel date' => $data['travel_date'] ?? null,
                ])),
            ]);
        });
    }

    /**
     * Joins the waitlist of a fixed departure that can't take `pax_count` more travelers. The
     * departure row is locked for the duration so capacity and the next waitlist position are
     * read consistently against concurrent bookings/waitlist joins.
     *
     * @param  array{name: string, email: string, phone?: ?string, pax_count?: ?int, message?: ?string}  $data
     */
    public function joinWaitlist(int $departureId, array $data): BookingWaitlist
    {
        return DB::transaction(function () use ($departureId, $data): BookingWaitlist {
            $departure = $this->publicDepartures()->whereKey($departureId)->lockForUpdate()->first();

            if ($departure === null) {
                throw ValidationException::withMessages(['departure' => 'This departure is not available.']);
            }

            $paxRequested = $data['pax_count'] ?? 1;

            if ($departure->publicSeatsRemaining() >= $paxRequested) {
                throw ValidationException::withMessages([
                    'departure' => 'This departure still has seats available — send an inquiry instead of joining the waitlist.',
                ]);
            }

            $customer = $this->resolveCustomer($data);

            $existingEntry = BookingWaitlist::query()
                ->where('fixed_departure_id', $departure->id)
                ->where('customer_id', $customer->id)
                ->where('status', 'waiting')
                ->first();

            if ($existingEntry !== null) {
                return $existingEntry;
            }

            $package = $departure->package;

            $lead = Lead::query()->create([
                'customer_id' => $customer->id,
                'origin' => self::ORIGIN,
                'source' => 'Departure waitlist',
                'destination' => $package->name,
                'pax_count' => $paxRequested,
                'status' => 'new',
                'notes' => $this->notes($data, [
                    'Waitlisted departure' => "{$package->name}, {$departure->start_date->toDateString()} to {$departure->end_date->toDateString()}",
                ]),
            ]);

            return BookingWaitlist::query()->create([
                'fixed_departure_id' => $departure->id,
                'customer_id' => $customer->id,
                'lead_id' => $lead->id,
                'pax_requested' => $paxRequested,
                'position' => (int) $departure->waitlistEntries()->max('position') + 1,
                'status' => 'waiting',
            ]);
        });
    }

    /**
     * Packages the current tenant has published to its public website.
     *
     * @return Builder<Package>
     */
    public function publicPackages(): Builder
    {
        return Package::query()->where('status', 'published')->where('is_public', true);
    }

    /**
     * Upcoming, still-running departures of public packages — `full` ones included, since those
     * are exactly the ones a visitor can join the waitlist for.
     *
     * @return Builder<FixedDeparture>
     */
    public function publicDepartures(): Builder
    {
        return FixedDeparture::query()
            ->whereIn('status', ['open', 'full'])
            ->whereDate('start_date', '>=', today())
            ->whereIn('package_id', $this->publicPackages()->select('id'));
    }

    /**
     * @param  array{name: string, email: string, phone?: ?string}  $data
     */
    private function resolveCustomer(array $data): Customer
    {
        $email = Str::lower(trim($data['email']));

        $customer = Customer::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($customer !== null) {
            return $customer;
        }

        $phone = $data['phone'] ?? null;
        $phoneIsTaken = $phone !== null && Customer::query()->where('phone', $phone)->exists();

        return Customer::query()->create([
            'name' => $data['name'],
            'email' => $email,
            'phone' => $phoneIsTaken ? null : $phone,
            'type' => 'individual',
        ]);
    }

    /**
     * @param  array{name: string, email: string, phone?: ?string, message?: ?string}  $data
     * @param  array<string, string>  $extra
     */
    private function notes(array $data, array $extra): string
    {
        $lines = collect([
            'Submitted via public website',
            'Name' => $data['name'],
            'Email' => $data['email'],
            'Phone' => $data['phone'] ?? null,
            ...$extra,
        ])
            ->filter()
            ->map(fn (string $value, int|string $label): string => is_int($label) ? $value : "{$label}: {$value}");

        if (! empty($data['message'])) {
            $lines->push('', $data['message']);
        }

        return $lines->implode("\n");
    }
}
