<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Services\PaymentGateways\Hbl\JoseCodec;
use App\Services\PaymentGateways\HblGateway;
use App\Support\TenantContext;
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
use RuntimeException;

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
});

test('handleReturn reports pending for a successful browser return with no webhook yet, and failed/cancelled otherwise', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    hblSetup($tenant);
    $customer = Customer::factory()->create();
    $invoice = hblInvoiceFor($customer);

    $gateway = app(HblGateway::class);

    $pending = $gateway->handleReturn(Request::create('/return?status=success&orderNo=NOTFOUNDYET'), $invoice);
    expect($pending->status)->toBe('pending')->and($pending->payment)->toBeNull();

    $failed = $gateway->handleReturn(Request::create('/return?status=failed&orderNo=X'), $invoice);
    expect($failed->status)->toBe('failed');

    $cancelled = $gateway->handleReturn(Request::create('/return?status=cancelled&orderNo=X'), $invoice);
    expect($cancelled->status)->toBe('cancelled');
});

test('handleReturn reports completed once the webhook has already recorded the payment', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    hblSetup($tenant);
    $customer = Customer::factory()->create();
    $invoice = hblInvoiceFor($customer);
    $invoice->payments()->create([
        'amount' => 50000, 'currency' => 'USD', 'method' => 'hbl', 'type' => 'installment',
        'status' => 'completed', 'transaction_ref' => 'ORDER777', 'paid_at' => now(),
    ]);

    $result = app(HblGateway::class)->handleReturn(Request::create('/return?status=success&orderNo=ORDER777'), $invoice);

    expect($result->status)->toBe('completed')
        ->and($result->payment?->transaction_ref)->toBe('ORDER777');
});

test('a webhook that decrypts and verifies with a recognised success status records a completed payment', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $customer = Customer::factory()->create();
    $invoice = hblInvoiceFor($customer, ['total' => 50000]);
    app(TenantContext::class)->clear();

    $token = hblFakePacoResponse([
        'response' => [
            'Data' => [
                'orderNo' => 'ORDER999',
                'paymentStatus' => 'SUCCESS',
                'transactionAmount' => ['amountText' => '000000050000', 'currencyCode' => 'USD', 'amount' => 500],
            ],
        ],
    ], $setup['paco'], $setup['paco']['merchant_decryption_public'], $setup['credentials']['api_key']);

    $request = Request::create('/webhooks/hbl/'.$invoice->id, 'POST', content: $token);

    $payment = app(HblGateway::class)->handleWebhook($request, $invoice);

    expect($payment)->not->toBeNull()
        ->and($payment->amount)->toBe(50000)
        ->and($payment->method)->toBe('hbl')
        ->and($payment->status)->toBe('completed')
        ->and($invoice->refresh()->status)->toBe('paid');

    // A retried webhook with the same orderNo must not double-credit the invoice.
    $again = app(HblGateway::class)->handleWebhook(Request::create('/webhooks/hbl/'.$invoice->id, 'POST', content: $token), $invoice);
    expect(Payment::query()->withoutGlobalScopes()->where('transaction_ref', 'ORDER999')->count())->toBe(1);
});

test('a webhook with an unrecognised status records nothing', function () {
    $tenant = Tenant::query()->create(['name' => 'Northwind Travel', 'slug' => 'northwind-travel']);
    app(TenantContext::class)->set($tenant);
    $setup = hblSetup($tenant);
    $customer = Customer::factory()->create();
    $invoice = hblInvoiceFor($customer);
    app(TenantContext::class)->clear();

    $token = hblFakePacoResponse([
        'response' => [
            'Data' => [
                'orderNo' => 'ORDERFAIL',
                'paymentStatus' => 'DECLINED',
                'transactionAmount' => ['amountText' => '000000050000', 'currencyCode' => 'USD', 'amount' => 500],
            ],
        ],
    ], $setup['paco'], $setup['paco']['merchant_decryption_public'], $setup['credentials']['api_key']);

    $payment = app(HblGateway::class)->handleWebhook(Request::create('/webhooks/hbl/'.$invoice->id, 'POST', content: $token), $invoice);

    expect($payment)->toBeNull()
        ->and(Payment::query()->withoutGlobalScopes()->where('transaction_ref', 'ORDERFAIL')->exists())->toBeFalse();
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
