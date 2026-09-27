<?php

use App\Enums\BookingType;
use App\Enums\PricingUnit;
use App\Filament\Tenant\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Tenant\Resources\BookingResource\Pages\EditBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FixedDeparture;
use App\Models\IncludeExclude;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PackageIncludeExclude;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::query()->create(['name' => 'Himalayan Trails', 'slug' => 'himalayan-trails']);
    $this->seed();
    app(TenantContext::class)->set($this->tenant);
    $this->owner = TenantUser::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->owner->assignRole(Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $this->tenant->id)->firstOrFail());
    $this->customer = Customer::factory()->create();
    $this->package = Package::factory()->create(['name' => 'Everest Base Camp', 'sales_price' => 100000, 'duration_days' => 12]);

    Filament::setCurrentPanel('tenant');
});

test('a fixed-group booking takes its dates and price from the departure and reserves seats', function () {
    $departure = FixedDeparture::factory()->create(['package_id' => $this->package->id, 'total_slots' => 8, 'price_override' => 90000]);

    Livewire::actingAs($this->owner, 'tenant')->test(CreateBooking::class)
        ->set('data.booking_type', BookingType::FixedGroup->value)
        ->set('data.customer_id', $this->customer->id)
        ->set('data.fixed_departure_id', $departure->id)
        ->assertSet('data.package_id', $this->package->id)
        ->assertSet('data.trip_name', 'Everest Base Camp')
        ->assertSet('data.start_date', $departure->start_date->toDateString())
        ->assertSet('data.duration_days', 12)
        ->assertSet('data.per_person_price', 900.0)
        ->set('data.pax_count', 3)
        ->call('create')
        ->assertHasNoFormErrors();

    $booking = Booking::query()->sole();

    expect($booking->booking_type)->toBe(BookingType::FixedGroup)
        ->and($booking->package_id)->toBe($this->package->id)
        ->and($booking->start_date->toDateString())->toBe($departure->start_date->toDateString())
        ->and($booking->end_date->toDateString())->toBe($departure->end_date->toDateString())
        ->and($booking->total_amount)->toBe(270000)
        ->and($departure->refresh()->booked_slots)->toBe(3);
});

test('changing pax on a fixed-group booking moves seats, and more pax than seats left is rejected', function () {
    $departure = FixedDeparture::factory()->create(['package_id' => $this->package->id, 'total_slots' => 6, 'booked_slots' => 2]);
    $booking = Booking::factory()->create([
        'booking_type' => BookingType::FixedGroup, 'package_id' => $this->package->id, 'fixed_departure_id' => $departure->id,
        'customer_id' => $this->customer->id, 'pax_count' => 2, 'per_person_price' => 100000,
    ]);

    Livewire::actingAs($this->owner, 'tenant')->test(EditBooking::class, ['record' => $booking->getRouteKey()])
        ->set('data.pax_count', 4)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($departure->refresh()->booked_slots)->toBe(4)
        ->and($booking->refresh()->total_amount)->toBe(400000);

    Livewire::actingAs($this->owner, 'tenant')->test(EditBooking::class, ['record' => $booking->getRouteKey()])
        ->set('data.pax_count', 7)
        ->call('save')
        ->assertHasErrors(['data.pax_count']);

    expect($departure->refresh()->booked_slots)->toBe(4)
        ->and($booking->refresh()->pax_count)->toBe(4);
});

test('a private group reserves no seats and its end date is start date plus days', function () {
    $start = now()->addMonths(2)->startOfDay();

    Livewire::actingAs($this->owner, 'tenant')->test(CreateBooking::class)
        ->set('data.booking_type', BookingType::PrivateGroup->value)
        ->set('data.customer_id', $this->customer->id)
        ->set('data.package_id', $this->package->id)
        ->set('data.start_date', $start->toDateString())
        ->set('data.pax_count', 2)
        ->call('create')
        ->assertHasNoFormErrors();

    $booking = Booking::query()->sole();

    expect($booking->fixed_departure_id)->toBeNull()
        ->and($booking->duration_days)->toBe(12)
        ->and($booking->end_date->toDateString())->toBe($start->copy()->addDays(11)->toDateString());
});

