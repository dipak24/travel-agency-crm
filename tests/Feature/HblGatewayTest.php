<?php

use App\Filament\Tenant\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Tenant\Resources\InvoiceResource\RelationManagers\PaymentsRelationManager as InvoicePaymentsRelationManager;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\TenantUser;
use App\Services\PaymentGateways\Hbl\JoseCodec;
use App\Services\PaymentGateways\HblGateway;
use App\Support\TenantContext;
use Database\Seeders\HblUatPaymentGatewaySeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A128CBCHS256;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\JWELoader;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\Encryption\Serializer\JWESerializerManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\JWSLoader;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @return array{private: string, public: string}
 */
function hblGenerateRsaKeyPair(): array
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privateKey);
    $details = openssl_pkey_get_details($resource);

    return ['private' => $privateKey, 'public' => $details['key']];
}

/**
 * Sets up a full four-keypair HBL/PACO handshake: two keypairs the merchant (HblGateway) holds
 * (signing outgoing requests, decrypting incoming responses) and two a "fake PACO" holds
 * (decrypting incoming requests, signing outgoing responses) — so tests exercise the real
 * cryptography in both directions rather than mocking it away.
 *
 * @return array{tenant: Tenant, credentials: array<string, mixed>, paco: array<string, array{private: string, public: string}>}
 */
function hblSetup(Tenant $tenant, array $overrides = []): array
{
    $merchantSigning = hblGenerateRsaKeyPair();
    $merchantDecryption = hblGenerateRsaKeyPair();
    $pacoEncryption = hblGenerateRsaKeyPair();
    $pacoSigning = hblGenerateRsaKeyPair();

    $credentials = array_merge([
        'mode' => 'uat',
        'office_id' => '9104137120',
        'api_key' => 'test-api-key',
        'encryption_key_id' => 'test-kid',
        'merchant_signing_private_key' => $merchantSigning['private'],
        'merchant_decryption_private_key' => $merchantDecryption['private'],
        'paco_encryption_public_key' => $pacoEncryption['public'],
        'paco_signing_public_key' => $pacoSigning['public'],
    ], $overrides);

    TenantPaymentGateway::query()->create([
        'tenant_id' => $tenant->id,
        'gateway' => 'hbl',
        'enabled' => true,
        'credentials' => $credentials,
    ]);

    return [
        'tenant' => $tenant,
        'credentials' => $credentials,
        'paco' => [
            'encryption' => $pacoEncryption,
            'signing' => $pacoSigning,
            'merchant_signing_public' => $merchantSigning['public'],
            'merchant_decryption_public' => $merchantDecryption['public'],
        ],
    ];
}

/**
 * Decrypts+verifies a request HblGateway sent, exactly as the real PACO endpoint would — but
 * without JoseCodec's own IssuerChecker(['PacoIssuer']), since that's a check on PACO's *responses*
 * to us, not on requests we send (which carry our own api_key as `iss`, matching HBL's own demo).
 */
