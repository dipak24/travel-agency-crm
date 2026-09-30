<?php

namespace Database\Seeders;

use App\Enums\BookingType;
use App\Enums\CustomerSpecialDateType;
use App\Enums\CustomerStatus;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingIncludeExclude;
use App\Models\BookingTraveler;
use App\Models\BookingWaitlist;
use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerSpecialDate;
use App\Models\EmailUnsubscribe;
use App\Models\FixedDeparture;
use App\Models\GiftVoucher;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PublicLeadPage;
use App\Models\Reminder;
use App\Models\SaasLead;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\BookingInvoicing;
use App\Services\BookingPricing;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Day-to-day demo data for the demo agency, built on DemoCatalogSeeder's catalog: customers,
 * leads, bookings of every type with travellers and add-ons, invoices with payments, a waitlist
 * entry, reminders, a gift voucher, a live public website and platform SaaS leads. Bookings and invoices are skipped when
 * the agency already has bookings, so running it again never duplicates them.
 *
 * DatabaseSeeder runs without model events, but invoices, payments and prices here go through the
 * real services (which rely on the tenant and invoice-number hooks), so events are switched back
 * on for this seeder. Every record is created in its final state, so no status-change emails fire.
 */
class DemoOperationsSeeder extends Seeder
{
    public function run(): void
    {
        SaasLead::query()->updateOrCreate(['email' => 'owner@summit-adventures.example'], ['name' => 'Pema Sherpa', 'company' => 'Summit Adventures', 'status' => 'new', 'source' => 'website']);
        SaasLead::query()->updateOrCreate(['email' => 'hello@lakeside-tours.example'], ['name' => 'Rita Thapa', 'company' => 'Lakeside Tours', 'status' => 'contacted', 'source' => 'referral']);

        $tenant = Tenant::query()->where('slug', 'demo-travel')->first();
        $staff = $tenant === null ? null : TenantUser::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if ($tenant === null || $staff === null) {
            return;
        }

        $this->withModelEvents(function () use ($tenant, $staff): void {
            app(TenantContext::class)->wrap($tenant, fn () => $this->seedTenant($staff));
        });
    }

