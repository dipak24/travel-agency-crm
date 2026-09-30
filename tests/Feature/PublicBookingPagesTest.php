<?php

use App\Enums\BookingType;
use App\Enums\PricingUnit;
use App\Models\Booking;
use App\Models\Country;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\GiftVoucher;
use App\Models\IncludeExclude;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PackageIncludeExclude;
use App\Models\PackageService;
use App\Models\PromoCode;
use App\Models\PublicLeadPage;
use App\Models\Service;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\BookingInvoicing;
use App\Services\PublicBooking;
use App\Support\PlanFeatures;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::query()->create(['name' => 'Himalayan Trails', 'slug' => 'himalayan-trails', 'currency' => 'USD']);
    app(TenantContext::class)->set($this->tenant);
    $this->package = Package::factory()->create(['name' => 'Everest Base Camp', 'slug' => 'everest-base-camp', 'package_code' => 'EBC-10', 'sales_price' => 100000, 'duration_days' => 10]);
    $porter = IncludeExclude::factory()->exclude()->create(['title' => 'Porter', 'unit_price' => 2000, 'pricing_unit' => PricingUnit::PerDay]);
    PackageIncludeExclude::query()->create(['package_id' => $this->package->id, 'include_exclude_id' => $porter->id, 'is_included' => false]);
    $this->porter = $porter;
    $this->skydiving = Service::factory()->create(['name' => 'Skydiving', 'price' => 45000, 'pricing_unit' => PricingUnit::PerPerson]);
    PackageService::query()->create(['package_id' => $this->package->id, 'service_id' => $this->skydiving->id, 'price' => 40000]);
});

function publishSite(Tenant $tenant, array $bookingSettings = []): PublicLeadPage
{
    return app(TenantContext::class)->wrap($tenant, fn (): PublicLeadPage => PublicLeadPage::query()->create([
        'is_active' => true,
        'booking_settings' => [...PublicLeadPage::BOOKING_DEFAULTS, ...$bookingSettings],
    ]));
}

/**
 * @return array<string, mixed>
 */
function travellerForm(array $overrides = []): array
{
    $countryId = Country::query()->value('id') ?? Country::factory()->create()->id;

    return ['name' => 'Asha Gurung', 'email' => 'asha@example.com', 'phone' => '+9779800000001', 'country_id' => $countryId, 'terms' => '1', ...$overrides];
}

test('the public pages are hidden until the website is published and each page is switched on', function () {
    $this->get(portalUrl($this->tenant, '/book/EBC-10'))->assertNotFound();

    $page = publishSite($this->tenant, ['gift_vouchers' => false]);

    $this->get(portalUrl($this->tenant, '/book/EBC-10'))->assertOk()->assertSee('Book your adventure')->assertSee('Porter')->assertSee('Skydiving')
        ->assertSee('name="promo_code"', false)->assertSee('name="gift_voucher"', false)->assertSee('name="country_id"', false);
    $this->get(portalUrl($this->tenant, '/gift-vouchers'))->assertNotFound();

    $page->update(['is_active' => false]);
    $this->get(portalUrl($this->tenant, '/departures'))->assertNotFound();
});

test('the plan must include online booking for trip and group pages', function () {
    publishSite($this->tenant);
    $plan = SubscriptionPlan::query()->create(['name' => 'Lite', 'price' => 0, 'billing_cycle' => 'monthly', 'features' => [PlanFeatures::ONLINE_BOOKING => false]]);
    TenantSubscription::query()->withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()]);

    $this->get(portalUrl($this->tenant, '/book/EBC-10'))->assertNotFound();
    $this->get(portalUrl($this->tenant, '/widget/departures'))->assertNotFound();
    $this->get(portalUrl($this->tenant, '/gift-vouchers'))->assertOk();
});

