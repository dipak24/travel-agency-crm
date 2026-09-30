<?php

namespace App\Services;

use App\Enums\BookingType;
use App\Enums\PricingUnit;
use App\Models\Booking;
use App\Models\BookingIncludeExclude;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\GiftVoucher;
use App\Models\GroupDiscountTier;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PackageIncludeExclude;
use App\Models\PromoCode;
use App\Models\Service;
use App\Support\PublicSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A booking made by a traveller on the agency's public pages, without an account: a private trip
 * on their own dates, or seats on a fixed departure. The traveller can add the package's optional
 * extras and add-on services; the booking is priced by BookingPricing like any staff booking,
 * seats are reserved, and an issued deposit invoice is returned for the no-login payment page.
 * A promo code comes off the trip total (as the booking's manual adjustment, so staff see it); a
 * gift voucher is credit against the amount paid now. The booking stays Pending for CST to confirm.
 */
class PublicBooking
{
    /**
     * @param  array{name: string, email: string, phone?: ?string, pax_count: int|string, start_date?: ?string, arrival_date?: ?string, country_id?: int|string|null, city?: ?string, address?: ?string, emergency_contact_name?: ?string, emergency_contact_phone?: ?string, extras?: array<int, int|string>, addons?: array<int, int|string>, notes?: ?string, promo_code?: ?string, gift_voucher?: ?string}  $data
     *
     * @throws ValidationException when the dates, traveller count, seats, promo code or gift voucher don't work for this trip.
     */
    public function book(Package $package, ?FixedDeparture $departure, array $data): Invoice
    {
        return DB::transaction(function () use ($package, $departure, $data): Invoice {
            $pax = (int) $data['pax_count'];

            if ($departure !== null) {
                $departure = $this->lockPublicSeats($departure, $pax);
            }

            $promo = $this->findPromo($data['promo_code'] ?? null, $package, lock: true);
            $customer = app(PublicInquiry::class)->resolveCustomer($data);
            $this->completeProfile($customer, $data);

            $attributes = app(BookingSchedule::class)->normalize([
                'booking_type' => match (true) {
                    $departure !== null => BookingType::FixedGroup,
                    $pax > 1 => BookingType::PrivateGroup,
                    default => BookingType::Individual,
                },
                'package_id' => $package->getKey(),
                'fixed_departure_id' => $departure?->getKey(),
                'start_date' => $data['start_date'] ?? null,
                'duration_days' => $package->duration_days,
                'pax_count' => $pax,
            ]);

            $booking = Booking::query()->create([
                ...$attributes,
                'customer_id' => $customer->getKey(),
                'trip_name' => $package->name,
                'booked_itinerary' => $package->itineraryHtml(),
                'status' => 'pending',
                'per_person_price' => app(BookingPricing::class)->defaultPerPersonPrice($package, $departure),
                'description' => 'Booked online via the agency website.',
                'customer_notes' => $this->customerNotes($data),
            ]);

            $extras = array_map('intval', $data['extras'] ?? []);
            $rows = array_map(
                fn (array $row): array => $row['default_included'] === false && in_array($row['include_exclude_id'], $extras, true)
                    ? [...$row, 'type' => 'include']
                    : $row,
                BookingIncludeExclude::rowsFromPackage($package),
            );
            BookingIncludeExclude::syncRows($booking, $rows);

            $this->addAddons($booking, $package, array_map('intval', $data['addons'] ?? []), $customer->email);

            app(BookingSchedule::class)->moveSeats([null, 0], $booking->refresh());

            if ($promo !== null) {
                $this->applyPromo($booking, $promo);
            }

            $invoice = app(BookingInvoicing::class)->createDeposit($booking, PublicSite::depositPercent($booking->tenant));

            if (filled($data['gift_voucher'] ?? null)) {
                $this->redeemVoucher($invoice, (string) $data['gift_voucher']);
            }

            return $invoice->refresh();
        });
    }

    /**
     * Checks a promo code or gift voucher typed on the booking form, so the traveller sees its
     * effect before booking. Everything is checked again when the booking is made.
     *
     * @return array{valid: bool, message: string, type?: string, value?: int, balance?: int}
     */
    public function checkCode(Package $package, string $kind, string $code, ?string $email = null): array
    {
        try {
            if ($kind === 'promo') {
                $promo = $this->findPromo($code, $package);

                return [
                    'valid' => true,
                    'message' => "Promo code {$promo->code} applied.",
                    'type' => $promo->discount_type,
                    'value' => $promo->discount_value,
                ];
            }

            $voucher = $this->findVoucher($code, $email);

            return ['valid' => true, 'message' => "Gift voucher {$voucher->code} applied.", 'balance' => $voucher->value];
        } catch (ValidationException $exception) {
            return ['valid' => false, 'message' => collect($exception->errors())->flatten()->first()];
        }
    }

    /**
     * @throws ValidationException when the code doesn't exist or can't be used for this package.
     */
    private function findPromo(?string $code, Package $package, bool $lock = false): ?PromoCode
    {
        if (blank($code)) {
            return null;
        }

        $promo = PromoCode::query()
            ->where('code', Str::upper(trim($code)))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        $reason = $promo === null ? 'That promo code is not valid.' : $promo->rejectionReason($package->getKey());

        if ($reason !== null) {
            throw ValidationException::withMessages(['promo_code' => $reason]);
        }

        return $promo;
    }

    /**
     * @throws ValidationException when the voucher can't be redeemed, or belongs to someone else.
     */
    private function findVoucher(string $code, ?string $email): GiftVoucher
    {
        $voucher = GiftVoucher::query()->with('issuedTo')->where('code', Str::upper(trim($code)))->first();
        $reason = $voucher === null ? 'That gift voucher is not valid or has already been fully redeemed.' : $voucher->rejectionReason();

        if ($reason === null && $voucher->issuedTo !== null && filled($email) && Str::lower(trim($email)) !== Str::lower($voucher->issuedTo->email)) {
            $reason = 'That gift voucher was issued to a different customer.';
        }

        if ($reason !== null) {
            throw ValidationException::withMessages(['gift_voucher' => $reason]);
        }

        return $voucher;
    }

    /**
     * Takes the promo discount off the priced trip total, recorded as the booking's manual
     * adjustment so the deposit, invoices and staff all see the reduced price.
     */
    private function applyPromo(Booking $booking, PromoCode $promo): void
    {
        $total = app(BookingPricing::class)->recalculate($booking->refresh())->total;
        $discount = $promo->discountOn($total);

        if ($discount <= 0) {
            return;
        }

        $booking->update([
            'manual_adjustment' => -$discount,
            'manual_adjustment_reason' => "Promo code {$promo->code}",
        ]);
        $promo->increment('used_count');
    }

    private function redeemVoucher(Invoice $invoice, string $code): void
    {
        $this->findVoucher($code, $invoice->customer?->email);

        try {
            app(InvoiceGiftVoucherRedemption::class)->redeem($invoice, $code);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['gift_voucher' => $exception->getMessage()]);
        }
    }

    /**
     * Fills in the traveller's country, address and emergency contact on their customer record,
     * without overwriting anything an existing customer already has on file.
     *
     * @param  array<string, mixed>  $data
     */
    private function completeProfile(Customer $customer, array $data): void
    {
        $address = collect([$data['address'] ?? null, $data['city'] ?? null])->filter(fn ($value): bool => filled($value))->implode(', ');

        $details = array_filter([
            'country_of_residence_id' => filled($data['country_id'] ?? null) ? (int) $data['country_id'] : null,
            'address' => $address !== '' ? $address : null,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
        ], fn ($value): bool => filled($value));

        $missing = array_filter($details, fn (string $attribute): bool => blank($customer->getAttribute($attribute)), ARRAY_FILTER_USE_KEY);

        if ($missing !== []) {
            $customer->update($missing);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function customerNotes(array $data): ?string
    {
        $notes = collect([
            filled($data['arrival_date'] ?? null) ? 'Arrival date: '.$data['arrival_date'] : null,
            filled($data['notes'] ?? null) ? $data['notes'] : null,
        ])->filter()->implode("\n\n");

        return $notes !== '' ? $notes : null;
    }

    /**
     * What the public booking form's live estimate needs (money in cents). The booking is always
     * re-priced on the server; this only lets the traveller see the effect of their choices.
     *
     * @return array{price: int, days: int, fixedGroup: bool, tiers: array<int, array{packageId: ?int, min: int, max: ?int, type: string, value: int}>, extras: array<int, array{id: int, price: int, unit: string}>, addons: array<int, array{id: int, price: int, unit: string}>}
     */
    public function estimatorData(Package $package, ?FixedDeparture $departure = null): array
    {
        return [
            'price' => app(BookingPricing::class)->defaultPerPersonPrice($package, $departure),
            'days' => $departure ? (int) $departure->start_date->diffInDays($departure->end_date) + 1 : max(1, (int) $package->duration_days),
            'fixedGroup' => $departure !== null,
            'tiers' => GroupDiscountTier::query()
                ->where(fn ($query) => $query->whereNull('package_id')->orWhere('package_id', $package->getKey()))
                ->get()
                ->map(fn (GroupDiscountTier $tier): array => [
                    'packageId' => $tier->package_id,
                    'min' => $tier->min_pax,
                    'max' => $tier->max_pax,
                    'type' => $tier->discount_type,
                    'value' => $tier->discount_value,
                ])->all(),
            'extras' => $package->includeExcludeItems
                ->filter(fn (PackageIncludeExclude $item): bool => ! $item->is_included && $item->includeExclude !== null)
                ->map(fn (PackageIncludeExclude $item): array => [
                    'id' => $item->include_exclude_id,
                    'price' => $item->unit_price,
                    'unit' => $item->includeExclude->pricing_unit?->value ?? PricingUnit::PerPerson->value,
                ])->values()->all(),
            'addons' => $package->services
                ->map(fn (Service $service): array => [
                    'id' => $service->id,
                    'price' => $service->pivot->price,
                    'unit' => $service->pricing_unit?->value ?? PricingUnit::PerTrip->value,
                ])->values()->all(),
        ];
    }

    /**
     * Re-reads the departure under a row lock and checks the seats a traveller may take online.
     * Seat reservation itself allows the overbooking buffer (it serves staff too), so without this
     * check two concurrent online bookings could both pass the controller's check and eat into it.
     *
     * @throws ValidationException when the departure is no longer public or has too few seats.
     */
    private function lockPublicSeats(FixedDeparture $departure, int $pax): FixedDeparture
    {
        $locked = app(PublicInquiry::class)->publicDepartures()
            ->whereKey($departure->getKey())
            ->lockForUpdate()
            ->first();

        if ($locked === null || $locked->publicSeatsRemaining() < $pax) {
            throw ValidationException::withMessages([
                'pax_count' => $locked === null || $locked->publicSeatsRemaining() < 1
                    ? 'This departure is no longer available.'
                    : "Only {$locked->publicSeatsRemaining()} seats are left on this departure.",
            ]);
        }

        return $locked->setRelation('package', $departure->package);
    }

    /**
     * Only the package's own add-on services can be picked online, at the package price. Their
     * quantity follows the service's pricing unit (e.g. per person → one per traveller).
     *
     * @param  array<int, int>  $serviceIds
     */
    private function addAddons(Booking $booking, Package $package, array $serviceIds, string $addedBy): void
    {
        if ($serviceIds === []) {
            return;
        }

        $days = app(BookingPricing::class)->daysFor($booking);

        foreach ($package->services()->where('is_active', true)->whereIn('services.id', $serviceIds)->get() as $service) {
            /** @var Service $service */
            $quantity = $service->pricing_unit->units($booking->pax_count, $days);
            $unitPrice = $service->unitPriceFor($package->getKey());

            $booking->addons()->create([
                'service_id' => $service->getKey(),
                'name' => $service->name,
                'unit_price' => $unitPrice,
                'price' => $unitPrice * $quantity,
                'quantity' => $quantity,
                'status' => 'approved',
                'added_by' => $addedBy,
            ]);
        }
    }
}
