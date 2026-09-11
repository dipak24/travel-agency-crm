<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

function publicPayTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function publicPayInvoice(Customer $customer, array $overrides = []): Invoice
{
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Manaslu Circuit']);

    return Invoice::query()->create(array_merge([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 60000,
        'total' => 60000,
        'currency' => 'USD',
        'status' => 'issued',
    ], $overrides));
}

test('the public pay page is not reachable without a valid signature', function () {
    $tenant = publicPayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = publicPayInvoice($customer);

    $this->get("/pay/{$invoice->id}")->assertForbidden();
});

test('a validly signed public pay link shows the invoice and only enabled gateways', function () {
    $tenant = publicPayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = publicPayInvoice($customer, ['total' => 50000]);

    $url = URL::temporarySignedRoute('public.pay.show', now()->addDays(14), ['invoice' => $invoice->id]);

    $this->get($url)
        ->assertOk()
        ->assertSee($invoice->invoice_no)
        ->assertSee('No online payment methods');

    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'paypal',
        'enabled' => true,
        'credentials' => ['mode' => 'sandbox', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'],
    ]);

    $this->get($url)
        ->assertOk()
        ->assertSee('Pay via PayPal');
});

test('a signed public pay link past its expiry is refused', function () {
    $tenant = publicPayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = publicPayInvoice($customer);

    $url = URL::temporarySignedRoute('public.pay.show', now()->subDay(), ['invoice' => $invoice->id]);

    $this->get($url)->assertForbidden();
});

test('starting a public checkout redirects to the gateway and works without any login', function () {
    $tenant = publicPayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'paypal',
        'enabled' => true,
        'credentials' => ['mode' => 'sandbox', 'client_id' => 'id', 'client_secret' => 'secret', 'webhook_id' => 'wh'],
    ]);
    $customer = Customer::factory()->create();
    $invoice = publicPayInvoice($customer, ['total' => 50000]);

    $startUrl = URL::temporarySignedRoute('public.pay.start', now()->addMinutes(30), ['gateway' => 'paypal', 'invoice' => $invoice->id]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token-123'], 200),
        '*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER123',
            'links' => [['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/checkoutnow?token=ORDER123']],
        ], 201),
    ]);

    $this->get($startUrl)->assertRedirect('https://sandbox.paypal.com/checkoutnow?token=ORDER123');
});

test('the public return page reflects success, pending, and failure without needing a login', function () {
    $tenant = publicPayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'hbl',
        'enabled' => true,
        'credentials' => ['mode' => 'uat', 'office_id' => '1', 'api_key' => 'k'],
    ]);
    $customer = Customer::factory()->create();
    $invoice = publicPayInvoice($customer, ['total' => 50000]);

    $this->get(route('public.pay.return', ['gateway' => 'hbl', 'invoice' => $invoice->id]).'?status=failed&orderNo=X')
        ->assertRedirect()
        ->assertSessionHas('payment_error');

    $this->get(route('public.pay.return', ['gateway' => 'hbl', 'invoice' => $invoice->id]).'?status=success&orderNo=NOTFOUNDYET')
        ->assertRedirect()
        ->assertSessionHas('payment_pending');
});
