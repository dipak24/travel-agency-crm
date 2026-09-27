<?php

use App\Enums\BookingType;
use App\Filament\Tenant\Resources\BookingResource;
use App\Filament\Tenant\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Tenant\Resources\PackageResource\RelationManagers\IncludeExcludeItemsRelationManager;
use App\Filament\Tenant\Resources\PackageResource\RelationManagers\ServicesRelationManager;
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
use Filament\Actions\Testing\TestAction;
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

test('the package page lists its inclusion items and add-on services for staff', function () {
    $this->seed();
    app(TenantContext::class)->set($this->tenant);
    $owner = TenantUser::factory()->create(['tenant_id' => $this->tenant->id]);
    $owner->assignRole(Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $this->tenant->id)->firstOrFail());
    $package = Package::factory()->create();
    $item = PackageIncludeExclude::query()->create(['package_id' => $package->id, 'include_exclude_id' => IncludeExclude::factory()->create()->id]);
    $link = PackageService::query()->create(['package_id' => $package->id, 'service_id' => Service::factory()->create()->id]);
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(IncludeExcludeItemsRelationManager::class, ['ownerRecord' => $package, 'pageClass' => EditPackage::class])
        ->assertOk()
        ->assertActionExists(TestAction::make('create')->table())
        ->assertCanSeeTableRecords([$item]);

    Livewire::actingAs($owner, 'tenant')->test(ServicesRelationManager::class, ['ownerRecord' => $package, 'pageClass' => EditPackage::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$link]);
});

test('an add-on requested on a package booking uses the package price and is listed first', function () {
    $package = Package::factory()->create();
    $skydiving = Service::factory()->create(['name' => 'Skydiving', 'price' => 45000]);
    Service::factory()->create(['name' => 'Rafting']);
    PackageService::query()->create(['package_id' => $package->id, 'service_id' => $skydiving->id, 'price_override' => 42000]);
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
