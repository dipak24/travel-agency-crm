<?php

use App\Filament\Portal\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Portal\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GiftVoucher;
use App\Models\Invoice;
use App\Models\PromoCode;
use App\Models\Tenant;
use App\Services\InvoiceGiftVoucherRedemption;
use App\Services\InvoicePromoRedemption;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;

uses(RefreshDatabase::class);

function portalInvoicesTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function portalInvoiceFor(Customer $customer, array $overrides = []): Invoice
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

test('a customer only sees their own non-draft invoices', function () {
    $tenant = portalInvoicesTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $otherCustomer = Customer::factory()->create();

    $ownInvoice = portalInvoiceFor($customer);
    portalInvoiceFor($customer, ['status' => 'draft']);
    portalInvoiceFor($otherCustomer);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(ListInvoices::class)
        ->assertCanSeeTableRecords([$ownInvoice])
        ->assertCountTableRecords(1);
});

test('a customer can view their invoice items and payments', function () {
    $tenant = portalInvoicesTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $invoice = portalInvoiceFor($customer);
    $invoice->items()->create(['description' => 'Package deposit', 'qty' => 1, 'unit_price' => 100000, 'total' => 100000]);
    $invoice->payments()->create(['amount' => 50000, 'currency' => 'USD', 'method' => 'card', 'type' => 'installment', 'status' => 'completed']);

    $this->actingAs($customer, 'customer')
        ->get("/portal/invoices/{$invoice->id}")
        ->assertOk()
        ->assertSee('Package deposit')
        ->assertSee('card');
});

test('a customer can download their invoice as a PDF', function () {
    $tenant = portalInvoicesTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['password' => 'secret-password']);
    $invoice = portalInvoiceFor($customer);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')
        ->test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction(TestAction::make('downloadPdf'))
        ->assertFileDownloaded("{$invoice->invoice_no}.pdf");
});

test('applying a valid percent promo code discounts the invoice total and is blocked from reuse on the same invoice', function () {
    $tenant = portalInvoicesTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = portalInvoiceFor($customer, ['total' => 100000]);
    $promo = PromoCode::query()->create([
        'code' => 'SAVE10',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'is_active' => true,
    ]);

    app(InvoicePromoRedemption::class)->apply($invoice, 'save10');

    $invoice->refresh();
    expect($invoice->total)->toBe(90000)
        ->and($invoice->discount)->toBe(10000)
        ->and($promo->refresh()->used_count)->toBe(1);

    expect(fn () => app(InvoicePromoRedemption::class)->apply($invoice, 'SAVE10'))
        ->toThrow(LogicException::class, 'That promo code has already been applied to this invoice.');
});

test('an inactive, expired, or usage-exhausted promo code is rejected', function () {
    $tenant = portalInvoicesTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = portalInvoiceFor($customer);

    PromoCode::query()->create(['code' => 'OFFLINE', 'discount_type' => 'flat', 'discount_value' => 5000, 'is_active' => false]);
    PromoCode::query()->create(['code' => 'EXPIRED', 'discount_type' => 'flat', 'discount_value' => 5000, 'is_active' => true, 'valid_until' => now()->subDay()]);
    PromoCode::query()->create(['code' => 'MAXEDOUT', 'discount_type' => 'flat', 'discount_value' => 5000, 'is_active' => true, 'usage_limit' => 1, 'used_count' => 1]);

    expect(fn () => app(InvoicePromoRedemption::class)->apply($invoice, 'OFFLINE'))->toThrow(LogicException::class)
        ->and(fn () => app(InvoicePromoRedemption::class)->apply($invoice, 'EXPIRED'))->toThrow(LogicException::class)
        ->and(fn () => app(InvoicePromoRedemption::class)->apply($invoice, 'MAXEDOUT'))->toThrow(LogicException::class);
});

test('redeeming a gift voucher creates a completed payment credit and updates the invoice balance', function () {
    $tenant = portalInvoicesTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = portalInvoiceFor($customer, ['total' => 100000]);
    $voucher = GiftVoucher::query()->create(['code' => 'GIFT50', 'value' => 40000, 'status' => 'unredeemed']);

    $payment = app(InvoiceGiftVoucherRedemption::class)->redeem($invoice, 'gift50');

    expect($payment->amount)->toBe(40000)
        ->and($payment->method)->toBe('gift_voucher')
        ->and($payment->status)->toBe('completed')
        ->and($invoice->refresh()->status)->toBe('partially_paid')
        ->and($invoice->balanceDue())->toBe(60000)
        ->and($voucher->refresh()->status)->toBe('redeemed')
        ->and($voucher->value)->toBe(0);
});

test('a gift voucher issued to a specific customer cannot be redeemed by another customer', function () {
    $tenant = portalInvoicesTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $otherCustomer = Customer::factory()->create();
    $invoice = portalInvoiceFor($customer);
    $voucher = GiftVoucher::query()->create([
        'code' => 'PRIVATE1', 'value' => 20000, 'status' => 'unredeemed', 'issued_to' => $otherCustomer->id,
    ]);

    expect(fn () => app(InvoiceGiftVoucherRedemption::class)->redeem($invoice, 'PRIVATE1'))
        ->toThrow(LogicException::class, 'That gift voucher was issued to a different customer.');
});

test('a gift voucher cannot be redeemed twice on the same invoice', function () {
    $tenant = portalInvoicesTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = portalInvoiceFor($customer, ['total' => 100000]);
    GiftVoucher::query()->create(['code' => 'ONCEONLY', 'value' => 10000, 'status' => 'unredeemed']);

    app(InvoiceGiftVoucherRedemption::class)->redeem($invoice, 'ONCEONLY');

    expect(fn () => app(InvoiceGiftVoucherRedemption::class)->redeem($invoice, 'ONCEONLY'))
        ->toThrow(LogicException::class, 'That gift voucher is not valid or has already been fully redeemed.');
});
