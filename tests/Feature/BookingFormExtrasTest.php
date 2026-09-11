<?php

use App\Filament\Tenant\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Tenant\Resources\BookingResource\Pages\EditBooking;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingIncludeExclude;
use App\Models\Customer;
use App\Models\IncludeExclude;
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

test('checking catalog items snapshots them onto the booking, and unchecking removes them', function () {
    $tenant = bookingExtrasTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $booking = Booking::query()->create(['customer_id' => Customer::factory()->create()->id, 'trip_name' => 'Everest Base Camp']);
    $transfer = IncludeExclude::query()->create(['type' => 'include', 'title' => 'Airport transfer', 'description' => 'Included']);
    $tips = IncludeExclude::query()->create(['type' => 'exclude', 'title' => 'Tips', 'description' => 'Not included']);

    BookingIncludeExclude::syncForBooking($booking, [$transfer->id, $tips->id]);

    expect($booking->includeExcludes()->count())->toBe(2)
        ->and($booking->includeExcludes()->where('include_exclude_id', $transfer->id)->first()->title)->toBe('Airport transfer')
        ->and($booking->includeExcludes()->where('include_exclude_id', $transfer->id)->first()->type)->toBe('include');

    BookingIncludeExclude::syncForBooking($booking, [$transfer->id]);

    expect($booking->includeExcludes()->count())->toBe(1)
        ->and($booking->includeExcludes()->where('include_exclude_id', $tips->id)->exists())->toBeFalse();
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

test('the booking form has a rich-text itinerary field, a catalog checkbox list, and per-type upload fields', function () {
    $tenant = bookingExtrasTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = bookingExtrasOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateBooking::class)
        ->assertFormFieldExists('booked_itinerary')
        ->assertFormFieldExists('include_exclude_selection')
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
