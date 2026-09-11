<?php

use App\Filament\Tenant\Pages\PaymentGateways;
use App\Filament\Tenant\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantUser;
use App\Notifications\InvoicePaymentLink;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function pgsTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function pgsOwner(Tenant $tenant): TenantUser
{
    $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $role = Role::firstOrCreate(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $owner->assignRole($role);

    return $owner;
}

test('a tenant owner can save paypal and hbl gateway settings independently, each with its own save button', function () {
    $tenant = pgsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = pgsOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(PaymentGateways::class)
        ->set('paypalData.enabled', true)
        ->set('paypalData.mode', 'live')
        ->set('paypalData.client_id', 'client-123')
        ->set('paypalData.client_secret', 'secret-123')
        ->set('paypalData.webhook_id', 'webhook-123')
        ->call('savePaypal')
        ->assertHasNoFormErrors([], 'paypalForm');

    // HBL was never touched by the PayPal save above — each card persists independently.
    expect(TenantPaymentGateway::query()->where('gateway', 'hbl')->exists())->toBeFalse();

    $paypal = TenantPaymentGateway::query()->where('gateway', 'paypal')->firstOrFail();

    expect($paypal->enabled)->toBeTrue()
        ->and($paypal->credentials['client_id'])->toBe('client-123')
        ->and($paypal->credentials['client_secret'])->toBe('secret-123')
        ->and($paypal->credentials['mode'])->toBe('live');
});

test('saved gateway settings are reloaded correctly on the next visit', function () {
    $tenant = pgsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = pgsOwner($tenant);
    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'paypal',
        'enabled' => true,
        'credentials' => ['mode' => 'live', 'client_id' => 'existing-id', 'client_secret' => 'existing-secret', 'webhook_id' => 'wh-1'],
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(PaymentGateways::class)
        ->assertSet('paypalData.enabled', true)
        ->assertSet('paypalData.client_id', 'existing-id')
        ->assertSet('paypalData.mode', 'live');
});

test('enabling paypal without its required credentials is rejected with clear validation errors', function () {
    $tenant = pgsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = pgsOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(PaymentGateways::class)
        ->set('paypalData.enabled', true)
        ->call('savePaypal')
        ->assertHasFormErrors(['client_id' => 'required', 'client_secret' => 'required'], 'paypalForm');

    expect(TenantPaymentGateway::query()->where('gateway', 'paypal')->exists())->toBeFalse();
});

test('enabling hbl without its required credentials is rejected with clear validation errors', function () {
    $tenant = pgsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = pgsOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(PaymentGateways::class)
        ->set('hblData.enabled', true)
        ->call('saveHbl')
        ->assertHasFormErrors(['office_id' => 'required', 'api_key' => 'required'], 'hblForm');

    expect(TenantPaymentGateway::query()->where('gateway', 'hbl')->exists())->toBeFalse();
});

test('a disabled gateway does not require its credential fields to be filled', function () {
    $tenant = pgsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = pgsOwner($tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(PaymentGateways::class)
        ->set('paypalData.enabled', false)
        ->call('savePaypal')
        ->assertHasNoFormErrors([], 'paypalForm');

    expect(TenantPaymentGateway::query()->where('gateway', 'paypal')->firstOrFail()->enabled)->toBeFalse();
});

test('a staff member without manage settings permission cannot access payment gateway settings', function () {
    $tenant = pgsTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $staff = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $role = Role::create(['name' => 'Sales Agent', 'guard_name' => 'tenant', 'team_id' => $tenant->id]);
    $staff->assignRole($role);

    $this->actingAs($staff, 'tenant');

    expect(PaymentGateways::canAccess())->toBeFalse();
});

test('staff can send a customer a public payment link by email', function () {
    Notification::fake();

    $tenant = pgsTenant('Northwind Travel', 'northwind-travel');
    (new PermissionSeeder)->run();
    app(TenantContext::class)->set($tenant);
    $owner = pgsOwner($tenant);
    $customer = Customer::factory()->create(['email' => 'traveler@example.com']);
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'K2 Base Camp']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 100000,
        'total' => 100000,
        'currency' => 'USD',
        'status' => 'issued',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')
        ->test(ListInvoices::class)
        ->callAction(TestAction::make('sendPaymentLink')->table($invoice));

    Notification::assertSentTo($customer, InvoicePaymentLink::class, function (InvoicePaymentLink $notification) use ($invoice) {
        $signedRequest = Request::create($notification->url);

        return $notification->invoice->is($invoice) && $signedRequest->hasValidSignature();
    });
});

test('the send payment link action is hidden once an invoice is fully paid', function () {
    $tenant = pgsTenant('Northwind Travel', 'northwind-travel');
    (new PermissionSeeder)->run();
    app(TenantContext::class)->set($tenant);
    $owner = pgsOwner($tenant);
    $customer = Customer::factory()->create(['email' => 'traveler@example.com']);
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'K2 Base Camp']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 0,
        'total' => 0,
        'currency' => 'USD',
        'status' => 'paid',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')
        ->test(ListInvoices::class)
        ->assertActionHidden(TestAction::make('sendPaymentLink')->table($invoice));
});