function hblReadMerchantRequest(string $body, array $paco, string $merchantSigningPublicKey): array
{
    $jweLoader = new JWELoader(
        new JWESerializerManager([new CompactSerializer]),
        new JWEDecrypter(new AlgorithmManager([
            new RSAOAEP,
            new A128CBCHS256,
        ])),
        null,
    );

    $recipient = null;
    $jwe = $jweLoader->loadAndDecryptWithKey($body, JWKFactory::createFromKey($paco['encryption']['private']), $recipient);

    $jwsLoader = new JWSLoader(
        new JWSSerializerManager([new Jose\Component\Signature\Serializer\CompactSerializer]),
        new JWSVerifier(new AlgorithmManager([new PS256])),
        null,
    );

    $signature = null;
    $jws = $jwsLoader->loadAndVerifyWithKey($jwe->getPayload(), JWKFactory::createFromKey($merchantSigningPublicKey), $signature);

    return json_decode($jws->getPayload(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Builds a fake PACO response token, signed and encrypted the way HblGateway expects to decode it.
 */
function hblFakePacoResponse(array $responseClaims, array $paco, string $merchantDecryptionPublicKey, string $audience): string
{
    $now = now();

    $payload = array_merge($responseClaims, [
        'iss' => 'PacoIssuer',
        'aud' => $audience,
        'iat' => $now->unix(),
        'nbf' => $now->unix(),
        'exp' => $now->copy()->addHour()->unix(),
    ]);

    return (new JoseCodec)->encode($payload, $paco['signing']['private'], $merchantDecryptionPublicKey, 'paco-kid');
}

function hblInvoiceFor(Customer $customer, array $overrides = []): Invoice
{
    $booking = Booking::query()->create(['customer_id' => $customer->id, 'trip_name' => 'Annapurna Circuit']);

    return Invoice::query()->create(array_merge([
        'booking_id' => $booking->id,
        'customer_id' => $customer->id,
        'amount' => 80000,
        'total' => 80000,
        'currency' => 'USD',
        'status' => 'issued',
    ], $overrides));
}

test('JoseCodec can sign+encrypt then decrypt+verify a payload round trip', function () {
    $merchant = hblGenerateRsaKeyPair();
    $counterparty = hblGenerateRsaKeyPair();
    $now = now();

    $codec = new JoseCodec;
    $token = $codec->encode([
        'request' => ['hello' => 'world'],
        'iss' => 'PacoIssuer',
        'aud' => 'my-audience',
        'iat' => $now->unix(),
        'nbf' => $now->unix(),
        'exp' => $now->copy()->addHour()->unix(),
    ], $merchant['private'], $counterparty['public'], 'kid-1');

    $claims = $codec->decode($token, $counterparty['private'], $merchant['public'], 'my-audience');

    expect($claims['request']['hello'])->toBe('world');
});

test('an hbl gateway with no configured credentials is not enabled', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $customer = Customer::factory()->create();
    $invoice = hblInvoiceFor($customer);

    expect(app(HblGateway::class)->isEnabledFor($invoice))->toBeFalse();
});

test('starting hbl checkout builds a correctly signed request and returns the payment page url', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $customer = Customer::factory()->create();
    $invoice = hblInvoiceFor($customer, ['total' => 50000]);

    expect(app(HblGateway::class)->isEnabledFor($invoice))->toBeTrue();

    Http::fake(function (Illuminate\Http\Client\Request $request) use ($setup, $invoice) {
        // Decrypt+verify the outgoing request the way the real PACO endpoint would.
        $claims = hblReadMerchantRequest($request->body(), $setup['paco'], $setup['paco']['merchant_signing_public']);

        expect($claims['request']['officeId'])->toBe($setup['credentials']['office_id'])
            ->and((float) $claims['request']['transactionAmount']['amount'])->toBe(500.0)
            ->and($claims['request']['transactionAmount']['amountText'])->toBe('000000050000')
            ->and($claims['request']['purchaseItems'][0]['referenceNo'])->toBe((string) $invoice->id);

        $token = hblFakePacoResponse([
            'response' => [
                'Data' => [
                    'paymentPage' => ['paymentPageURL' => 'https://fake-hbl-checkout.test/session/abc123'],
                ],
            ],
        ], $setup['paco'], $setup['paco']['merchant_decryption_public'], $setup['credentials']['api_key']);

        return Http::response($token, 200);
    });

    $url = app(HblGateway::class)->startCheckout($invoice, $invoice->balanceDue(), 'https://app.test/pay/return');

    expect($url)->toBe('https://fake-hbl-checkout.test/session/abc123');

    // Every attempt is on record from the start, so a declined or abandoned one still shows.
    expect(Payment::query()->where('invoice_id', $invoice->id)->where('method', 'hbl')->sole()->status)->toBe('pending');
});

/**
 * Makes every outgoing HBL call answer like PACO's Inquiry API does for one order.
 */
function hblFakeInquiry(array $setup, Invoice $invoice, string $orderNo, ?string $pacoStatus, string $amountText = '000000050000', ?string $description = null): void
{
    $data = $pacoStatus === null ? [] : [[
        'OrderNo' => $orderNo,
        'ProductDescription' => $description ?? "Invoice {$invoice->invoice_no}",
        'PaymentStatusInfo' => ['PaymentStatus' => $pacoStatus],
        'TransactionAmount' => ['AmountText' => $amountText, 'CurrencyCode' => 'USD', 'DecimalPlaces' => 2, 'Amount' => ((int) $amountText) / 100],
    ]];

    Http::fake(fn () => Http::response(hblFakePacoResponse(
        ['response' => ['Data' => $data]],
        $setup['paco'],
        $setup['paco']['merchant_decryption_public'],
        $setup['credentials']['api_key'],
    ), 200));
}

function hblPendingAttempt(Invoice $invoice, string $orderNo, int $amount = 50000): Payment
{
    return $invoice->payments()->create([
        'amount' => $amount, 'currency' => 'USD', 'method' => 'hbl', 'type' => 'installment',
        'status' => 'pending', 'transaction_ref' => $orderNo,
    ]);
}

test('handleReturn falls back to the browser outcome when HBL has no record of the order', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $invoice = hblInvoiceFor(Customer::factory()->create());
    hblFakeInquiry($setup, $invoice, 'UNKNOWN', null);

    $gateway = app(HblGateway::class);

    expect($gateway->handleReturn(Request::create('/return?status=success&orderNo=UNKNOWN'), $invoice)->status)->toBe('pending')
        ->and($gateway->handleReturn(Request::create('/return?status=failed&orderNo=UNKNOWN'), $invoice)->status)->toBe('failed')
        ->and($gateway->handleReturn(Request::create('/return?status=cancelled&orderNo=UNKNOWN'), $invoice)->status)->toBe('cancelled')
        ->and(Payment::query()->where('transaction_ref', 'UNKNOWN')->exists())->toBeFalse();
});