test('the booking page locks the trip from the link and links the agency\'s terms', function () {
    publishSite($this->tenant);
    PublicLeadPage::query()->sole()->update(['contact_settings' => ['terms_url' => 'https://www.himalayantrails.com/terms']]);
    $included = IncludeExclude::factory()->create(['title' => 'Hotel in Kathmandu']);
    PackageIncludeExclude::query()->create(['package_id' => $this->package->id, 'include_exclude_id' => $included->id, 'is_included' => true]);

    $this->get(portalUrl($this->tenant, '/book/EBC-10'))
        ->assertOk()
        ->assertSee('id="trip" value="Everest Base Camp (10 days)" readonly', false)
        ->assertDontSee('What\'s included')
        ->assertDontSee('Hotel in Kathmandu')
        ->assertSee('href="https://www.himalayantrails.com/terms"', false)
        ->assertSee('id="booking-submit" style="margin-top: 12px;" disabled', false)
        ->assertSeeInOrder(['Personal information', 'Customise your trip']);
});

test('without a package in the link the traveller picks any published trip first', function () {
    publishSite($this->tenant);
    Package::factory()->create(['name' => 'Draft Trek', 'package_code' => 'DRAFT-1', 'status' => 'draft']);

    $this->get(portalUrl($this->tenant, '/book'))
        ->assertOk()
        ->assertSee('Everest Base Camp')
        ->assertDontSee('Draft Trek');

    $this->get(portalUrl($this->tenant, '/book?trip=EBC-10&pax=3'))
        ->assertRedirect(portalUrl($this->tenant, '/book/EBC-10?pax=3'));
    $this->get(portalUrl($this->tenant, '/book?trip=DRAFT-1'))->assertOk()->assertDontSee('Draft Trek');
});

test('the CRM hosts no trip catalog — the marketing site links to the booking pages instead', function () {
    publishSite($this->tenant);

    $this->get(portalUrl($this->tenant, '/trips'))->assertNotFound();
    $this->get(portalUrl($this->tenant, '/trips/everest-base-camp'))->assertNotFound();
    $this->get(portalUrl($this->tenant, '/book/everest-base-camp'))->assertNotFound();
});

test('a booking link pre-fills the start date and travellers, ignoring values it cannot use', function () {
    publishSite($this->tenant);
    $startDate = now()->addMonth()->toDateString();

    $this->get(portalUrl($this->tenant, "/book/EBC-10?start_date={$startDate}&pax=3"))
        ->assertOk()
        ->assertSee('name="start_date" value="'.$startDate.'"', false)
        ->assertSee('name="pax_count" value="3"', false);

    $this->get(portalUrl($this->tenant, '/book/EBC-10?start_date='.now()->subDay()->toDateString().'&pax=80'))
        ->assertOk()
        ->assertSee('name="start_date" value=""', false)
        ->assertSee('name="pax_count" value="50"', false);

    $this->get(portalUrl($this->tenant, '/book/EBC-10?start_date=2026-02-30&pax=abc'))
        ->assertOk()
        ->assertSee('name="start_date" value=""', false)
        ->assertSee('name="pax_count" value="1"', false);
});

test('a departure booking link pre-fills travellers up to the seats left', function () {
    publishSite($this->tenant);
    $departure = FixedDeparture::factory()->create(['package_id' => $this->package->id, 'total_slots' => 5, 'booked_slots' => 3]);

    $this->get(portalUrl($this->tenant, "/departures/{$departure->id}/join?pax=4"))
        ->assertOk()
        ->assertSee('name="pax_count" value="2"', false);
});

