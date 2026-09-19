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
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function giftVoucherTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug, 'currency' => 'USD']);
}

function enableGiftVoucherPaypalFor(Tenant $tenant): void
{
    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'paypal',
        'enabled' => true,
        'credentials' => ['mode' => 'sandbox', 'client_id' => 'id-123', 'client_secret' => 'secret-123'],
    ]);
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

    enableGiftVoucherPaypalFor($tenant);
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

test('the payment summary shows the default $100 total on first load, not $0.00', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->assertSeeText('USD 100.00')
        ->assertDontSeeText('USD 0.00')
        ->assertDontSeeHtml('<span class="text-lg font-bold">');
});

test('the payment summary reflects the chosen amount and the current number of recipients', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();

    Filament::setCurrentPanel('portal');

    $test = Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->set('data.amount_choice', '200')
        ->set('data.send_to_self', false)
        ->set('data.recipients', [
            'r1' => ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'alice@example.com', 'phone' => '555-0001'],
            'r2' => ['first_name' => 'Bob', 'last_name' => 'Jones', 'email' => 'bob@example.com', 'phone' => '555-0002'],
        ]);

    // $200 per voucher, 2 recipients — subtotal and total both read $400.
    $test->assertSeeText('USD 400.00');

    // Checking "send to myself" collapses back down to a single $200 voucher.
    $test->set('data.send_to_self', true);
    $test->assertSeeText('USD 200.00');
});

test('only the gateways the tenant has enabled render as pay-via cards, with their brand badge', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    enableGiftVoucherPaypalFor($tenant);
    TenantPaymentGateway::query()->create(['tenant_id' => $tenant->id, 'gateway' => 'pay_later', 'enabled' => true]);

    Filament::setCurrentPanel('portal');

    $test = Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class);

    $test->assertSeeHtml('bg-[#003087]') // PayPal badge
        ->assertSeeText('Pay Later')
        ->assertDontSeeText('HBL');
});

test('the purchase form pre-fills purchaser info from the logged-in customer', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['name' => 'Jane Buyer', 'email' => 'jane@example.com', 'phone' => '555-0100']);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->assertSet('data.purchaser_first_name', 'Jane')
        ->assertSet('data.purchaser_last_name', 'Buyer')
        ->assertSet('data.purchaser_email', 'jane@example.com')
        ->assertSet('data.purchaser_phone', '555-0100');
});

test('buying a voucher for multiple recipients creates one invoice with one line item per recipient and no vouchers yet', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create(['email' => 'jane@example.com']);
    enableGiftVoucherPaypalFor($tenant);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->set('data.amount_choice', '100')
        ->set('data.send_to_self', false)
        ->set('data.recipients', [
            'r1' => ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'alice@example.com', 'phone' => '555-0001'],
            'r2' => ['first_name' => 'Bob', 'last_name' => 'Jones', 'email' => 'bob@example.com', 'phone' => '555-0002'],
        ])
        ->set('data.gateway', 'paypal')
        ->call('buy')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->where('customer_id', $customer->id)->where('purpose', 'gift_voucher_purchase')->firstOrFail();

    expect($invoice->total)->toBe(20000)
        ->and($invoice->booking_id)->toBeNull()
        ->and($invoice->status)->toBe('issued')
        ->and($invoice->purchaser_email)->toBe('jane@example.com')
        ->and($invoice->items()->count())->toBe(2)
        ->and($invoice->items()->where('recipient_email', 'alice@example.com')->exists())->toBeTrue()
        ->and($invoice->items()->where('recipient_email', 'bob@example.com')->exists())->toBeTrue()
        ->and(GiftVoucher::query()->count())->toBe(0);
});

test('checking "send to myself" buys a single voucher addressed to the purchaser, hiding the recipients list', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    // Faker's default phone() can produce a format like "(123) 456-7890 x1234" whose letter
    // extension suffix fails the purchaser_phone field's ->tel() regex — pin a plain valid one.
    $customer = Customer::factory()->create(['name' => 'Jane Buyer', 'email' => 'jane@example.com', 'phone' => '555-0100']);
    enableGiftVoucherPaypalFor($tenant);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->set('data.amount_choice', '200')
        ->set('data.send_to_self', true)
        ->assertFormFieldIsHidden('recipients')
        ->set('data.gateway', 'paypal')
        ->call('buy')
        ->assertHasNoFormErrors();

    $invoice = Invoice::query()->where('customer_id', $customer->id)->where('purpose', 'gift_voucher_purchase')->firstOrFail();

    expect($invoice->items()->count())->toBe(1)
        ->and($invoice->items()->first()->recipient_email)->toBe('jane@example.com')
        ->and($invoice->items()->first()->recipientName())->toBe('Jane Buyer');
});

test('a custom amount outside the allowed range is rejected', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    enableGiftVoucherPaypalFor($tenant);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->set('data.amount_choice', 'custom')
        ->set('data.custom_amount', '1')
        ->set('data.send_to_self', true)
        ->set('data.gateway', 'paypal')
        ->call('buy')
        ->assertHasFormErrors(['custom_amount']);
});

