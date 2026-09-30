<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\InvoicePaymentLinks;
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
        ->assertSee('Pay USD 500.00 via PayPal');
});

test('pay later is only offered on the booking pages\' payment redirect, never on a staff payment link', function () {
    $tenant = publicPayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $invoice = publicPayInvoice(Customer::factory()->create(), ['total' => 50000]);
    TenantPaymentGateway::query()->create(['tenant_id' => $tenant->id, 'gateway' => 'pay_later', 'enabled' => true]);

    $this->get(app(InvoicePaymentLinks::class)->url($invoice))
        ->assertOk()
        ->assertDontSee('via Pay Later');

    $this->get(app(InvoicePaymentLinks::class)->url($invoice, offerPayLater: true))
        ->assertOk()
        ->assertSee('via Pay Later');
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

test('the public checkout start route is rate limited', function () {
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

    for ($i = 0; $i < 20; $i++) {
        $this->get($startUrl)->assertRedirect('https://sandbox.paypal.com/checkoutnow?token=ORDER123');
    }

    $this->get($startUrl)->assertStatus(429);
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

test('the public pay page shows the trip, the invoice lines, what was already paid and who is being paid', function () {
    $tenant = publicPayTenant('Northwind Travel', 'northwind-travel');
    $tenant->update(['address' => 'Thamel, Kathmandu', 'phone_number' => '+977 1 4000000', 'billing_email' => 'accounts@northwind.test']);
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['name' => 'Asha Gurung']);
    $booking = Booking::query()->create([
        'customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp Trek', 'pax_count' => 2,
        'start_date' => '2026-11-10', 'end_date' => '2026-11-23', 'duration_days' => 14,
    ]);
    $invoice = Invoice::query()->create([
        'booking_id' => $booking->id, 'customer_id' => $customer->id,
        'amount' => 280000, 'total' => 280000, 'currency' => 'USD', 'status' => 'partially_paid', 'due_date' => '2026-10-20',
    ]);
    $invoice->items()->create(['description' => 'Package price (1,400.00 × 2 people)', 'qty' => 1, 'unit_price' => 280000, 'total' => 280000]);
    $invoice->payments()->create(['amount' => 80000, 'currency' => 'USD', 'method' => 'bank_transfer', 'type' => 'installment', 'status' => 'completed', 'paid_at' => '2026-09-20', 'transaction_ref' => 'DEP-1']);

    $url = URL::temporarySignedRoute('public.pay.show', now()->addDays(14), ['invoice' => $invoice->id]);

    $this->get($url)
        ->assertOk()
        ->assertSeeInOrder(['Amount due', 'USD 2,000.00', 'Trip details', 'Everest Base Camp Trek', 'Tue, 10 Nov 2026', 'Mon, 23 Nov 2026', '14 days / 13 nights', '2 people'])
        ->assertSee('Package price (1,400.00 × 2 people)')
        ->assertSee('Bank Transfer')
        ->assertSee('Pay to')
        ->assertSee('Thamel, Kathmandu')
        ->assertSee('accounts@northwind.test')
        ->assertSee('valid until');
});