    private function seedTenant(TenantUser $staff): void
    {
        $customers = $this->seedCustomers();
        $this->seedLeads($customers, $staff);

        PublicLeadPage::query()->firstOrCreate([], [
            'is_active' => true,
            'booking_settings' => PublicLeadPage::BOOKING_DEFAULTS,
            'contact_settings' => ['email' => 'hello@demo-travel.test', 'phone' => '+977 1 4000000'],
        ]);

        EmailUnsubscribe::query()->updateOrCreate(['tenant_id' => $staff->tenant_id, 'email' => 'no-marketing@example.com'], ['unsubscribed_at' => now()->subWeek(), 'source' => 'manual']);

        GiftVoucher::query()->updateOrCreate(['code' => 'GIFT-DEMO-100'], [
            'value' => 10000, 'currency' => 'USD', 'issued_to' => $customers['bikash']->id,
            'recipient_first_name' => 'Bikash', 'recipient_last_name' => 'Rai', 'recipient_email' => $customers['bikash']->email,
            'status' => 'unredeemed', 'expires_at' => now()->addYear(),
        ]);

        if (Booking::query()->withTrashed()->exists()) {
            return;
        }

        $everest = Package::query()->where('package_code', 'EBC-14')->firstOrFail();
        $annapurna = Package::query()->where('package_code', 'ABC-10')->firstOrFail();
        $everestDeparture = FixedDeparture::query()->where('package_id', $everest->id)->orderBy('start_date')->firstOrFail();
        $annapurnaDeparture = FixedDeparture::query()->where('package_id', $annapurna->id)->orderBy('start_date')->firstOrFail();
        $skydiving = Service::query()->where('name', 'Tandem skydiving in Pokhara')->firstOrFail();
        $airportTransfer = Service::query()->where('name', 'Private airport transfer')->firstOrFail();

        // 1. Fixed group departure, confirmed, deposit paid.
        $fixedGroup = $this->createBooking($customers['demo'], $staff, $everest, [
            'booking_type' => BookingType::FixedGroup,
            'fixed_departure_id' => $everestDeparture->id,
            'start_date' => $everestDeparture->start_date,
            'end_date' => $everestDeparture->end_date,
            'pax_count' => 2,
            'status' => 'confirmed',
        ]);
        $everestDeparture->increment('booked_slots', 2);
        $this->addTraveler($fixedGroup, 'Demo Customer', $customers['demo']->email);
        $this->addTraveler($fixedGroup, 'Sita Customer', 'sita@example.com');
        $this->addAddon($fixedGroup, $skydiving, 2, 'approved', 42000);
        $invoice = $this->invoice($fixedGroup, $staff);
        $this->pay($invoice, (int) round($invoice->total * 0.25), 'bank_transfer', 'advance', 'DEMO-TXN-1001');
        Reminder::query()->create([
            'booking_id' => $fixedGroup->id, 'requirement_type' => 'documents', 'recipient_type' => 'customer',
            'channel' => 'email', 'reminder_rule' => 'days_before:30', 'sent_at' => now()->subDay(), 'status' => 'sent',
        ]);

        // 2. Private group on own dates, still pending, invoice not issued yet.
        $privateGroup = $this->createBooking($customers['anna'], $staff, $annapurna, [
            'booking_type' => BookingType::PrivateGroup,
            'start_date' => now()->addMonths(2)->startOfWeek(),
            'end_date' => now()->addMonths(2)->startOfWeek()->addDays($annapurna->duration_days - 1),
            'pax_count' => 4,
            'status' => 'pending',
        ]);
        foreach (['Anna Müller', 'Jonas Müller', 'Lena Weber', 'Paul Weber'] as $name) {
            $this->addTraveler($privateGroup, $name);
        }
        $this->invoice($privateGroup, $staff, 'draft');

        // 3. Individual custom trip in the past, completed and fully paid.
        $completed = $this->createBooking($customers['bikash'], $staff, null, [
            'booking_type' => BookingType::Individual,
            'trip_name' => 'Kathmandu Valley heritage tour',
            'booked_itinerary' => '<p>Durbar Squares of Kathmandu, Patan and Bhaktapur with a private guide.</p>',
            'start_date' => now()->subMonths(2)->startOfWeek(),
            'end_date' => now()->subMonths(2)->startOfWeek()->addDays(2),
            'duration_days' => 3,
            'pax_count' => 1,
            'per_person_price' => 35000,
            'status' => 'completed',
        ]);
        $this->addTraveler($completed, 'Bikash Rai', $customers['bikash']->email);
        $this->addAddon($completed, $airportTransfer, 1, 'booked');
        $paidInvoice = $this->invoice($completed, $staff);
        $this->pay($paidInvoice, $paidInvoice->total, 'card', 'final', 'DEMO-TXN-1002');

        // 4. Cancelled individual booking.
        $this->createBooking($customers['emma'], $staff, $everest, [
            'booking_type' => BookingType::Individual,
            'start_date' => now()->addMonths(5)->startOfWeek(),
            'end_date' => now()->addMonths(5)->startOfWeek()->addDays($everest->duration_days - 1),
            'pax_count' => 1,
            'status' => 'cancelled',
            'customer_notes' => 'Changed travel plans.',
        ]);

        BookingWaitlist::query()->create([
            'fixed_departure_id' => $annapurnaDeparture->id,
            'customer_id' => $customers['emma']->id,
            'pax_requested' => 2,
            'position' => 1,
            'status' => 'waiting',
        ]);
    }

    /**
     * @return array<string, Customer>
     */
    private function seedCustomers(): array
    {
        $nepal = Country::query()->where('iso2', 'NP')->value('id');
        $germany = Country::query()->where('iso2', 'DE')->value('id');
        $uk = Country::query()->where('iso2', 'GB')->value('id');

        $rows = [
            'demo' => ['customer@example.com', 'Demo Customer', '+1 555 0100', $nepal],
            'anna' => ['anna.mueller@example.com', 'Anna Müller', '+49 151 2345678', $germany],
            'bikash' => ['bikash.rai@example.com', 'Bikash Rai', '+977 9801234567', $nepal],
            'emma' => ['emma.jones@example.com', 'Emma Jones', '+44 7700 900123', $uk],
        ];

        $customers = [];

        foreach ($rows as $key => [$email, $name, $phone, $country]) {
            $customer = Customer::query()->firstOrNew(['email' => $email]);

            if (! $customer->exists) {
                $customer->fill([
                    'name' => $name, 'password' => 'password', 'phone' => $phone, 'type' => 'individual',
                    'nationality_id' => $country, 'country_of_residence_id' => $country,
                ]);
                $customer->forceFill(['status' => CustomerStatus::Active, 'email_verified_at' => now()])->save();
            }

            $customers[$key] = $customer;
        }

        CustomerSpecialDate::query()->updateOrCreate(
            ['customer_id' => $customers['anna']->id, 'type' => CustomerSpecialDateType::WeddingAnniversary],
            ['label' => 'Wedding anniversary', 'date' => now()->addMonth()->startOfDay(), 'remind' => true, 'remind_days_before' => 7],
        );
        CustomerSpecialDate::query()->updateOrCreate(
            ['customer_id' => $customers['bikash']->id, 'type' => CustomerSpecialDateType::TravelAnniversary],
            ['label' => 'First trip with us', 'date' => now()->subMonths(2)->startOfWeek(), 'remind' => false, 'remind_days_before' => 3],
        );

        return $customers;
    }

