<?php

use App\Filament\Portal\Resources\BookingResource\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function travelersPortalBooking(string $customerType): Booking
{
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    Filament::setCurrentPanel('portal');

    $customer = Customer::factory()->create(['type' => $customerType, 'password' => 'secret-password']);

    return Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);
}

test('an agency adds travellers with full details, and name, email and nationality are required', function () {
    $booking = travelersPortalBooking('agency');
    $nepali = Country::factory()->create(['name' => 'Nepal', 'nationality' => 'Nepali']);

    Livewire::actingAs($booking->customer, 'customer')
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('addTravelers', data: ['travelers' => [['name' => '', 'email' => '', 'nationality_id' => null]]])
        ->assertHasActionErrors([
            'travelers.0.name' => 'required',
            'travelers.0.email' => 'required',
            'travelers.0.nationality_id' => 'required',
        ]);

    Livewire::actingAs($booking->customer, 'customer')
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('addTravelers', data: ['travelers' => [[
            'name' => 'Ang Dorje',
            'email' => 'ang@example.test',
            'phone' => '+977 9800000000',
            'dob' => '1992-03-04',
            'nationality_id' => $nepali->id,
            'address' => 'Namche Bazaar',
        ]]])
        ->assertHasNoActionErrors();

    $traveler = $booking->travelers()->sole();

    expect($traveler->only(['name', 'email', 'phone', 'nationality_id', 'address']))->toBe([
        'name' => 'Ang Dorje',
        'email' => 'ang@example.test',
        'phone' => '+977 9800000000',
        'nationality_id' => $nepali->id,
        'address' => 'Namche Bazaar',
    ])->and($traveler->dob->toDateString())->toBe('1992-03-04');
});

test('an individual customer cannot manage travellers or their documents', function () {
    $booking = travelersPortalBooking('individual');
    $booking->travelers()->create(['name' => 'Someone', 'document_status' => 'pending']);

    Livewire::actingAs($booking->customer, 'customer')
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionHidden('addTravelers')
        ->assertActionHidden('uploadTravelerDocuments');
});

test('a group leader uploads a document for one traveller, kept apart from the booking-level documents', function () {
    Storage::fake('local');
    $booking = travelersPortalBooking('group_leader');
    $traveler = $booking->travelers()->create(['name' => 'Ang Dorje', 'document_status' => 'pending']);

    Livewire::actingAs($booking->customer, 'customer')
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('uploadTravelerDocuments', data: [
            'booking_traveler_id' => $traveler->id,
            'doc_type' => 'passport',
            'files' => [UploadedFile::fake()->create('passport.pdf', 50, 'application/pdf')],
        ])
        ->assertHasNoActionErrors();

    $document = $booking->documents()->sole();

    expect($document->booking_traveler_id)->toBe($traveler->id)
        ->and($document->doc_type)->toBe('passport')
        ->and($document->status)->toBe('pending');

    $this->actingAs($booking->customer, 'customer')
        ->get(portalUrl($booking->tenant, "/portal/bookings/{$booking->id}"))
        ->assertOk()
        ->assertSeeText('Passport (pending)');
});

test('a traveller from another booking cannot receive uploads through this booking', function () {
    $booking = travelersPortalBooking('agency');
    $otherBooking = Booking::query()->create(['customer_id' => $booking->customer_id, 'trip_name' => 'Langtang']);
    $stranger = $otherBooking->travelers()->create(['name' => 'Elsewhere', 'document_status' => 'pending']);

    expect(fn () => BookingDocument::addCustomerUploads($booking, ['passport' => ['booking-documents/x.pdf']], 'c@example.test', $stranger))
        ->toThrow(LogicException::class);

    expect(BookingDocument::query()->count())->toBe(0);
});

test('included and excluded items are shown in separate sections', function () {
    $booking = travelersPortalBooking('individual');
    $booking->includeExcludes()->create(['type' => 'include', 'title' => 'Airport transfer']);
    $booking->includeExcludes()->create(['type' => 'exclude', 'title' => 'International flights']);

    $this->actingAs($booking->customer, 'customer')
        ->get(portalUrl($booking->tenant, "/portal/bookings/{$booking->id}"))
        ->assertOk()
        ->assertSeeTextInOrder(['What\'s included', 'Airport transfer', 'What\'s not included', 'International flights']);
});
