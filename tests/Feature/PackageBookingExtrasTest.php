<?php

use App\Enums\BookingType;
use App\Filament\Tenant\Resources\BookingResource;
use App\Filament\Tenant\Resources\BookingResource\BookingFormState;
use App\Filament\Tenant\Resources\PackageResource\PackageFormState;
use App\Filament\Tenant\Resources\PackageResource\Pages\CreatePackage;
use App\Filament\Tenant\Resources\PackageResource\Pages\EditPackage;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingIncludeExclude;
use App\Models\IncludeExclude;
use App\Models\Package;
use App\Models\PackageIncludeExclude;
use App\Models\PackageService;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\BookingAddonRequest;
use App\Services\BookingInvoicing;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::query()->create(['name' => 'Himalayan Trails', 'slug' => 'himalayan-trails']);
    app(TenantContext::class)->set($this->tenant);
});

test('a package cannot link another tenant\'s catalog item or service', function () {
    $other = Tenant::query()->create(['name' => 'Other Agency', 'slug' => 'other-agency']);
    [$foreignItem, $foreignService] = app(TenantContext::class)->wrap($other, fn (): array => [
        IncludeExclude::factory()->create(), Service::factory()->create(),
    ]);
    $package = Package::factory()->create();

    expect(fn () => PackageIncludeExclude::query()->create(['package_id' => $package->id, 'include_exclude_id' => $foreignItem->id]))
        ->toThrow(LogicException::class)
        ->and(fn () => PackageService::query()->create(['package_id' => $package->id, 'service_id' => $foreignService->id]))
        ->toThrow(LogicException::class);
});

test('the package form lists the whole catalog and saves the ticked items and services at the package price', function () {
    $this->seed();
    app(TenantContext::class)->set($this->tenant);
    $owner = TenantUser::factory()->create(['tenant_id' => $this->tenant->id]);
    $owner->assignRole(Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $this->tenant->id)->firstOrFail());
    $hotel = IncludeExclude::factory()->create(['title' => 'Hotel', 'type' => 'include', 'unit_price' => 5000]);
    $porter = IncludeExclude::factory()->exclude()->create(['title' => 'Porter', 'unit_price' => 2000]);
    $unused = IncludeExclude::factory()->create(['title' => 'City tour']);
    $skydiving = Service::factory()->create(['name' => 'Skydiving', 'price' => 45000]);
    $rafting = Service::factory()->create(['name' => 'Rafting', 'price' => 9000]);
    Filament::setCurrentPanel('tenant');

    $form = Livewire::actingAs($owner, 'tenant')->test(CreatePackage::class);
    expect(array_keys($form->get('data.'.PackageFormState::INCLUSIONS)))->toEqualCanonicalizing(["item-{$hotel->id}", "item-{$porter->id}", "item-{$unused->id}"])
        ->and($form->get('data.'.PackageFormState::INCLUSIONS.".item-{$porter->id}"))->toMatchArray(['selected' => false, 'placement' => 'exclude', 'unit_price' => 20.0]);

    foreach ([
        'name' => 'Annapurna Circuit', 'slug' => 'annapurna-circuit', 'package_code' => 'ANN-15', 'category' => 'trek',
        'duration_days' => 15, 'base_price' => '900.00', 'sales_price' => '1200.00', 'status' => 'draft',
        PackageFormState::INCLUSIONS.".item-{$hotel->id}.selected" => true,
        PackageFormState::INCLUSIONS.".item-{$hotel->id}.unit_price" => '60.00',
        PackageFormState::INCLUSIONS.".item-{$porter->id}.selected" => true,
        PackageFormState::SERVICES.".service-{$skydiving->id}.selected" => true,
        PackageFormState::SERVICES.".service-{$skydiving->id}.unit_price" => '420.00',
        PackageFormState::SERVICES.".service-{$skydiving->id}.is_featured" => true,
    ] as $field => $value) {
        $form->set("data.{$field}", $value);
    }
    $form->call('create')->assertHasNoFormErrors();

    $package = Package::query()->where('package_code', 'ANN-15')->sole();
    expect($package->includeExcludeItems()->get()->map->only(['include_exclude_id', 'is_included', 'unit_price'])->all())->toBe([
        ['include_exclude_id' => $hotel->id, 'is_included' => true, 'unit_price' => 6000],
        ['include_exclude_id' => $porter->id, 'is_included' => false, 'unit_price' => 2000],
    ])
        ->and($package->serviceLinks()->get()->map->only(['service_id', 'price', 'is_featured'])->all())->toBe([
            ['service_id' => $skydiving->id, 'price' => 42000, 'is_featured' => true],
        ]);

    // Catalog price changes don't touch the package; unticking removes an item.
    $hotel->update(['unit_price' => 9900]);
    $edit = Livewire::actingAs($owner, 'tenant')->test(EditPackage::class, ['record' => $package->getRouteKey()])
        ->assertSet('data.'.PackageFormState::INCLUSIONS.".item-{$hotel->id}.unit_price", 60.0)
        ->set('data.'.PackageFormState::INCLUSIONS.".item-{$porter->id}.selected", false)
        ->set('data.'.PackageFormState::SERVICES.".service-{$rafting->id}.selected", true);
    $edit->call('save')->assertHasNoFormErrors();

    expect($package->includeExcludeItems()->pluck('include_exclude_id')->all())->toBe([$hotel->id])
        ->and($package->serviceLinks()->pluck('price', 'service_id')->all())->toBe([$skydiving->id => 42000, $rafting->id => 9000]);
});

