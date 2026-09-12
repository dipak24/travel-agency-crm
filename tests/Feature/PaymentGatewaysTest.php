<?php

use App\Filament\Portal\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Tenant\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantUser;
use App\Services\PaymentGateways\PayPalGateway;
use App\Support\TenantContext;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function paymentGatewayTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function paymentGatewayInvoice(Customer $customer, array $overrides = []): Invoice
{
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Everest Base Camp']);

    return Invoice::query()->create(array_merge([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 100000,
        'total' => 100000,
        'currency' => 'USD',
        'status' => 'issued',
    ], $overrides));
}

function enablePaypalFor(Tenant $tenant, array $overrides = []): TenantPaymentGateway
{
    return TenantPaymentGateway::query()->create(array_merge([
        'tenant_id' => $tenant->id,
        'gateway' => 'paypal',
        'enabled' => true,
        'credentials' => [
            'mode' => 'sandbox',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-secret',
            'webhook_id' => 'test-webhook-id',
        ],
    ], $overrides));
}

test('the pay via paypal action is only visible while an invoice has a balance due and paypal is enabled', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $unpaid = paymentGatewayInvoice($customer, ['total' => 50000]);
    $paid = paymentGatewayInvoice($customer, ['total' => 0]);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')
        ->test(ViewInvoice::class, ['record' => $unpaid->getRouteKey()])
        ->assertActionHidden('payViaPaypal');

    enablePaypalFor($tenant);

    Livewire::actingAs($customer, 'customer')
        ->test(ViewInvoice::class, ['record' => $unpaid->getRouteKey()])
        ->assertActionVisible('payViaPaypal');

    Livewire::actingAs($customer, 'customer')
        ->test(ViewInvoice::class, ['record' => $paid->getRouteKey()])
        ->assertActionHidden('payViaPaypal');
});

test('starting paypal checkout redirects the customer to paypals approval url', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    enablePaypalFor($tenant);
    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer, ['total' => 50000]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token-123'], 200),
        '*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER123',
            'links' => [['rel' => 'approve', 'href' => 'https://sandbox.paypal.com/checkoutnow?token=ORDER123']],
        ], 201),
    ]);

    $this->actingAs($customer, 'customer')
        ->get("/portal/pay/paypal/{$invoice->id}/start")
        ->assertRedirect('https://sandbox.paypal.com/checkoutnow?token=ORDER123');
});

test('starting checkout for a gateway the tenant has not enabled is refused', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer, ['total' => 50000]);

    $this->actingAs($customer, 'customer')
        ->get("/portal/pay/paypal/{$invoice->id}/start")
        ->assertRedirect(route('filament.portal.resources.invoices.view', ['record' => $invoice->id]))
        ->assertSessionHas('payment_error');
});

test('a customer cannot start checkout for another customers invoice', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    enablePaypalFor($tenant);
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($otherCustomer);

    $this->actingAs($customer, 'customer')
        ->get("/portal/pay/paypal/{$invoice->id}/start")
        ->assertForbidden();
});

test('returning from paypal captures the order and records a completed payment', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    enablePaypalFor($tenant);
    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer, ['total' => 50000]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token-123'], 200),
        '*/v2/checkout/orders/ORDER123/capture' => Http::response([
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => ['captures' => [[
                    'id' => 'CAPTURE123',
                    'amount' => ['value' => '500.00', 'currency_code' => 'USD'],
                ]]],
            ]],
        ], 201),
    ]);

    $this->actingAs($customer, 'customer')
        ->get("/portal/pay/paypal/{$invoice->id}/return?token=ORDER123")
        ->assertRedirect();

    $payment = Payment::query()->withoutGlobalScopes()->where('transaction_ref', 'CAPTURE123')->first();

    expect($payment)->not->toBeNull()
        ->and($payment->amount)->toBe(50000)
        ->and($payment->status)->toBe('completed')
        ->and($payment->method)->toBe('paypal')
        ->and($invoice->refresh()->status)->toBe('paid');
});

test('a paypal webhook with a valid signature records a completed payment idempotently', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    enablePaypalFor($tenant);
    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer, ['total' => 50000]);
    app(TenantContext::class)->clear();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token-123'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
    ]);

    $payload = [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE999',
            'custom_id' => (string) $invoice->id,
            'amount' => ['value' => '500.00', 'currency_code' => 'USD'],
        ],
    ];

    $this->postJson("/webhooks/paypal/{$invoice->id}", $payload)->assertOk();
    $this->postJson("/webhooks/paypal/{$invoice->id}", $payload)->assertOk();

    expect(Payment::query()->withoutGlobalScopes()->where('transaction_ref', 'CAPTURE999')->count())->toBe(1);
});