test('handleReturn reports an already confirmed payment as completed without asking HBL again', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    hblSetup($tenant);
    $invoice = hblInvoiceFor(Customer::factory()->create());
    $invoice->payments()->create([
        'amount' => 50000, 'currency' => 'USD', 'method' => 'hbl', 'type' => 'installment',
        'status' => 'completed', 'transaction_ref' => 'ORDER777', 'paid_at' => now(),
    ]);
    Http::fake();

    $result = app(HblGateway::class)->handleReturn(Request::create('/return?status=success&orderNo=ORDER777'), $invoice);

    expect($result->status)->toBe('completed')
        ->and($result->payment?->transaction_ref)->toBe('ORDER777');
    Http::assertNothingSent();
});

test('returning from HBL confirms an approved payment with HBL and records it against the invoice', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $invoice = hblInvoiceFor(Customer::factory()->create(), ['total' => 50000]);
    $attempt = hblPendingAttempt($invoice, 'ORDER100');
    hblFakeInquiry($setup, $invoice, 'ORDER100', 'A');

    $result = app(HblGateway::class)->handleReturn(Request::create('/return?status=success&orderNo=ORDER100'), $invoice);

    expect($result->status)->toBe('completed')
        ->and($attempt->refresh()->status)->toBe('completed')
        ->and($attempt->paid_at)->not->toBeNull()
        ->and($invoice->refresh()->status)->toBe('paid');
});

test('an order HBL reports as unsuccessful is recorded with its outcome and takes no money', function (string $pacoStatus, string $recorded, string $returned, string $message) {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $invoice = hblInvoiceFor(Customer::factory()->create(), ['total' => 50000]);
    $attempt = hblPendingAttempt($invoice, 'ORDER200');
    hblFakeInquiry($setup, $invoice, 'ORDER200', $pacoStatus);

    $result = app(HblGateway::class)->handleReturn(Request::create('/return?status=success&orderNo=ORDER200'), $invoice);

    expect($result->status)->toBe($returned)
        ->and($result->message)->toContain($message)
        ->and($attempt->refresh()->status)->toBe($recorded)
        ->and($invoice->refresh()->balanceDue())->toBe(50000)
        ->and($invoice->status)->toBe('issued');
})->with([
    'declined by the bank' => ['F', 'declined', 'failed', 'declined'],
    'cancelled by the customer' => ['C', 'cancelled', 'cancelled', 'cancelled'],
    'session expired' => ['E', 'expired', 'failed', 'expired'],
]);

