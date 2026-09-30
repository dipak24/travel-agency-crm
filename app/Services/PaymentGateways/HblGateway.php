<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\PaymentGateways\Concerns\ResolvesTenantCredentials;
use App\Services\PaymentGateways\Hbl\JoseCodec;
use App\Support\PaymentReturnResult;
use App\Support\PlanFeatures;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * HBL's gateway is a white-labelled 2C2P PACO Core Payment API integration: every request/response
 * body is signed (JWS/PS256) then encrypted (JWE/RSA-OAEP+A128CBC-HS256) — see
 * App\Services\PaymentGateways\Hbl\JoseCodec. Adapted from HBL's own published PHP demo
 * (hbldemo/src/api/Payment.php et al).
 *
 * Every checkout attempt is recorded as a `pending` Payment keyed by its PACO orderNo. Its outcome
 * is never taken from the browser redirect or the webhook body — both only tell us *which* order
 * to look at. The status always comes from PACO's own Inquiry API (server-to-server, signed and
 * encrypted), so the attempt ends up completed, declined, cancelled, expired or voided exactly as
 * PACO has it. That also works where PACO can't reach our webhook at all (local development).
 */
class HblGateway implements PaymentGateway
{
    use ResolvesTenantCredentials;

    private const REQUEST_CURRENCY_DECIMAL_PLACES = 2;

    /**
     * PACO payment status codes (paymentStatusInfo.paymentStatus) → our Payment status. `R`
     * (refund) isn't mapped: a refund is recorded as its own Payment row, the original stays
     * completed.
     *
     * @see https://devzone.2c2p.com/docs/payment-status
     *
     * @var array<string, string>
     */
    private const PACO_STATUSES = [
        'PCPS' => 'pending',
        'I' => 'pending',
        'P' => 'pending',
        'A' => 'completed',
        'S' => 'completed',
        'F' => 'declined',
        'C' => 'cancelled',
        'E' => 'expired',
        'V' => 'voided',
    ];

    public function __construct(private readonly JoseCodec $jose = new JoseCodec) {}

    public function key(): string
    {
        return 'hbl';
    }

    public function label(): string
    {
        return 'HBL';
    }

