<?php

use App\Filament\Tenant\Resources\PromoCodeResource\Pages\CreatePromoCode;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PromoCode;
use App\Models\TenantUser;
use App\Services\InvoicePromoRedemption;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;

uses(RefreshDatabase::class);

/**
 * fillForm() doesn't persist state on this project's exact Filament/Livewire
 * combination — see .ai/rules/feature.md. Route around it with direct set().
 */
function fillPromoCodeForm(Testable $test, array $data): Testable
{
    foreach ($data as $key => $value) {
        $test->set("data.{$key}", $value);
    }

    return $test;
}

function promoCodeStaff(): TenantUser
{
    $staff = TenantUser::query()->withoutGlobalScopes()
        ->where('email', 'staff@example.com')
        ->firstOrFail();

    Filament::setCurrentPanel('tenant');
    app(TenantContext::class)->set($staff->tenant);

    return $staff;
}

test('a promo code must be 6-12 uppercase alphanumeric characters and is stored uppercase', function () {
    test()->seed();
    $staff = promoCodeStaff();

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        ['code' => 'sum1', 'discount_type' => 'percent', 'discount_value' => 10],
    )->call('create')->assertHasFormErrors(['code' => 'min']);

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        ['code' => 'save-summer!', 'discount_type' => 'percent', 'discount_value' => 10],
    )->call('create')->assertHasFormErrors(['code' => 'regex']);

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        ['code' => 'summer25', 'discount_type' => 'percent', 'discount_value' => 10],
    )->call('create')->assertHasNoFormErrors();

    expect(PromoCode::query()->where('code', 'SUMMER25')->exists())->toBeTrue();
});

test('a promo code cannot duplicate another code already used in the same tenant', function () {
    test()->seed();
    $staff = promoCodeStaff();

    PromoCode::query()->create(['code' => 'SUMMER25', 'discount_type' => 'percent', 'discount_value' => 10]);

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        ['code' => 'summer25', 'discount_type' => 'percent', 'discount_value' => 5],
    )->call('create')->assertHasFormErrors(['code']);

    expect(PromoCode::query()->where('code', 'SUMMER25')->count())->toBe(1);
});

test('a percent discount must be between 1 and 99', function () {
    test()->seed();
    $staff = promoCodeStaff();

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        ['code' => 'TOOMUCH1', 'discount_type' => 'percent', 'discount_value' => 100],
    )->call('create')->assertHasFormErrors(['discount_value' => 'max']);

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        ['code' => 'VALIDPCT', 'discount_type' => 'percent', 'discount_value' => 99],
    )->call('create')->assertHasNoFormErrors();
});

test('a flat discount is entered in normal currency units in the form and stored as minor units', function () {
    test()->seed();
    $staff = promoCodeStaff();

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        ['code' => 'FLAT2500', 'discount_type' => 'flat', 'discount_value' => 25],
    )->call('create')->assertHasNoFormErrors();

    expect(PromoCode::query()->where('code', 'FLAT2500')->firstOrFail()->discount_value)->toBe(2500);
});

test('valid from and valid until reject past dates and out-of-order ranges', function () {
    test()->seed();
    $staff = promoCodeStaff();

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        [
            'code' => 'PASTDATE',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'valid_from' => now()->subDay()->toDateTimeString(),
        ],
    )->call('create')->assertHasFormErrors(['valid_from']);

    fillPromoCodeForm(
        Livewire::actingAs($staff, 'tenant')->test(CreatePromoCode::class),
        [
            'code' => 'BADRANGE',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'valid_from' => now()->addDays(5)->toDateTimeString(),
            'valid_until' => now()->addDay()->toDateTimeString(),
        ],
    )->call('create')->assertHasFormErrors(['valid_until']);
});

test('a promo code restricted to specific packages only applies to invoices for those packages', function () {
    test()->seed();
    app(TenantContext::class)->set(TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail()->tenant);

    $allowedPackage = Package::query()->create(['name' => 'Everest Base Camp', 'slug' => 'ebc', 'package_code' => 'EBC-1', 'duration_days' => 12]);
    $otherPackage = Package::query()->create(['name' => 'Annapurna Circuit', 'slug' => 'ac', 'package_code' => 'AC-1', 'duration_days' => 14]);

    $promo = PromoCode::query()->create(['code' => 'EBCONLY', 'discount_type' => 'flat', 'discount_value' => 5000, 'is_active' => true]);
    $promo->packages()->attach($allowedPackage);

    $customer = Customer::factory()->create();

    $matchingBooking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'EBC trip', 'package_id' => $allowedPackage->id]);
    $matchingInvoice = Invoice::query()->create(['booking_id' => $matchingBooking->id, 'customer_id' => $customer->id, 'amount' => 100000, 'total' => 100000, 'currency' => 'USD', 'status' => 'issued']);

    app(InvoicePromoRedemption::class)->apply($matchingInvoice, 'EBCONLY');
    expect($matchingInvoice->refresh()->discount)->toBe(5000);

    $mismatchedBooking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'AC trip', 'package_id' => $otherPackage->id]);
    $mismatchedInvoice = Invoice::query()->create(['booking_id' => $mismatchedBooking->id, 'customer_id' => $customer->id, 'amount' => 100000, 'total' => 100000, 'currency' => 'USD', 'status' => 'issued']);

    expect(fn () => app(InvoicePromoRedemption::class)->apply($mismatchedInvoice, 'EBCONLY'))
        ->toThrow(LogicException::class, 'That promo code does not apply to this booking\'s package.');
});