test('a failed redirect closes an attempt HBL still shows as open, but a success redirect alone never marks it paid', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $invoice = hblInvoiceFor(Customer::factory()->create(), ['total' => 50000]);
    $failedAttempt = hblPendingAttempt($invoice, 'ORDER300');
    $openAttempt = hblPendingAttempt($invoice, 'ORDER301');
    $gateway = app(HblGateway::class);

    hblFakeInquiry($setup, $invoice, 'ORDER300', 'PCPS');
    expect($gateway->handleReturn(Request::create('/return?status=failed&orderNo=ORDER300'), $invoice)->status)->toBe('failed')
        ->and($failedAttempt->refresh()->status)->toBe('failed');

    hblFakeInquiry($setup, $invoice, 'ORDER301', 'PCPS');
    expect($gateway->handleReturn(Request::create('/return?status=success&orderNo=ORDER301'), $invoice)->status)->toBe('pending')
        ->and($openAttempt->refresh()->status)->toBe('pending')
        ->and($invoice->refresh()->balanceDue())->toBe(50000);
});

test('an HBL notification re-checks the order with HBL and records the payment only once', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $invoice = hblInvoiceFor(Customer::factory()->create(), ['total' => 50000]);
    app(TenantContext::class)->clear();
    hblFakeInquiry($setup, $invoice, 'ORDER999', 'A');

    // The notification body only names the order; its outcome comes from the Inquiry above.
    $notification = hblFakePacoResponse(
        ['data' => ['paymentResult' => ['orderNo' => 'ORDER999']]],
        $setup['paco'],
        $setup['paco']['merchant_decryption_public'],
        $setup['credentials']['api_key'],
    );

    $payment = app(HblGateway::class)->handleWebhook(Request::create('/webhooks/hbl/'.$invoice->id, 'POST', content: $notification), $invoice);
    app(HblGateway::class)->handleWebhook(Request::create('/webhooks/hbl/'.$invoice->id, 'POST', content: $notification), $invoice);

    expect($payment?->status)->toBe('completed')
        ->and($payment->amount)->toBe(50000)
        ->and(Payment::query()->withoutGlobalScopes()->where('transaction_ref', 'ORDER999')->count())->toBe(1)
        ->and($invoice->refresh()->status)->toBe('paid');
});

test('an HBL notification for an order raised for another invoice records nothing', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $invoice = hblInvoiceFor(Customer::factory()->create());
    hblFakeInquiry($setup, $invoice, 'ORDERX', 'A', description: 'Invoice SOMEONE-ELSES');

    $payment = app(HblGateway::class)->handleWebhook(Request::create('/webhooks/hbl/'.$invoice->id, 'POST', ['orderNo' => 'ORDERX']), $invoice);

    expect($payment)->toBeNull()
        ->and(Payment::query()->withoutGlobalScopes()->where('transaction_ref', 'ORDERX')->exists())->toBeFalse();
});

test('a webhook that fails to decrypt/verify is rejected', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    hblSetup($tenant);
    $customer = Customer::factory()->create();
    $invoice = hblInvoiceFor($customer);

    expect(fn () => app(HblGateway::class)->handleWebhook(
        Request::create('/webhooks/hbl/'.$invoice->id, 'POST', content: 'not-a-valid-jose-token'),
        $invoice,
    ))->toThrow(RuntimeException::class);
});

