<?php

use App\Filament\Tenant\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Tenant\Resources\BookingResource\Pages\EditBooking;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingIncludeExclude;
use App\Models\Customer;
use App\Models\IncludeExclude;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function bookingExtrasTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function bookingExtrasOwner(Tenant $tenant): TenantUser
{
    $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $role = Role::firstOrCreate(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $owner->assignRole($role);

    return $owner;
}

test('syncing rows snapshots catalog items onto the booking, and a later sync replaces them', function () {
    $tenant = bookingExtrasTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $booking = Booking::query()->create(['customer_id' => Customer::factory()->create()->id, 'trip_name' => 'Everest Base Camp']);
    $transfer = IncludeExclude::query()->create(['type' => 'include', 'title' => 'Airport transfer', 'description' => 'Included', 'unit_price' => 3000]);
    $tips = IncludeExclude::query()->create(['type' => 'exclude', 'title' => 'Tips', 'description' => 'Not included']);

    BookingIncludeExclude::syncRows($booking, [
        BookingIncludeExclude::rowFromCatalog($transfer),
        BookingIncludeExclude::rowFromCatalog($tips, 'exclude'),
    ]);

    $transferRow = $booking->includeExcludes()->where('include_exclude_id', $transfer->id)->first();

    expect($booking->includeExcludes()->count())->toBe(2)
        ->and($transferRow->title)->toBe('Airport transfer')
        ->and($transferRow->type)->toBe('include')
        ->and($transferRow->unit_price)->toBe(3000);

    BookingIncludeExclude::syncRows($booking, [BookingIncludeExclude::rowFromCatalog($tips, 'exclude')]);

    expect($booking->includeExcludes()->count())->toBe(1)
        ->and($booking->includeExcludes()->where('include_exclude_id', $transfer->id)->exists())->toBeFalse();
});

test('syncing a document type creates new pending documents and removes ones no longer present', function () {
    $tenant = bookingExtrasTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $booking = Booking::query()->create(['customer_id' => Customer::factory()->create()->id, 'trip_name' => 'Everest Base Camp']);

    BookingDocument::syncForBookingDocType($booking, 'passport', ['booking-documents/p1.pdf', 'booking-documents/p2.pdf'], 'staff@example.com');

    expect($booking->documents()->where('doc_type', 'passport')->count())->toBe(2)
        ->and($booking->documents()->where('file_path', 'booking-documents/p1.pdf')->first()->status)->toBe('pending')
        ->and($booking->documents()->where('file_path', 'booking-documents/p1.pdf')->first()->uploaded_by)->toBe('staff@example.com');

    // p2 removed, p3 added — p1 untouched, p2's row disappears, p3's row is new.
    BookingDocument::syncForBookingDocType($booking, 'passport', ['booking-documents/p1.pdf', 'booking-documents/p3.pdf'], 'staff@example.com');

    $remaining = $booking->documents()->where('doc_type', 'passport')->pluck('file_path')->sort()->values()->all();
    expect($remaining)->toBe(['booking-documents/p1.pdf', 'booking-documents/p3.pdf']);
});

test('syncing one document type never touches another document type\'s rows', function () {
    $tenant = bookingExtrasTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $booking = Booking::query()->create(['customer_id' => Customer::factory()->create()->id, 'trip_name' => 'Everest Base Camp']);

    BookingDocument::syncForBookingDocType($booking, 'passport', ['booking-documents/passport.pdf'], 'staff@example.com');
    BookingDocument::syncForBookingDocType($booking, 'visa', [], 'staff@example.com');

    expect($booking->documents()->where('doc_type', 'passport')->count())->toBe(1);
});

test('the booking form has a rich-text itinerary field, inclusion and exclusion lists, and per-type upload fields', function () {
    $tenant = bookingExtrasTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = bookingExtrasOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateBooking::class)
        ->assertFormFieldExists('booked_itinerary')
        ->assertFormFieldExists('inclusion_rows')
        ->assertFormFieldExists('exclusion_rows')
        ->assertFormFieldExists('document_files.passport')
        ->assertFormFieldExists('document_files.visa');
});

