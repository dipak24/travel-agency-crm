<?php

use App\Filament\Portal\Pages\BuyGiftVoucher;
use App\Filament\Tenant\Resources\GiftVoucherResource;
use App\Filament\Tenant\Resources\GiftVoucherResource\Pages\ListGiftVouchers;
use App\Models\Customer;
use App\Models\GiftVoucher;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantUser;
use App\Notifications\GiftVoucherPurchased;
use App\Services\GiftVoucherPurchase;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function giftVoucherTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug, 'currency' => 'USD']);
}

test('tenant staff can no longer create a gift voucher — the resource is view/support-only', function () {
    expect(GiftVoucherResource::canCreate())->toBeFalse();

    test()->seed();
    $owner = TenantUser::query()->withoutGlobalScopes()->where('email', 'staff@example.com')->firstOrFail();
    app(TenantContext::class)->set($owner->tenant);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(ListGiftVouchers::class)
        ->assertActionDoesNotExist('create');
});

test('the buy-a-voucher page only offers gateways the tenant has fully configured and enabled', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();

    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'paypal',
        'enabled' => true,
        'credentials' => ['mode' => 'sandbox', 'client_id' => 'id-123', 'client_secret' => 'secret-123'],
    ]);
    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'hbl',
        'enabled' => true,
        'credentials' => [], // enabled but not actually configured — must not be offered
    ]);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->assertFormFieldExists('gateway', fn ($field): bool => array_keys($field->getOptions()) === ['paypal']);
});

test('buying a voucher creates a normal invoice flagged as a gift-voucher purchase, not a voucher yet', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'paypal',
        'enabled' => true,
        'credentials' => ['mode' => 'sandbox', 'client_id' => 'id-123', 'client_secret' => 'secret-123'],
    ]);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->set('data.amount', '50')
        ->set('data.gateway', 'paypal')
        ->call('buy');

    $invoice = Invoice::query()->where('customer_id', $customer->id)->where('purpose', 'gift_voucher_purchase')->firstOrFail();

    expect($invoice->total)->toBe(5000)
        ->and($invoice->booking_id)->toBeNull()
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->items()->first()->description)->toBe('Gift voucher purchase')
        ->and(GiftVoucher::query()->count())->toBe(0);
});

test('fully paying a gift-voucher-purchase invoice mints the voucher and emails the buyer, exactly once', function () {
    Notification::fake();

    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();

    $invoice = app(GiftVoucherPurchase::class)->createInvoice($customer, 5000, 'USD');

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'amount' => 5000,
        'currency' => 'USD',
        'method' => 'paypal',
        'type' => 'installment',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    expect($invoice->fresh()->status)->toBe('paid');

    $voucher = GiftVoucher::query()->where('source_invoice_id', $invoice->id)->first();

    expect($voucher)->not->toBeNull()
        ->and($voucher->value)->toBe(5000)
        ->and($voucher->currency)->toBe('USD')
        ->and($voucher->issued_to)->toBe($customer->id)
        ->and($voucher->status)->toBe('unredeemed');

    Notification::assertSentTo($customer, GiftVoucherPurchased::class);

    // Re-fulfilling the same invoice (e.g. a retried webhook) must not mint a second voucher.
    app(GiftVoucherPurchase::class)->fulfill($invoice->fresh());
    expect(GiftVoucher::query()->where('source_invoice_id', $invoice->id)->count())->toBe(1);
});
