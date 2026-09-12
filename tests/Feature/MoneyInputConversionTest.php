<?php

use App\Filament\Tenant\Resources\BookingResource\Pages\CreateBooking;
use App\Filament\Tenant\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Filament\Tenant\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Tenant\Resources\PaymentResource\Pages\CreatePayment;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * End-to-end proof for the fix requested at /tenant/invoices/create: staff type a normal dollar
 * amount ("100.00"), the database still stores integer cents (10000), and the UI reads it back as
 * "100.00" — never raw cents — via App\Filament\Forms\Components\MoneyInput +
 * App\Support\Money. tests/Unit/MoneyTest.php covers the conversion math in isolation; these
 * cover the actual Filament forms/tables wired to it.
 */
function micTenant(string $name, string $slug): Tenant
{
    $tenant = Tenant::query()->create(['name' => $name, 'slug' => $slug, 'currency' => 'USD']);
    (new PermissionSeeder)->run();

    return $tenant;
}

function micOwner(Tenant $tenant): TenantUser
{
    $owner = TenantUser::factory()->create(['tenant_id' => $tenant->id]);
    $owner->assignRole(Role::firstOrCreate(['name' => 'Tenant Owner', 'guard_name' => 'tenant', 'team_id' => $tenant->id]));

    return $owner;
}

test('creating an invoice with a dollar amount stores cents in the database', function () {
    $tenant = micTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = micOwner($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alps Trek']);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateInvoice::class)
        ->set('data.booking_id', $booking->id)
        ->set('data.amount', '100.00')
        ->set('data.total', '100.00')
        ->set('data.status', 'issued')
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->where('booking_id', $booking->id)->firstOrFail();

    expect($invoice->amount)->toBe(10000)
        ->and($invoice->total)->toBe(10000);
});

test('editing that invoice shows the dollar amount back, not raw cents', function () {
    $tenant = micTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = micOwner($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alps Trek']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id, 'customer_id' => $customer->id,
        'amount' => 10000, 'total' => 10000, 'currency' => 'USD', 'status' => 'issued',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(EditInvoice::class, ['record' => $invoice->getKey()])
        ->assertSet('data.amount', 100.0)
        ->assertSet('data.total', 100.0);
});

test('a booking\'s total amount round-trips through dollars in the create form', function () {
    $tenant = micTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = micOwner($tenant);
    $customer = Customer::factory()->create();

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreateBooking::class)
        ->set('data.customer_id', $customer->id)
        ->set('data.trip_name', 'Alps Trek')
        ->set('data.total_amount', '250.50')
        ->set('data.status', 'confirmed')
        ->call('create')
        ->assertHasNoFormErrors();

    $booking = Booking::query()->where('trip_name', 'Alps Trek')->firstOrFail();

    expect($booking->total_amount)->toBe(25050);
});

test('recording a payment with a dollar amount stores cents', function () {
    $tenant = micTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = micOwner($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alps Trek']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id, 'customer_id' => $customer->id,
        'total' => 20000, 'currency' => 'USD', 'status' => 'issued',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreatePayment::class)
        ->set('data.invoice_id', $invoice->id)
        ->set('data.amount', '75.25')
        ->set('data.method', 'cash')
        ->call('create')
        ->assertHasNoFormErrors();

    $payment = Payment::query()->where('invoice_id', $invoice->id)->firstOrFail();

    expect($payment->amount)->toBe(7525);
});

test('the payment amount auto-fills from the invoice balance due, converted to dollars', function () {
    $tenant = micTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $owner = micOwner($tenant);
    $customer = Customer::factory()->create();
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Alps Trek']);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id, 'customer_id' => $customer->id,
        'total' => 33300, 'currency' => 'USD', 'status' => 'issued',
    ]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(CreatePayment::class)
        ->set('data.invoice_id', $invoice->id)
        ->assertSet('data.amount', 333.0);
});
