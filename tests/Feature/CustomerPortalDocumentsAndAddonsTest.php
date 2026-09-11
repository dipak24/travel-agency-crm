<?php

use App\Filament\Portal\Resources\BookingResource\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Notifications\BookingDocumentReviewed;
use App\Notifications\BookingStatusChanged;
use App\Services\BookingAddonRequest;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use LogicException;

uses(RefreshDatabase::class);

function docsAddonsTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

test('a customer can see the upload/request actions and their existing documents read-only', function () {
    $tenant = docsAddonsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);
    BookingDocument::query()->create([
        'booking_id' => $booking->id,
        'doc_type' => 'passport',
        'file_path' => 'booking-documents/passport.pdf',
        'status' => 'rejected',
        'rejection_reason' => 'Blurry scan',
        'uploaded_by' => $customer->email,
    ]);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionExists('uploadDocument')
        ->assertSee('rejected')
        ->assertSee('Blurry scan');
});

test('staff rejecting a booking document notifies the customer', function () {
    Notification::fake();

    $tenant = docsAddonsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);
    $document = BookingDocument::query()->create([
        'booking_id' => $booking->id,
        'doc_type' => 'passport',
        'file_path' => 'booking-documents/passport.pdf',
        'status' => 'pending',
        'uploaded_by' => $customer->email,
    ]);

    $document->update(['status' => 'rejected', 'rejection_reason' => 'Expired passport']);

    Notification::assertSentTo($customer, BookingDocumentReviewed::class);
});

test('staff changing a booking status notifies the customer', function () {
    Notification::fake();

    $tenant = docsAddonsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp', 'status' => 'pending']);

    $booking->update(['status' => 'confirmed']);

    Notification::assertSentTo($customer, BookingStatusChanged::class);
});

test('unrelated booking updates do not spam a status-change notification', function () {
    Notification::fake();

    $tenant = docsAddonsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp', 'status' => 'pending']);

    $booking->update(['description' => 'Updated itinerary notes']);

    Notification::assertNotSentTo($customer, BookingStatusChanged::class);
});

test('requesting an add-on with no inventory limit always succeeds', function () {
    $tenant = docsAddonsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);
    $service = Service::query()->create(['name' => 'Airport transfer', 'price' => 2500, 'is_active' => true]);

    $addon = app(BookingAddonRequest::class)->request($booking, $service, 2, $customer->email);

    expect($addon->quantity)->toBe(2)
        ->and($addon->price)->toBe(5000)
        ->and($addon->status)->toBe('requested')
        ->and($addon->added_by)->toBe($customer->email);
});

test('requesting an add-on for an inactive service is rejected', function () {
    $tenant = docsAddonsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);
    $service = Service::query()->create(['name' => 'KTM City Tour', 'price' => 4000, 'is_active' => false]);

    expect(fn () => app(BookingAddonRequest::class)->request($booking, $service, 1, $customer->email))
        ->toThrow(LogicException::class, 'This add-on is no longer available.');
});