test('a new booking cannot start in the past, and a private group needs at least two travellers', function () {
    Livewire::actingAs($this->owner, 'tenant')->test(CreateBooking::class)
        ->set('data.booking_type', BookingType::Individual->value)
        ->set('data.customer_id', $this->customer->id)
        ->set('data.trip_name', 'Custom trip')
        ->set('data.start_date', now()->subDay()->toDateString())
        ->set('data.duration_days', 3)
        ->call('create')
        ->assertHasFormErrors(['start_date']);

    Livewire::actingAs($this->owner, 'tenant')->test(CreateBooking::class)
        ->set('data.booking_type', BookingType::PrivateGroup->value)
        ->set('data.customer_id', $this->customer->id)
        ->set('data.package_id', $this->package->id)
        ->set('data.start_date', now()->addMonth()->toDateString())
        ->set('data.pax_count', 1)
        ->call('create')
        ->assertHasErrors(['data.pax_count']);

    expect(Booking::query()->count())->toBe(0);
});

test('picking a package fills its inclusions, and unticking one lowers the saved total', function () {
    $hotel = IncludeExclude::factory()->create(['title' => 'Hotel in Kathmandu', 'unit_price' => 4500, 'pricing_unit' => PricingUnit::PerPersonPerDay]);
    $porter = IncludeExclude::factory()->exclude()->create(['title' => 'Porter', 'unit_price' => 2000, 'pricing_unit' => PricingUnit::PerDay]);
    PackageIncludeExclude::query()->create(['package_id' => $this->package->id, 'include_exclude_id' => $hotel->id, 'is_included' => true]);
    PackageIncludeExclude::query()->create(['package_id' => $this->package->id, 'include_exclude_id' => $porter->id, 'is_included' => false]);

    $test = Livewire::actingAs($this->owner, 'tenant')->test(CreateBooking::class)
        ->set('data.booking_type', BookingType::Individual->value)
        ->set('data.customer_id', $this->customer->id)
        ->set('data.package_id', $this->package->id)
        ->set('data.start_date', now()->addMonth()->toDateString())
        ->set('data.duration_days', 2)
        ->assertSet('data.trip_name', 'Everest Base Camp')
        ->assertSet('data.per_person_price', 1000.0);

    $inclusions = $test->get('data.inclusion_rows');
    $exclusions = $test->get('data.exclusion_rows');

    expect(array_column($inclusions, 'title'))->toBe(['Hotel in Kathmandu'])
        ->and(array_column($exclusions, 'title'))->toBe(['Porter']);

    $test->set('data.inclusion_rows.'.array_key_first($inclusions).'.selected', false)
        ->set('data.exclusion_rows.'.array_key_first($exclusions).'.selected', true)
        ->call('create')
        ->assertHasNoFormErrors();

    $booking = Booking::query()->sole();

    // 1000 − hotel (45 × 1 pax × 2 days) + porter (20 × 2 days)
    expect($booking->inclusions_adjustment)->toBe(-9000 + 4000)
        ->and($booking->total_amount)->toBe(100000 - 5000)
        ->and($booking->includeExcludes()->where('title', 'Hotel in Kathmandu')->value('type'))->toBe('exclude');
});

test('the booking page offers a payment link only while one of its invoices has a balance due', function () {
    $booking = Booking::factory()->create(['customer_id' => $this->customer->id, 'per_person_price' => 100000]);

    Livewire::actingAs($this->owner, 'tenant')->test(EditBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionHidden('paymentLink');

    Invoice::query()->create([
        'booking_id' => $booking->id, 'customer_id' => $this->customer->id,
        'amount' => 100000, 'total' => 100000, 'currency' => 'USD', 'status' => 'issued',
    ]);

    Livewire::actingAs($this->owner, 'tenant')->test(EditBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionVisible('paymentLink');
});