    public function isEnabledFor(Invoice $invoice): bool
    {
        if (! PlanFeatures::allows(Tenant::query()->find($invoice->tenant_id), PlanFeatures::ONLINE_PAYMENTS)) {
            return false;
        }

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

        $request = [
            'apiRequest' => $this->apiRequestHeader(),
            'officeId' => $credentials['office_id'],
            'orderNo' => $orderNo,
            'productDescription' => $this->productDescription($invoice),
            'paymentType' => 'CC',
            'paymentCategory' => 'ECOM',
            'storeCardDetails' => ['storeCardFlag' => 'N', 'storedCardUniqueID' => null],
            'installmentPaymentDetails' => ['ippFlag' => 'N', 'installmentPeriod' => 0, 'interestType' => null],
            'mcpFlag' => 'N',
            'request3dsFlag' => 'N',
            'transactionAmount' => $this->amountPayload($amount, $invoice->currency),
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
                'purchaseItemDescription' => $this->productDescription($invoice),
                'purchaseItemPrice' => $this->amountPayload($amount, $invoice->currency),
            ]],
        ];

        $decoded = $this->call($credentials, 'api/1.0/Payment/prePaymentUi', $request);

        $paymentPageUrl = $decoded['response']['Data']['paymentPage']['paymentPageURL'] ?? null;

        if (! $paymentPageUrl) {
            $reason = $decoded['response']['ApiResponse']['ResponseDescription'] ?? 'no payment page URL returned';

            throw new RuntimeException("HBL could not start the payment ({$reason}).");
        }

        $this->recordAttempt($invoice, $orderNo, $amount, $invoice->currency);

        return $paymentPageUrl;
    }

    public function handleReturn(Request $request, Invoice $invoice): PaymentReturnResult
    {
        $orderNo = (string) $request->input('orderNo');
        $browserStatus = $request->input('status');
        $payment = null;

        if ($orderNo !== '') {
            try {
                $payment = $this->syncOrder($invoice, $orderNo);
            } catch (Throwable $e) {
                Log::warning('HBL payment status check failed on return.', ['invoice_id' => $invoice->id, 'order_no' => $orderNo, 'message' => $e->getMessage()]);
                $payment = $this->attemptFor($invoice, $orderNo);
            }
        }

        // PACO still has the order open (or couldn't be asked): the browser's own failed/cancelled
        // redirect is enough to close the attempt — nothing was charged. A "success" redirect is
        // never trusted on its own; only PACO's Inquiry can mark an attempt completed.
        if ($payment?->status === 'pending' && in_array($browserStatus, ['failed', 'cancelled'], true)) {
            $this->updateAttempt($invoice, $payment, $browserStatus === 'failed' ? 'failed' : 'cancelled');
        }

        return match ($payment?->status ?? $browserStatus) {
            'completed' => new PaymentReturnResult('completed', $payment),
            'declined' => new PaymentReturnResult('failed', $payment, 'Your card payment was declined by the bank. No money was taken — please try again or use another card.'),
            'failed' => new PaymentReturnResult('failed', $payment, 'The payment could not be completed. No money was taken — please try again.'),
            'expired' => new PaymentReturnResult('failed', $payment, 'The payment session expired before it was completed. Please try again.'),
            'voided' => new PaymentReturnResult('failed', $payment, 'This payment was voided. Please contact us if you were charged.'),
            'cancelled' => new PaymentReturnResult('cancelled', $payment, 'The payment was cancelled.'),
            default => new PaymentReturnResult(
                'pending',
                $payment,
                "We've received your payment and are confirming it with the bank — it will show on the invoice as soon as it is confirmed.",
            ),
        };
    }

    /**
     * PACO's backend notification. Its body is only used to find out which order changed — the
     * status itself is re-read from PACO's Inquiry API, so a forged or replayed notification can
     * at most trigger a harmless re-check.
     */
    public function handleWebhook(Request $request, Invoice $invoice): ?Payment
    {
        $orderNo = $this->orderNoFromNotification($request, $invoice);

        if ($orderNo === null) {
            throw new RuntimeException('HBL webhook did not identify an order.');
        }

        return $this->syncOrder($invoice, $orderNo);
    }

    /**
     * Bring the Payment for this PACO order in line with what PACO's Inquiry API reports. Creates
     * the record if it's missing (an attempt started before attempts were recorded), but only for
     * an order PACO confirms was raised for this very invoice.
     */
    public function syncOrder(Invoice $invoice, string $orderNo): ?Payment
    {
        $payment = $this->attemptFor($invoice, $orderNo);

        if ($payment?->status === 'completed') {
            return $payment;
        }

        $transaction = $this->inquire($invoice, $orderNo);

        if ($transaction === null) {
            return $payment;
        }

        $pacoStatus = (string) $this->field($transaction, 'PaymentStatusInfo', 'PaymentStatus');
        $status = self::PACO_STATUSES[$pacoStatus] ?? null;

        if ($status === null) {
            Log::info('HBL order has a status we do not track — left unchanged.', ['invoice_id' => $invoice->id, 'order_no' => $orderNo, 'paco_status' => $pacoStatus]);

            return $payment;
        }

        $amountText = $this->field($transaction, 'TransactionAmount', 'AmountText');
        $amount = $amountText !== null ? (int) $amountText : null;
        $currency = $this->field($transaction, 'TransactionAmount', 'CurrencyCode');

        if ($payment === null) {
            if ($this->field($transaction, 'ProductDescription') !== $this->productDescription($invoice) || $amount === null) {
                Log::warning('HBL order does not belong to this invoice — ignored.', ['invoice_id' => $invoice->id, 'order_no' => $orderNo]);

                return null;
            }

            $payment = $this->recordAttempt($invoice, $orderNo, $amount, (string) ($currency ?: $invoice->currency));
        }

        if ($payment->status !== $status) {
            $this->updateAttempt($invoice, $payment, $status, $status === 'completed' ? array_filter([
                'amount' => $amount,
                'currency' => $currency,
            ]) : []);

            Log::info('HBL payment status updated.', ['invoice_id' => $invoice->id, 'order_no' => $orderNo, 'status' => $status, 'paco_status' => $pacoStatus]);
        }

        return $payment;
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
     * The order's current record at PACO, or null when PACO doesn't know it.
     *
     * @return array<string, mixed>|null
     */
    private function inquire(Invoice $invoice, string $orderNo): ?array
    {
        $credentials = $this->credentialsFor($invoice);

        $decoded = $this->call($credentials, 'api/1.0/Inquiry/transactionList', [
            'apiRequest' => $this->apiRequestHeader(),
            'advSearchParams' => [
                'controllerInternalID' => null,
                'officeId' => [$credentials['office_id'] ?? ''],
                'orderNo' => [$orderNo],
                'invoiceNo2C2P' => null,
                'fromDate' => '0001-01-01T00:00:00',
                'toDate' => '0001-01-01T00:00:00',
                'amountFrom' => null,
                'amountTo' => null,
            ],
        ]);

        foreach ((array) $this->field($decoded, 'response', 'Data') as $transaction) {
            if (is_array($transaction) && (string) $this->field($transaction, 'OrderNo') === $orderNo) {
                return $transaction;
            }
        }

        return null;
    }

    private function attemptFor(Invoice $invoice, string $orderNo): ?Payment
    {
        return Payment::query()->withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('invoice_id', $invoice->id)
            ->where('method', 'hbl')
            ->where('transaction_ref', $orderNo)
            ->first();
    }

    private function recordAttempt(Invoice $invoice, string $orderNo, int $amount, string $currency): Payment
    {
        return app(TenantContext::class)->wrap($invoice->tenant()->firstOrFail(), fn (): Payment => Payment::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $invoice->tenant_id, 'transaction_ref' => $orderNo],
            [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'currency' => $currency,
                'method' => 'hbl',
                'type' => 'installment',
                'status' => 'pending',
            ],
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateAttempt(Invoice $invoice, Payment $payment, string $status, array $attributes = []): void
    {
        app(TenantContext::class)->wrap($invoice->tenant()->firstOrFail(), fn (): bool => $payment->update([
            ...$attributes,
            'status' => $status,
            'paid_at' => $status === 'completed' ? now() : $payment->paid_at,
        ]));
    }

    /**
     * The orderNo from a backend notification, whichever way PACO sent it: a JOSE token (verified
     * against the agency's keys), plain JSON, or form fields.
     */
    private function orderNoFromNotification(Request $request, Invoice $invoice): ?string
    {
        $credentials = $this->credentialsFor($invoice);
        $content = trim($request->getContent());
        $payloads = [$request->all()];

        if ($content !== '') {
            try {
                $payloads[] = $this->jose->decode(
                    $content,
                    $credentials['merchant_decryption_private_key'] ?? '',
                    $credentials['paco_signing_public_key'] ?? '',
                    $credentials['api_key'] ?? '',
                );
            } catch (Throwable) {
                try {
                    $payloads[] = (array) json_decode($content, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    // Neither JOSE nor JSON: only form fields remain to look at.
                }
            }
        }

        foreach ($payloads as $payload) {
            $orderNo = $this->findKey($payload, 'orderNo');

            if (filled($orderNo) && is_scalar($orderNo)) {
                return (string) $orderNo;
            }
        }

        return null;
    }

    /**
     * A field read case-insensitively along a path — PACO answers in camelCase from the Payment API
     * and PascalCase from the Inquiry API.
     *
     * @param  array<string, mixed>  $data
     */
    private function field(array $data, string ...$path): mixed
    {
        $value = $data;

        foreach ($path as $key) {
            if (! is_array($value)) {
                return null;
            }

            $match = array_values(array_filter(array_keys($value), fn ($candidate): bool => strcasecmp((string) $candidate, $key) === 0));
            $value = $match === [] ? null : $value[$match[0]];
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function findKey(array $data, string $key): mixed
    {
        foreach ($data as $candidate => $value) {
            if (is_string($candidate) && strcasecmp($candidate, $key) === 0) {
                return $value;
            }

            if (is_array($value) && ($found = $this->findKey($value, $key)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array{amountText: string, currencyCode: string, decimalPlaces: int, amount: float}
     */
    private function amountPayload(int $amount, string $currency): array
    {
        return [
            'amountText' => str_pad((string) $amount, 12, '0', STR_PAD_LEFT),
            'currencyCode' => $currency,
            'decimalPlaces' => self::REQUEST_CURRENCY_DECIMAL_PLACES,
            'amount' => round($amount / 100, self::REQUEST_CURRENCY_DECIMAL_PLACES),
        ];
    }

    private function productDescription(Invoice $invoice): string
    {
        return "Invoice {$invoice->invoice_no}";
    }

    /**
     * @return array{requestMessageID: string, requestDateTime: string, language: string}
     */
    private function apiRequestHeader(): array
    {
        return [
            'requestMessageID' => $this->guid(),
            'requestDateTime' => Carbon::now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'language' => 'en-US',
        ];
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