    /**
     * @param  array<string, Customer>  $customers
     */
    private function seedLeads(array $customers, TenantUser $staff): void
    {
        $leads = [
            ['Everest Base Camp', 'public_website', 'website form', 2, 'new', null, 'Asked about October departures.'],
            ['Annapurna Base Camp', 'whatsapp', 'WhatsApp', 4, 'negotiating', $customers['anna']->id, 'Family of four, wants private guide.'],
            ['Chitwan safari', 'referral', 'Bikash Rai', 3, 'contacted', null, 'Short add-on after a trek.'],
            ['Upper Mustang', 'agent', 'Partner agency', 6, 'lost', null, 'Budget too low for restricted-area permits.'],
            ['Kathmandu Valley tour', 'manual', 'walk-in', 1, 'won', $customers['bikash']->id, 'Converted to a booking.'],
        ];

        foreach ($leads as [$destination, $origin, $source, $pax, $status, $customerId, $notes]) {
            Lead::query()->updateOrCreate(
                ['destination' => $destination, 'origin' => $origin],
                [
                    'customer_id' => $customerId, 'source' => $source, 'trip_type' => 'Trek', 'pax_count' => $pax,
                    'budget_range' => '$1,000 – $2,500 pp', 'status' => $status, 'assigned_staff_id' => $staff->id,
                    'follow_up_date' => in_array($status, ['new', 'contacted', 'negotiating'], true) ? now()->addDays(3) : null,
                    'notes' => $notes,
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createBooking(Customer $customer, TenantUser $staff, ?Package $package, array $attributes): Booking
    {
        $booking = Booking::query()->create([
            'customer_id' => $customer->id,
            'package_id' => $package?->id,
            'trip_name' => $package?->name,
            'booked_itinerary' => $package?->itineraryHtml(),
            'duration_days' => $package?->duration_days,
            'per_person_price' => $package?->sales_price ?? 0,
            'created_by_staff_id' => $staff->id,
            ...$attributes,
        ]);

        if ($package !== null) {
            BookingIncludeExclude::syncRows($booking, BookingIncludeExclude::rowsFromPackage($package));
        }

        app(BookingPricing::class)->recalculate($booking);

        return $booking;
    }

    private function addTraveler(Booking $booking, string $name, ?string $email = null): void
    {
        BookingTraveler::query()->create([
            'booking_id' => $booking->id,
            'name' => $name,
            'email' => $email,
            'document_status' => 'pending',
        ]);
    }

    private function addAddon(Booking $booking, Service $service, int $quantity, string $status, ?int $unitPrice = null): void
    {
        $unitPrice ??= $service->price;

        BookingAddon::query()->create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'name' => $service->name,
            'unit_price' => $unitPrice,
            'price' => $unitPrice * $quantity,
            'quantity' => $quantity,
            'status' => $status,
            'added_by' => 'staff@example.com',
        ]);

        app(BookingPricing::class)->recalculate($booking->refresh());
    }

    private function invoice(Booking $booking, TenantUser $staff, string $status = 'issued'): Invoice
    {
        return app(BookingInvoicing::class)->createDraft($booking->refresh(), $staff, $status);
    }

    private function pay(Invoice $invoice, int $amount, string $method, string $type, string $reference): void
    {
        Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => $invoice->currency,
            'method' => $method,
            'type' => $type,
            'status' => 'completed',
            'transaction_ref' => $reference,
            'paid_at' => now(),
        ]);
    }

    /**
     * Runs the callback with Eloquent model events on, re-booting models so the hooks they
     * register in booted() (tenant id, invoice numbers, payment totals) are attached.
     */
    private function withModelEvents(callable $callback): void
    {
        $dispatcher = Model::getEventDispatcher();
        Model::setEventDispatcher(app('events'));
        Model::clearBootedModels();

        try {
            $callback();
        } finally {
            if ($dispatcher === null) {
                Model::unsetEventDispatcher();
            } else {
                Model::setEventDispatcher($dispatcher);
            }

            Model::clearBootedModels();
        }
    }
}
