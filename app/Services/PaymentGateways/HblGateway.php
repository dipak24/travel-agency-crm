<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentGateways\Concerns\ResolvesTenantCredentials;
use App\Services\PaymentGateways\Hbl\JoseCodec;
use App\Support\PaymentReturnResult;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * HBL's gateway is a white-labelled 2C2P PACO Core Payment API integration: every request/response
 * body is signed (JWS/PS256) then encrypted (JWE/RSA-OAEP+A128CBC-HS256) — see
 * App\Services\PaymentGateways\Hbl\JoseCodec. Adapted from HBL's own published PHP demo
 * (hbldemo/src/api/Payment.php et al).
 *
 * The exact JSON shape PACO posts to our webhook (backendURL) isn't in the material HBL supplied
 * — only the demo's outgoing request shapes are. A webhook is trusted once it decrypts and its
 * signature verifies (the same trust model as PayPal's webhook signature check), but the field
 * paths used to read orderNo/status/amount out of it are a best-effort guess pending a real UAT
 * test — see DELIVERY_ROADMAP.md Phase 10.
 */
class HblGateway implements PaymentGateway
{
    use ResolvesTenantCredentials;

    private const REQUEST_CURRENCY_DECIMAL_PLACES = 2;

    /**
     * Status values PACO/HBL is known (from the demo's own examples) or reasonably expected to use
     * to mean "payment succeeded" — kept narrow and fails closed (no Payment recorded) for anything
     * else, since guessing wrong in the permissive direction would record a payment that didn't
     * actually happen. Revisit once a real UAT webhook payload is seen.
     */
    private const SUCCESS_STATUSES = ['00', '000', 'SUCCESS', 'SUCCESSFUL', 'S', 'COMPLETED', 'A'];

    public function __construct(private readonly JoseCodec $jose = new JoseCodec) {}

    public function key(): string
    {
        return 'hbl';
    }

    public function isEnabledFor(Invoice $invoice): bool
    {
        $settings = $this->settingsFor($invoice);

        if (! $settings?->enabled) {
            return false;
        }

        foreach ($this->requiredCredentialKeys() as $field) {
            if (blank($settings->credentials[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function startCheckout(Invoice $invoice, int $amount, string $returnUrl): string
    {
        $credentials = $this->credentialsFor($invoice);
        $orderNo = $this->generateOrderNo();
        $now = Carbon::now();

        $request = [
            'apiRequest' => [
                'requestMessageID' => $this->guid(),
                'requestDateTime' => $now->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'language' => 'en-US',
            ],
            'officeId' => $credentials['office_id'],
            'orderNo' => $orderNo,
            'productDescription' => "Invoice {$invoice->invoice_no}",
            'paymentType' => 'CC',
            'paymentCategory' => 'ECOM',
            'storeCardDetails' => ['storeCardFlag' => 'N', 'storedCardUniqueID' => null],
            'installmentPaymentDetails' => ['ippFlag' => 'N', 'installmentPeriod' => 0, 'interestType' => null],
            'mcpFlag' => 'N',
            'request3dsFlag' => 'N',
            'transactionAmount' => [
                'amountText' => str_pad((string) $amount, 12, '0', STR_PAD_LEFT),
                'currencyCode' => $invoice->currency,
                'decimalPlaces' => self::REQUEST_CURRENCY_DECIMAL_PLACES,
                'amount' => round($amount / 100, self::REQUEST_CURRENCY_DECIMAL_PLACES),
            ],
            'notificationURLs' => [
                'confirmationURL' => $this->decorateReturnUrl($returnUrl, $orderNo, 'success'),
                'failedURL' => $this->decorateReturnUrl($returnUrl, $orderNo, 'failed'),
                'cancellationURL' => $this->decorateReturnUrl($returnUrl, $orderNo, 'cancelled'),
                'backendURL' => route('payments.webhook', ['gateway' => 'hbl', 'invoice' => $invoice->id]),
            ],
            'deviceDetails' => [
                'browserIp' => request()?->ip() ?? '127.0.0.1',
                'browser' => substr((string) request()?->userAgent(), 0, 255),
                'browserUserAgent' => substr((string) request()?->userAgent(), 0, 255),
                'mobileDeviceFlag' => 'N',
            ],
            'purchaseItems' => [[
                'purchaseItemType' => 'invoice',
                'referenceNo' => (string) $invoice->id,
                'purchaseItemDescription' => "Invoice {$invoice->invoice_no}",
                'purchaseItemPrice' => [
                    'amountText' => str_pad((string) $amount, 12, '0', STR_PAD_LEFT),
                    'currencyCode' => $invoice->currency,
                    'decimalPlaces' => self::REQUEST_CURRENCY_DECIMAL_PLACES,
                    'amount' => round($amount / 100, self::REQUEST_CURRENCY_DECIMAL_PLACES),
                ],
            ]],
        ];

        $decoded = $this->call($credentials, 'api/1.0/Payment/prePaymentUi', $request);

        $paymentPageUrl = $decoded['response']['Data']['paymentPage']['paymentPageURL'] ?? null;

        if (! $paymentPageUrl) {
            throw new RuntimeException('HBL did not return a payment page URL.');
        }

        return $paymentPageUrl;
    }

    public function handleReturn(Request $request, Invoice $invoice): PaymentReturnResult
    {
        $status = $request->query('status');
        $orderNo = $request->query('orderNo');

        if ($status === 'failed') {
            return new PaymentReturnResult('failed', message: 'The payment was declined.');
        }

        if ($status === 'cancelled') {
            return new PaymentReturnResult('cancelled', message: 'The payment was cancelled.');
        }

        if ($status === 'success' && $orderNo) {
            $payment = Payment::query()->withoutGlobalScopes()
                ->where('tenant_id', $invoice->tenant_id)
                ->where('transaction_ref', $orderNo)
                ->first();

            if ($payment) {
                return new PaymentReturnResult('completed', $payment);
            }
        }

        return new PaymentReturnResult(
            'pending',
            message: "We've received your payment and are confirming it now — this page will not update automatically, but you'll see it reflected on the invoice shortly.",
        );
    }

    public function handleWebhook(Request $request, Invoice $invoice): ?Payment
    {
        $credentials = $this->credentialsFor($invoice);

        try {
            $claims = $this->jose->decode(
                $request->getContent(),
                $credentials['merchant_decryption_private_key'] ?? '',
                $credentials['paco_signing_public_key'] ?? '',
                $credentials['api_key'] ?? '',
            );
        } catch (Throwable $e) {
            throw new RuntimeException('HBL webhook could not be decrypted/verified: '.$e->getMessage());
        }

        $body = $claims['request'] ?? $claims['response']['Data'] ?? $claims;

        $orderNo = $body['orderNo'] ?? null;
        $status = $body['paymentStatus'] ?? $body['status'] ?? $body['respCode'] ?? null;
        $amountData = $body['transactionAmount'] ?? $body['amount'] ?? null;

        if (! $orderNo || ! $amountData) {
            Log::warning('HBL webhook payload did not match any known shape — recording nothing.', ['invoice_id' => $invoice->id, 'claims' => $claims]);

            return null;
        }

        $isSuccessful = $status !== null && in_array((string) $status, self::SUCCESS_STATUSES, true);

        if (! $isSuccessful) {
            Log::info('HBL webhook did not indicate a successful payment — no payment recorded.', [
                'invoice_id' => $invoice->id,
                'order_no' => $orderNo,
                'status' => $status,
            ]);

            return null;
        }

        $amount = isset($amountData['amountText'])
            ? (int) ltrim((string) $amountData['amountText'], '0') ?: 0
            : (int) round(((float) ($amountData['amount'] ?? 0)) * 100);
        $currency = $amountData['currencyCode'] ?? $invoice->currency;

        return app(TenantContext::class)->wrap($invoice->tenant, fn (): Payment => Payment::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $invoice->tenant_id, 'transaction_ref' => $orderNo],
            [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'currency' => $currency,
                'method' => 'hbl',
                'type' => 'installment',
                'status' => 'completed',
                'paid_at' => now(),
            ],
        ));
    }

    public function refund(Payment $payment, ?int $amount = null): Payment
    {
        $amount ??= $payment->amount;
        $invoice = $payment->invoice()->withoutGlobalScopes()->firstOrFail();
        $credentials = $this->credentialsFor($invoice);
        $actor = auth('tenant')->user();

        $request = [
            'officeId' => $credentials['office_id'],
            'orderNo' => $payment->transaction_ref,
            'refundAmount' => [
                'AmountText' => str_pad((string) $amount, 12, '0', STR_PAD_LEFT),
                'CurrencyCode' => $payment->currency,
                'DecimalPlaces' => self::REQUEST_CURRENCY_DECIMAL_PLACES,
                'Amount' => round($amount / 100, self::REQUEST_CURRENCY_DECIMAL_PLACES),
            ],
            'refundItems' => [],
            'localMakerChecker' => [
                'maker' => [
                    'username' => $actor?->name ?? 'system',
                    'email' => $actor?->email ?? 'system@'.parse_url(config('app.url'), PHP_URL_HOST),
                ],
            ],
        ];

        $decoded = $this->call($credentials, 'api/1.0/Refund/refund', $request);
        $refundReference = $decoded['response']['Data']['refundTransactionId'] ?? $decoded['response']['Data']['orderNo'] ?? 'refund-'.$payment->transaction_ref;

        return $payment->invoice->payments()->create([
            'tenant_id' => $payment->tenant_id,
            'amount' => $amount,
            'currency' => $payment->currency,
            'method' => 'hbl',
            'type' => 'refund',
            'status' => 'completed',
            'transaction_ref' => (string) $refundReference,
            'paid_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function call(array $credentials, string $path, array $request): array
    {
        $now = Carbon::now();

        $payload = [
            'request' => $request,
            'iss' => $credentials['api_key'] ?? '',
            'aud' => 'PacoAudience',
            'CompanyApiKey' => $credentials['api_key'] ?? '',
            'iat' => $now->unix(),
            'nbf' => $now->unix(),
            'exp' => $now->copy()->addHour()->unix(),
        ];

        $body = $this->jose->encode(
            $payload,
            $credentials['merchant_signing_private_key'] ?? '',
            $credentials['paco_encryption_public_key'] ?? '',
            $credentials['encryption_key_id'] ?? '',
        );

        $token = Http::baseUrl($this->baseUrl($credentials))
            ->withHeaders([
                'Accept' => 'application/jose',
                'CompanyApiKey' => $credentials['api_key'] ?? '',
            ])
            ->withBody($body, 'application/jose; charset=utf-8')
            ->post($path)
            ->throw()
            ->body();

        return $this->jose->decode(
            $token,
            $credentials['merchant_decryption_private_key'] ?? '',
            $credentials['paco_signing_public_key'] ?? '',
            $credentials['api_key'] ?? '',
        );
    }

    private function decorateReturnUrl(string $returnUrl, string $orderNo, string $status): string
    {
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return $returnUrl.$separator.http_build_query([
            'status' => $status,
            'orderNo' => $orderNo,
        ]);
    }

    private function generateOrderNo(): string
    {
        return (string) Carbon::now()->getPreciseTimestamp(3);
    }

    private function guid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        );
    }

    /**
     * @return array<int, string>
     */
    public static function requiredCredentialKeys(): array
    {
        return [
            'office_id',
            'api_key',
            'encryption_key_id',
            'merchant_signing_private_key',
            'merchant_decryption_private_key',
            'paco_encryption_public_key',
            'paco_signing_public_key',
        ];
    }

    private function baseUrl(array $credentials): string
    {
        return ($credentials['mode'] ?? 'uat') === 'live'
            ? 'https://core.paco.2c2p.com'
            : 'https://core.demo-paco.2c2p.com';
    }
}