test('two recipients sharing an email or phone in the same order are rejected', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    enableGiftVoucherPaypalFor($tenant);

    Filament::setCurrentPanel('portal');

    Livewire::actingAs($customer, 'customer')->test(BuyGiftVoucher::class)
        ->set('data.amount_choice', '100')
        ->set('data.send_to_self', false)
        ->set('data.recipients', [
            'r1' => ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'dup@example.com', 'phone' => '555-0001'],
            'r2' => ['first_name' => 'Bob', 'last_name' => 'Jones', 'email' => 'dup@example.com', 'phone' => '555-0002'],
        ])
        ->set('data.gateway', 'paypal')
        ->call('buy')
        ->assertHasFormErrors(['recipients.r1.email', 'recipients.r2.email']);
});

test('the service rejects duplicate recipient emails or phones as a backend safety net', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $purchaser = ['first_name' => 'Jane', 'last_name' => 'Buyer', 'email' => 'jane@example.com', 'phone' => '555-0100'];

    expect(fn () => app(GiftVoucherPurchase::class)->createInvoice($customer, $purchaser, 5000, 'USD', [
        ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'dup@example.com', 'phone' => '111'],
        ['first_name' => 'Bob', 'last_name' => 'Jones', 'email' => 'DUP@example.com', 'phone' => '222'],
    ]))->toThrow(LogicException::class, 'Each recipient must have a unique email address.');

    expect(fn () => app(GiftVoucherPurchase::class)->createInvoice($customer, $purchaser, 5000, 'USD', [
        ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'a@example.com', 'phone' => '999'],
        ['first_name' => 'Bob', 'last_name' => 'Jones', 'email' => 'b@example.com', 'phone' => '999'],
    ]))->toThrow(LogicException::class, 'Each recipient must have a unique mobile number.');
});

test('fully paying a multi-recipient gift-voucher invoice mints one voucher per recipient and emails each one, exactly once', function () {
    Notification::fake();

    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();

    $purchaser = ['first_name' => 'Jane', 'last_name' => 'Buyer', 'email' => 'jane@example.com', 'phone' => '555-0100'];
    $recipients = [
        ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'alice@example.com', 'phone' => '555-0001'],
        ['first_name' => 'Bob', 'last_name' => 'Jones', 'email' => 'bob@example.com', 'phone' => '555-0002'],
    ];

    $invoice = app(GiftVoucherPurchase::class)->createInvoice($customer, $purchaser, 5000, 'USD', $recipients);

    Payment::query()->create([
        'invoice_id' => $invoice->id,
        'amount' => $invoice->total,
        'currency' => 'USD',
        'method' => 'paypal',
        'type' => 'installment',
        'status' => 'completed',
        'paid_at' => now(),
    ]);

    expect($invoice->fresh()->status)->toBe('paid');

    $vouchers = GiftVoucher::query()->where('source_invoice_id', $invoice->id)->get();

    expect($vouchers)->toHaveCount(2)
        ->and($vouchers->pluck('code')->unique())->toHaveCount(2)
        ->and($vouchers->pluck('value')->unique()->all())->toBe([5000])
        ->and($vouchers->pluck('recipient_email')->sort()->values()->all())->toBe(['alice@example.com', 'bob@example.com']);

    Notification::assertSentOnDemand(
        GiftVoucherPurchased::class,
        fn ($notification, $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'alice@example.com'
    );
    Notification::assertSentOnDemand(
        GiftVoucherPurchased::class,
        fn ($notification, $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'bob@example.com'
    );

    // Re-fulfilling the same invoice (e.g. a retried webhook) must not mint duplicate vouchers.
    app(GiftVoucherPurchase::class)->fulfill($invoice->fresh());
    expect(GiftVoucher::query()->where('source_invoice_id', $invoice->id)->count())->toBe(2);
});

test('a voucher minted for a recipient who already has a customer account links to it, otherwise it stays unlinked', function () {
    $tenant = giftVoucherTenant('Northwind Travel', 'northwind-travel');
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $recipientAccount = Customer::factory()->create(['email' => 'alice@example.com']);

    $purchaser = ['first_name' => 'Jane', 'last_name' => 'Buyer', 'email' => 'jane@example.com', 'phone' => '555-0100'];
    $recipients = [
        ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'alice@example.com', 'phone' => '555-0001'],
        ['first_name' => 'Nobody', 'last_name' => 'Special', 'email' => 'stranger@example.com', 'phone' => '555-0002'],
    ];

    $invoice = app(GiftVoucherPurchase::class)->createInvoice($customer, $purchaser, 5000, 'USD', $recipients);

    Payment::query()->create([
        'invoice_id' => $invoice->id, 'amount' => $invoice->total, 'currency' => 'USD',
        'method' => 'paypal', 'type' => 'installment', 'status' => 'completed', 'paid_at' => now(),
    ]);

    $vouchers = GiftVoucher::query()->where('source_invoice_id', $invoice->id)->get()->keyBy('recipient_email');

    expect($vouchers['alice@example.com']->issued_to)->toBe($recipientAccount->id)
        ->and($vouchers['stranger@example.com']->issued_to)->toBeNull();
});