test('a paypal webhook with an invalid signature is rejected and records nothing', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    enablePaypalFor($tenant);
    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer);
    app(TenantContext::class)->clear();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token-123'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'], 200),
    ]);

    $this->postJson("/webhooks/paypal/{$invoice->id}", [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => ['id' => 'CAPTUREBAD', 'custom_id' => (string) $invoice->id, 'amount' => ['value' => '1.00', 'currency_code' => 'USD']],
    ])->assertStatus(400);

    expect(Payment::query()->withoutGlobalScopes()->where('transaction_ref', 'CAPTUREBAD')->exists())->toBeFalse();
});

test('a webhook for an unknown invoice is rejected with a 404 rather than crashing', function () {
    $this->postJson('/webhooks/paypal/999999', ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])
        ->assertStatus(404);
});

test('a webhook for an unknown gateway key is rejected with a 400 rather than crashing', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer);
    app(TenantContext::class)->clear();

    $this->postJson("/webhooks/not-a-real-gateway/{$invoice->id}", ['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])
        ->assertStatus(400);
});

test('refunding a completed paypal payment calls the gateway and records a refund entry', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    enablePaypalFor($tenant);
    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer, ['total' => 50000]);
    $payment = $invoice->payments()->create([
        'amount' => 50000, 'currency' => 'USD', 'method' => 'paypal', 'type' => 'installment',
        'status' => 'completed', 'transaction_ref' => 'CAPTURE555', 'paid_at' => now(),
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token-123'], 200),
        '*/v2/payments/captures/CAPTURE555/refund' => Http::response(['id' => 'REFUND555'], 201),
    ]);

    $refund = app(PayPalGateway::class)->refund($payment);

    expect($refund->type)->toBe('refund')
        ->and($refund->amount)->toBe(50000)
        ->and($refund->transaction_ref)->toBe('REFUND555')
        ->and($invoice->refresh()->balanceDue())->toBe(50000)
        ->and($invoice->status)->toBe('issued');
});

test('the tenant panel refund action is only offered for completed gateway payments and staff who can update invoices', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    (new PermissionSeeder)->run();
    app(TenantContext::class)->set($tenant);
    enablePaypalFor($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer, ['total' => 50000]);
    $paypalPayment = $invoice->payments()->create([
        'amount' => 50000, 'currency' => 'USD', 'method' => 'paypal', 'type' => 'installment',
        'status' => 'completed', 'transaction_ref' => 'CAPTURE777', 'paid_at' => now(),
    ]);
    $cashPayment = $invoice->payments()->create([
        'amount' => 20000, 'currency' => 'USD', 'method' => 'cash', 'type' => 'installment',
        'status' => 'completed', 'paid_at' => now(),
    ]);

    Filament::setCurrentPanel('tenant');

    $test = Livewire::actingAs($owner, 'tenant')->test(ListPayments::class);
    $test->assertActionVisible(TestAction::make('refund')->table($paypalPayment));
    $test->assertActionHidden(TestAction::make('refund')->table($cashPayment));

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'token-123'], 200),
        '*/v2/payments/captures/CAPTURE777/refund' => Http::response(['id' => 'REFUND777'], 201),
    ]);

    $test->callAction(TestAction::make('refund')->table($paypalPayment));

    expect(Payment::query()->where('transaction_ref', 'REFUND777')->exists())->toBeTrue();
});

test('staff can toggle a payment between reconciled and unreconciled', function () {
    $tenant = paymentGatewayTenant('Northwind Travel', 'northwind-travel');
    (new PermissionSeeder)->run();
    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $ownerRole = Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail();
    $owner->assignRole($ownerRole);

    $customer = Customer::factory()->create();
    $invoice = paymentGatewayInvoice($customer, ['total' => 50000]);
    $payment = $invoice->payments()->create([
        'amount' => 50000, 'currency' => 'USD', 'method' => 'bank_transfer', 'type' => 'installment',
        'status' => 'completed', 'paid_at' => now(),
    ]);

    Filament::setCurrentPanel('tenant');

    $test = Livewire::actingAs($owner, 'tenant')->test(ListPayments::class);
    $test->callAction(TestAction::make('toggleReconciled')->table($payment));
    expect($payment->refresh()->reconciled_at)->not->toBeNull();

    $test->callAction(TestAction::make('toggleReconciled')->table($payment));
    expect($payment->refresh()->reconciled_at)->toBeNull();
});