test('catalog booking rows keep the catalog title and unit, while custom rows keep what CST typed', function () {
    $hotel = IncludeExclude::factory()->create(['title' => 'Hotel', 'unit_price' => 5000]);

    $rows = BookingFormState::stateToRows([
        ['include_exclude_id' => $hotel->id, 'title' => 'Renamed by hand', 'pricing_unit' => 'per_trip', 'unit_price' => '45.50', 'selected' => true, 'default_included' => true],
        ['include_exclude_id' => null, 'title' => 'Free-text item', 'pricing_unit' => 'per_trip', 'unit_price' => '10', 'selected' => true, 'default_included' => true],
        ['include_exclude_id' => null, 'title' => '  ', 'unit_price' => '10', 'selected' => true],
    ], []);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'title' => 'Hotel',
            'pricing_unit' => $hotel->pricing_unit->value,
            'unit_price' => 4550,
            'type' => 'include',
            'default_included' => true,
        ])
        ->and($rows[1])->toMatchArray([
            'include_exclude_id' => null,
            'title' => 'Free-text item',
            'pricing_unit' => 'per_trip',
            'unit_price' => 1000,
            'default_included' => null,
        ]);
});

test('an add-on requested on a package booking uses the package price and is listed first', function () {
    $package = Package::factory()->create();
    $skydiving = Service::factory()->create(['name' => 'Skydiving', 'price' => 45000]);
    Service::factory()->create(['name' => 'Rafting']);
    PackageService::query()->create(['package_id' => $package->id, 'service_id' => $skydiving->id, 'price' => 42000]);
    $booking = Booking::factory()->create(['package_id' => $package->id]);

    $addon = app(BookingAddonRequest::class)->request($booking, $skydiving, 2, 'customer@example.com');

    expect($addon->unit_price)->toBe(42000)
        ->and($addon->price)->toBe(84000)
        ->and(array_keys(BookingResource::serviceOptions($package->id)))->toBe(['Recommended for this package', 'All services']);
});

test('an invoice created from a booking matches its total, with reductions as the discount', function () {
    $package = Package::factory()->create(['duration_days' => 2]);
    $booking = Booking::factory()->create([
        'package_id' => $package->id, 'booking_type' => BookingType::Individual,
        'pax_count' => 2, 'duration_days' => 2, 'per_person_price' => 100000,
    ]);
    BookingIncludeExclude::syncRows($booking, [
        ['title' => 'Hotel', 'type' => 'exclude', 'default_included' => true, 'unit_price' => 5000, 'pricing_unit' => 'per_person'],
        ['title' => 'Porter', 'type' => 'include', 'default_included' => false, 'unit_price' => 2000, 'pricing_unit' => 'per_day'],
    ]);

    $invoice = app(BookingInvoicing::class)->createDraft($booking);

    expect($booking->refresh()->total_amount)->toBe(200000 - 10000 + 4000)
        ->and($invoice->total)->toBe($booking->total_amount)
        ->and($invoice->amount)->toBe(204000)
        ->and($invoice->discount)->toBe(10000)
        ->and($invoice->status)->toBe('draft')
        ->and($invoice->items()->pluck('total')->all())->toBe([200000, 4000]);
});

test('staff can download only documents that belong to the booking', function () {
    Storage::fake('local');
    Storage::disk('local')->put('booking-documents/mine.pdf', 'pdf');
    Storage::disk('local')->put('booking-documents/theirs.pdf', 'pdf');
    $booking = Booking::factory()->create();
    $otherBooking = Booking::factory()->create();
    $booking->documents()->create(['doc_type' => 'passport', 'file_path' => 'booking-documents/mine.pdf', 'status' => 'pending', 'uploaded_by' => 'staff']);
    $otherBooking->documents()->create(['doc_type' => 'passport', 'file_path' => 'booking-documents/theirs.pdf', 'status' => 'pending', 'uploaded_by' => 'staff']);

    expect(BookingResource::downloadDocument($booking, 'booking-documents/mine.pdf'))->toBeInstanceOf(StreamedResponse::class)
        ->and(BookingResource::downloadDocument($booking, 'booking-documents/theirs.pdf'))->toBeNull();
});

test('other documents are always optional and capped at five booking-level files', function () {
    $booking = Booking::factory()->create(['document_requirements' => ['passport' => true, 'other' => true]]);

    expect($booking->documentRequirements()['other'])->toBeFalse()
        ->and($booking->requiredDocumentTypes())->not->toContain('other');

    $paths = array_map(fn (int $i): string => "booking-documents/extra-{$i}.pdf", range(1, 7));
    $added = BookingDocument::addCustomerUploads($booking, ['other' => $paths], 'customer@example.com');

    expect($added)->toBe(5)
        ->and(BookingDocument::otherFilesRemaining($booking))->toBe(0)
        ->and(BookingDocument::addCustomerUploads($booking, ['other' => ['booking-documents/late.pdf']], 'customer@example.com'))->toBe(0);
});