test('refunding an hbl payment calls the gateway and records a refund entry', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $customer = Customer::factory()->create();
    $invoice = hblInvoiceFor($customer, ['total' => 50000]);
    $payment = $invoice->payments()->create([
        'amount' => 50000, 'currency' => 'USD', 'method' => 'hbl', 'type' => 'installment',
        'status' => 'completed', 'transaction_ref' => 'ORDERREFUND', 'paid_at' => now(),
    ]);

    Http::fake(function () use ($setup) {
        return Http::response(hblFakePacoResponse([
            'response' => ['Data' => ['refundTransactionId' => 'REFUND-ORDERREFUND']],
        ], $setup['paco'], $setup['paco']['merchant_decryption_public'], $setup['credentials']['api_key']), 200);
    });

    $refund = app(HblGateway::class)->refund($payment);

    expect($refund->type)->toBe('refund')
        ->and($refund->amount)->toBe(50000)
        ->and($refund->method)->toBe('hbl')
        ->and($refund->transaction_ref)->toBe('REFUND-ORDERREFUND')
        ->and($invoice->refresh()->balanceDue())->toBe(50000);
});

test('JoseCodec accepts a PACO response stamped a few seconds ahead of our clock', function () {
    $merchant = hblGenerateRsaKeyPair();
    $paco = hblGenerateRsaKeyPair();
    $ahead = now()->addSeconds(5);

    $codec = new JoseCodec;
    $token = $codec->encode([
        'response' => ['ok' => true],
        'iss' => 'PacoIssuer',
        'aud' => 'my-audience',
        'iat' => $ahead->unix(),
        'nbf' => $ahead->unix(),
        'exp' => $ahead->copy()->addHour()->unix(),
    ], $paco['private'], $merchant['public'], 'kid-1');

    expect($codec->decode($token, $merchant['private'], $paco['public'], 'my-audience')['response']['ok'])->toBeTrue();
});

test('a gateway POSTing the customer back is redirected to the GET return route with its fields', function () {
    $this->post('/pay/hbl/12/return?status=failed', ['orderNo' => 'ORDER5'])
        ->assertStatus(303)
        ->assertRedirect(url('/pay/hbl/12/return').'?status=failed&orderNo=ORDER5');
});

test('staff can check a pending HBL payment with HBL from the invoice', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    $this->seed();
    app(TenantContext::class)->set($tenant);
    $owner = TenantUser::factory()->create();
    $owner->assignRole(Role::query()->where('name', 'Tenant Owner')->where('guard_name', 'tenant')->where('team_id', $tenant->id)->firstOrFail());
    $setup = hblSetup($tenant);
    $invoice = hblInvoiceFor(Customer::factory()->create(), ['total' => 50000]);
    $attempt = hblPendingAttempt($invoice, 'ORDER400');
    hblFakeInquiry($setup, $invoice, 'ORDER400', 'F');

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($owner, 'tenant')->test(InvoicePaymentsRelationManager::class, [
        'ownerRecord' => $invoice,
        'pageClass' => EditInvoice::class,
    ])
        ->callAction(TestAction::make('checkHblStatus')->table($attempt))
        ->assertNotified('HBL status: Declined');

    expect($attempt->refresh()->status)->toBe('declined');
});

test('the HBL UAT seeder puts the configured test credentials on the demo agency', function () {
    $tenant = Tenant::query()->create(['name' => 'Demo Travel Agency', 'slug' => 'demo-travel']);
    config(['services.hbl_uat' => ['office_id' => '9104137120'] + array_fill_keys(HblGateway::requiredCredentialKeys(), 'value')]);

    (new HblUatPaymentGatewaySeeder)->run();

    $gateway = TenantPaymentGateway::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('gateway', 'hbl')->firstOrFail();

    expect($gateway->enabled)->toBeTrue()
        ->and($gateway->credentials['mode'])->toBe('uat')
        ->and($gateway->credentials['office_id'])->toBe('9104137120');
});

test('the HBL UAT seeder does nothing until every credential is configured', function () {
    Tenant::query()->create(['name' => 'Demo Travel Agency', 'slug' => 'demo-travel']);
    config(['services.hbl_uat' => ['api_key' => null] + array_fill_keys(HblGateway::requiredCredentialKeys(), 'value')]);

    (new HblUatPaymentGatewaySeeder)->run();

    expect(TenantPaymentGateway::query()->withoutGlobalScopes()->where('gateway', 'hbl')->exists())->toBeFalse();
});