test('the departures widget lists public upcoming departures with their booking links, for any origin', function () {
    publishSite($this->tenant);
    $open = FixedDeparture::factory()->create(['package_id' => $this->package->id, 'start_date' => now()->addMonths(2), 'end_date' => now()->addMonths(2)->addDays(9), 'total_slots' => 8, 'booked_slots' => 2, 'overbooking_buffer' => 2]);
    FixedDeparture::factory()->create(['package_id' => $this->package->id, 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addDays(9), 'status' => 'cancelled']);
    $hidden = Package::factory()->create(['slug' => 'draft-trek', 'status' => 'draft']);
    FixedDeparture::factory()->create(['package_id' => $hidden->id]);

    $response = $this->getJson(portalUrl($this->tenant, '/widget/departures?package=EBC-10'), ['Origin' => 'https://www.himalayantrails.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $open->id)
        ->assertJsonPath('data.0.package_name', 'Everest Base Camp')
        ->assertJsonPath('data.0.seats_remaining', 6)
        ->assertJsonPath('data.0.book_url', portalUrl($this->tenant, "/departures/{$open->id}/join"))
        ->assertJsonMissingPath('data.0.total_slots');

    expect($response->json('data.0'))->not->toHaveKey('overbooking_buffer');

    $this->getJson(portalUrl($this->tenant, '/widget/departures'))->assertJsonCount(1, 'data');
});

test('the departures widget is off while group joining is switched off', function () {
    publishSite($this->tenant, ['group_joining' => false]);
    FixedDeparture::factory()->create(['package_id' => $this->package->id]);

    $this->getJson(portalUrl($this->tenant, '/widget/departures'))->assertNotFound();
});

test('booking a trip online creates a pending booking with the extras, and a deposit invoice to pay without login', function () {
    publishSite($this->tenant, ['deposit_percent' => 20]);

    $response = $this->post(portalUrl($this->tenant, '/book/EBC-10'), travellerForm([
        'pax_count' => 2,
        'start_date' => now()->addMonth()->toDateString(),
        'extras' => [$this->porter->id],
        'addons' => [$this->skydiving->id],
    ]));

    $booking = Booking::query()->sole();
    $invoice = Invoice::query()->sole();

    // 2 × 1000 + porter 20 × 10 days + skydiving 400 × 2 people
    expect($booking->booking_type)->toBe(BookingType::PrivateGroup)
        ->and($booking->status)->toBe('pending')
        ->and($booking->customer->email)->toBe('asha@example.com')
        ->and($booking->total_amount)->toBe(200000 + 20000 + 80000)
        ->and($booking->addons()->sole()->quantity)->toBe(2)
        ->and($invoice->total)->toBe(60000)
        ->and($invoice->status)->toBe('issued');

    $location = $response->assertRedirect()->headers->get('Location');
    expect($location)->toContain("/pay/{$invoice->id}")
        ->and(Request::create($location)->hasValidSignature())->toBeTrue();
});

test('a promo code comes off the trip total and a gift voucher pays towards the deposit', function () {
    publishSite($this->tenant, ['deposit_percent' => 20]);
    $promo = PromoCode::query()->create(['code' => 'SAVE10', 'discount_type' => 'percent', 'discount_value' => 10, 'is_active' => true]);
    $promo->packages()->attach($this->package);
    $voucher = GiftVoucher::query()->create(['code' => 'GV-50', 'value' => 5000, 'currency' => 'USD', 'status' => 'unredeemed']);
    $startDate = now()->addMonth()->toDateString();

    $this->post(portalUrl($this->tenant, '/book/EBC-10'), travellerForm([
        'pax_count' => 1, 'start_date' => $startDate, 'arrival_date' => $startDate,
        'promo_code' => 'save10', 'gift_voucher' => 'gv-50',
        'city' => 'Pokhara', 'address' => 'Lakeside 6', 'emergency_contact_name' => 'Ram Gurung', 'emergency_contact_phone' => '+9779800000009',
        'notes' => 'Vegetarian',
    ]))->assertRedirect();

    $booking = Booking::query()->sole();
    $invoice = Invoice::query()->sole();

    // 1000 − 10% promo = 900; 20% deposit = 180, of which the 50 voucher is already paid.
    expect($booking->total_amount)->toBe(90000)
        ->and($booking->manual_adjustment)->toBe(-10000)
        ->and($booking->manual_adjustment_reason)->toBe('Promo code SAVE10')
        ->and($booking->customer_notes)->toBe("Arrival date: {$startDate}\n\nVegetarian")
        ->and($invoice->total)->toBe(18000)
        ->and($invoice->balanceDue())->toBe(13000)
        ->and($invoice->status)->toBe('partially_paid')
        ->and($promo->refresh()->used_count)->toBe(1)
        ->and($voucher->refresh()->status)->toBe('redeemed')
        ->and($booking->customer->only(['address', 'emergency_contact_name', 'country_of_residence_id']))
        ->toBe(['address' => 'Lakeside 6, Pokhara', 'emergency_contact_name' => 'Ram Gurung', 'country_of_residence_id' => Country::query()->value('id')]);
});

test('an unusable promo code or someone else\'s gift voucher stops the booking', function () {
    publishSite($this->tenant);
    PromoCode::query()->create(['code' => 'OLD', 'discount_type' => 'flat', 'discount_value' => 5000, 'is_active' => true, 'valid_until' => now()->subDay()]);
    $voucher = GiftVoucher::query()->create(['code' => 'GV-RAM', 'value' => 5000, 'currency' => 'USD', 'status' => 'unredeemed', 'issued_to' => Customer::factory()->create()->id]);
    $form = travellerForm(['pax_count' => 1, 'start_date' => now()->addMonth()->toDateString()]);

    $this->post(portalUrl($this->tenant, '/book/EBC-10'), [...$form, 'promo_code' => 'OLD'])
        ->assertSessionHasErrors(['promo_code' => 'That promo code has expired.']);
    $this->post(portalUrl($this->tenant, '/book/EBC-10'), [...$form, 'gift_voucher' => 'GV-RAM'])
        ->assertSessionHasErrors(['gift_voucher' => 'That gift voucher was issued to a different customer.']);

    expect(Booking::query()->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0)
        ->and($voucher->refresh()->value)->toBe(5000);
});

test('the booking form checks a promo code or gift voucher before booking', function () {
    publishSite($this->tenant);
    $otherPackage = Package::factory()->create();
    PromoCode::query()->create(['code' => 'FLAT50', 'discount_type' => 'flat', 'discount_value' => 5000, 'is_active' => true]);
    PromoCode::query()->create(['code' => 'OTHERTRIP', 'discount_type' => 'percent', 'discount_value' => 5, 'is_active' => true])->packages()->attach($otherPackage);
    GiftVoucher::query()->create(['code' => 'GV-25', 'value' => 2500, 'currency' => 'USD', 'status' => 'unredeemed']);
    $url = portalUrl($this->tenant, '/book/EBC-10/check-code');

    $this->postJson($url, ['kind' => 'promo', 'code' => 'flat50'])
        ->assertOk()->assertJson(['valid' => true, 'type' => 'flat', 'value' => 5000]);
    $this->postJson($url, ['kind' => 'promo', 'code' => 'OTHERTRIP'])
        ->assertOk()->assertJson(['valid' => false]);
    $this->postJson($url, ['kind' => 'voucher', 'code' => 'GV-25'])
        ->assertOk()->assertJson(['valid' => true, 'balance' => 2500]);
    $this->postJson($url, ['kind' => 'voucher', 'code' => 'NOPE'])
        ->assertOk()->assertJson(['valid' => false]);
});

test('a trip cannot be booked for a past date, and the booking terms must be accepted', function () {
    publishSite($this->tenant);

    $this->post(portalUrl($this->tenant, '/book/EBC-10'), travellerForm([
        'pax_count' => 1, 'start_date' => now()->subDay()->toDateString(), 'terms' => null,
    ]))->assertSessionHasErrors(['start_date', 'terms']);

    expect(Booking::query()->count())->toBe(0);
});

test('joining a group departure takes seats, and more travellers than seats left is refused', function () {
    publishSite($this->tenant);
    $departure = FixedDeparture::factory()->create(['package_id' => $this->package->id, 'total_slots' => 5, 'booked_slots' => 2, 'price_override' => 90000]);

    $this->get(portalUrl($this->tenant, '/departures'))->assertOk()->assertSee('3 left');

    $this->post(portalUrl($this->tenant, "/departures/{$departure->id}/join"), travellerForm(['pax_count' => 4]))
        ->assertSessionHasErrors('pax_count');

    $this->post(portalUrl($this->tenant, "/departures/{$departure->id}/join"), travellerForm(['pax_count' => 3]))
        ->assertRedirect();

    $booking = Booking::query()->sole();

    expect($booking->booking_type)->toBe(BookingType::FixedGroup)
        ->and($booking->fixed_departure_id)->toBe($departure->id)
        ->and($booking->total_amount)->toBe(270000)
        ->and($departure->refresh()->booked_slots)->toBe(5)
        ->and($departure->status)->toBe('full');
});

test('an online booking never takes the overbooking buffer kept for staff', function () {
    publishSite($this->tenant);
    $departure = FixedDeparture::factory()->create(['package_id' => $this->package->id, 'total_slots' => 10, 'booked_slots' => 9, 'overbooking_buffer' => 2]);

    expect(fn () => app(PublicBooking::class)->book($this->package, $departure, travellerForm(['pax_count' => 2])))
        ->toThrow(ValidationException::class, 'Only 1 seats are left on this departure.');

    expect(Booking::query()->count())->toBe(0)
        ->and($departure->refresh()->booked_slots)->toBe(9);
});

test('a booking form filled in by a bot (honeypot) is refused', function () {
    publishSite($this->tenant);

    $this->post(portalUrl($this->tenant, '/book/EBC-10'), travellerForm([
        'pax_count' => 1, 'start_date' => now()->addMonth()->toDateString(), 'website' => 'https://spam.example',
    ]))->assertSessionHasErrors('website');

    expect(Booking::query()->count())->toBe(0);
});

test('the balance invoice created later subtracts the online deposit', function () {
    publishSite($this->tenant, ['deposit_percent' => 25]);
    $this->post(portalUrl($this->tenant, '/book/EBC-10'), travellerForm([
        'pax_count' => 1, 'start_date' => now()->addMonth()->toDateString(),
    ]))->assertRedirect();

    $balance = app(BookingInvoicing::class)->createDraft(Booking::query()->sole());

    expect($balance->total)->toBe(75000);
});

test('anyone can buy gift vouchers for several recipients and is sent to the payment page', function () {
    publishSite($this->tenant);

    $response = $this->post(portalUrl($this->tenant, '/gift-vouchers'), [
        'amount' => 150,
        'purchaser' => ['first_name' => 'Asha', 'last_name' => 'Gurung', 'email' => 'asha@example.com', 'phone' => '+9779800000001'],
        'recipients' => [
            ['first_name' => 'Ram', 'last_name' => 'Thapa', 'email' => 'ram@example.com', 'phone' => '+9779800000002'],
            ['first_name' => 'Sita', 'last_name' => 'Rai', 'email' => 'sita@example.com', 'phone' => '+9779800000003'],
        ],
        'terms' => '1',
    ]);

    $invoice = Invoice::query()->sole();

    expect($invoice->isGiftVoucherPurchase())->toBeTrue()
        ->and($invoice->total)->toBe(30000)
        ->and($invoice->items()->count())->toBe(2)
        ->and(Customer::query()->where('email', 'asha@example.com')->exists())->toBeTrue()
        ->and(GiftVoucher::query()->count())->toBe(0);

    expect($response->assertRedirect()->headers->get('Location'))->toContain("/pay/{$invoice->id}");
});

test('gift vouchers need unique recipient emails', function () {
    publishSite($this->tenant);

    $this->post(portalUrl($this->tenant, '/gift-vouchers'), [
        'amount' => 100,
        'purchaser' => ['first_name' => 'Asha', 'last_name' => 'Gurung', 'email' => 'asha@example.com', 'phone' => '+9779800000001'],
        'recipients' => [
            ['first_name' => 'Ram', 'last_name' => 'Thapa', 'email' => 'ram@example.com', 'phone' => '+9779800000002'],
            ['first_name' => 'Ram', 'last_name' => 'Again', 'email' => 'ram@example.com', 'phone' => '+9779800000003'],
        ],
        'terms' => '1',
    ])->assertSessionHasErrors('recipients');

    expect(Invoice::query()->count())->toBe(0);
});