test('the itinerary rich text is saved as-is and survives editing without any structured itinerary coupling', function () {
    test()->seed();
    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();
    app(TenantContext::class)->set($owner->tenant);
    $customer = Customer::factory()->create();

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateBooking::class)
        ->set('data.customer_id', $customer->id)
        ->set('data.trip_name', 'Private Trek')
        ->set('data.start_date', now()->addMonth()->toDateString())
        ->set('data.duration_days', 3)
        ->set('data.status', 'pending')
        ->set('data.booked_itinerary', '<p><strong>Day 1</strong></p><p>Arrival</p>')
        ->call('create')
        ->assertHasNoFormErrors();

    $booking = Booking::query()->where('trip_name', 'Private Trek')->firstOrFail();
    expect($booking->booked_itinerary)->toContain('Day 1')->toContain('Arrival');

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(EditBooking::class, ['record' => $booking->getRouteKey()])
        ->assertOk();
});

test('CST can add catalog items at their own price and custom lines to a booking\'s inclusions and add-ons', function () {
    test()->seed();
    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();
    app(TenantContext::class)->set($owner->tenant);
    $customer = Customer::factory()->create();
    $porter = IncludeExclude::query()->create(['type' => 'exclude', 'title' => 'Porter', 'unit_price' => 2000, 'pricing_unit' => 'per_day']);
    $skydiving = Service::factory()->create(['name' => 'Skydiving', 'price' => 40000]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateBooking::class)
        ->set('data.customer_id', $customer->id)
        ->set('data.trip_name', 'Custom Trek')
        ->set('data.start_date', now()->addMonth()->toDateString())
        ->set('data.duration_days', 3)
        ->set('data.pax_count', 2)
        ->set('data.per_person_price', '100.00')
        ->set('data.status', 'pending')
        ->set('data.inclusion_rows', [
            'row-0' => ['include_exclude_id' => null, 'title' => 'Welcome dinner', 'selected' => true, 'unit_price' => '20.00', 'pricing_unit' => 'per_person', 'default_included' => null],
        ])
        ->set('data.exclusion_rows', [
            'row-0' => ['include_exclude_id' => $porter->id, 'title' => 'Renamed porter', 'selected' => true, 'unit_price' => '15.00', 'pricing_unit' => 'per_trip', 'default_included' => null],
        ])
        ->set('data.addons', [
            'a' => ['service_id' => null, 'name' => 'Photo package', 'quantity' => 1, 'unit_price' => '50.00', 'price' => '50.00', 'status' => 'approved'],
            'b' => ['service_id' => $skydiving->id, 'name' => 'Tampered name', 'quantity' => 2, 'unit_price' => '400.00', 'price' => '800.00', 'status' => 'approved'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $booking = Booking::query()->where('trip_name', 'Custom Trek')->sole();

    expect($booking->includeExcludes()->orderBy('sort_order')->get()->map->only(['include_exclude_id', 'title', 'pricing_unit', 'unit_price'])->map(fn (array $row): array => [...$row, 'pricing_unit' => $row['pricing_unit']->value])->all())->toBe([
        ['include_exclude_id' => null, 'title' => 'Welcome dinner', 'pricing_unit' => 'per_person', 'unit_price' => 2000],
        ['include_exclude_id' => $porter->id, 'title' => 'Porter', 'pricing_unit' => 'per_day', 'unit_price' => 1500],
    ])
        ->and($booking->addons()->orderBy('id')->get()->map->only(['service_id', 'name', 'price'])->all())->toBe([
            ['service_id' => null, 'name' => 'Photo package', 'price' => 5000],
            ['service_id' => $skydiving->id, 'name' => 'Skydiving', 'price' => 80000],
        ])
        // 2 × 100 + dinner 20 × 2 + porter 15 × 3 days + add-ons 50 + 800
        ->and($booking->refresh()->total_amount)->toBe(20000 + 4000 + 4500 + 5000 + 80000);
});
