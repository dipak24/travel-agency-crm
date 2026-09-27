<?php

use App\Filament\Resources\SubscriptionPlanResource\Pages\EditSubscriptionPlan;
use App\Filament\Tenant\Pages\Reports;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantUser;
use App\Services\PaymentGateways\PayLaterGateway;
use App\Services\PaymentGateways\PayPalGateway;
use App\Support\PlanFeatures;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Switches features off on the seeded demo tenant's plan ("Starter").
 *
 * @param  array<string, bool>  $features
 */
function setStarterPlanFeatures(?array $features): void
{
    SubscriptionPlan::query()->where('name', 'Starter')->update(['features' => $features === null ? null : json_encode($features)]);
}

function demoTenant(): Tenant
{
    return Tenant::query()->where('slug', 'demo-travel')->firstOrFail();
}

function demoOwner(): TenantUser
{
    return TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();
}

test('a super admin can switch plan features off and saved plans reload them', function () {
    $this->seed();
    Filament::setCurrentPanel('admin');
    $plan = SubscriptionPlan::query()->where('name', 'Starter')->firstOrFail();
    $admin = SuperAdmin::query()->where('email', 'admin@example.com')->firstOrFail();

    Livewire::actingAs($admin, 'super_admin')->test(EditSubscriptionPlan::class, ['record' => $plan->getRouteKey()])
        ->assertSet('data.features.reports', true)
        ->set('data.features.reports', false)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->refresh()->features[PlanFeatures::REPORTS])->toBeFalse()
        ->and($plan->features[PlanFeatures::EMAIL_CAMPAIGNS])->toBeTrue();
});

test('a plan saved before feature toggles existed allows every feature', function () {
    $this->seed();
    setStarterPlanFeatures(null);

    foreach (array_keys(PlanFeatures::ALL) as $feature) {
        expect(PlanFeatures::allows(demoTenant(), $feature))->toBeTrue();
    }
});

test('staff cannot open a module their plan switches off, while other modules stay available', function () {
    $this->seed();
    setStarterPlanFeatures([PlanFeatures::REPORTS => false, PlanFeatures::EMAIL_CAMPAIGNS => false]);

    $this->actingAsStaff(demoOwner());

    $this->get('/tenant/reports')->assertForbidden();
    $this->get('/tenant/email-campaigns')->assertForbidden();
    $this->get('/tenant/promo-codes')->assertOk();
    expect(Reports::canAccess())->toBeFalse();
});

test('the public API treats an agency whose plan has no public API as not found', function () {
    $this->seed();
    setStarterPlanFeatures([PlanFeatures::PUBLIC_API => false]);

    $this->getJson('/api/v1/packages?tenant=demo-travel')->assertNotFound();
});

test('customers cannot use the portal when the agency plan switches it off', function () {
    $this->seed();
    $customer = Customer::query()->withoutGlobalScopes()->where('email', 'customer@example.com')->firstOrFail();

    expect($customer->canAccessPanel(Filament::getPanel('portal')))->toBeTrue();

    setStarterPlanFeatures([PlanFeatures::CUSTOMER_PORTAL => false]);

    expect($customer->fresh()->canAccessPanel(Filament::getPanel('portal')))->toBeFalse();
});

test('card gateways are unavailable without online payments, but pay later still works', function () {
    $this->seed();
    $tenant = demoTenant();
    setStarterPlanFeatures([PlanFeatures::ONLINE_PAYMENTS => false]);

    app(TenantContext::class)->set($tenant);
    $customer = Customer::query()->where('email', 'customer@example.com')->firstOrFail();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Annapurna Circuit']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id, 'customer_id' => $customer->id,
        'amount' => 50000, 'total' => 50000, 'currency' => 'USD', 'status' => 'issued',
    ]);

    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id, 'gateway' => 'paypal', 'enabled' => true,
        'credentials' => ['mode' => 'sandbox', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'hook'],
    ]);
    TenantPaymentGateway::query()->create(['tenant_id' => $tenant->id, 'gateway' => 'pay_later', 'enabled' => true, 'credentials' => []]);

    expect(app(PayPalGateway::class)->isEnabledFor($invoice))->toBeFalse()
        ->and(app(PayLaterGateway::class)->isEnabledFor($invoice))->toBeTrue();
});
