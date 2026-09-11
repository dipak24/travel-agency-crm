<?php

namespace App\Services\PaymentGateways;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentGateways\Concerns\ResolvesTenantCredentials;
use App\Support\PaymentReturnResult;
use App\Support\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PayPalGateway implements PaymentGateway
{
    use ResolvesTenantCredentials;

    public function key(): string
    {
        return 'paypal';
    }

    public function isEnabledFor(Invoice $invoice): bool
    {
        $settings = $this->settingsFor($invoice);

        if (! $settings?->enabled) {
            return false;
        }

        foreach (self::requiredCredentialKeys() as $field) {
            if (blank($settings->credentials[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public static function requiredCredentialKeys(): array
    {
        return ['client_id', 'client_secret'];
    }

    public function startCheckout(Invoice $invoice, int $amount, string $returnUrl): string
    {
        $credentials = $this->credentialsFor($invoice);

        $order = $this->api($credentials)->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'custom_id' => (string) $invoice->id,
                'amount' => [
                    'currency_code' => $invoice->currency,
                    'value' => $this->toMajorUnits($amount),
                ],
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $returnUrl,
            ],
        ])->throw()->json();

        $approveUrl = collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        if (! $approveUrl) {
            throw new RuntimeException('PayPal did not return an approval link.');
        }

        return $approveUrl;
    }

    public function handleReturn(Request $request, Invoice $invoice): PaymentReturnResult
    {
        $orderId = $request->query('token');

        if (! $orderId) {
            return new PaymentReturnResult('failed', message: 'Missing PayPal order token on return.');
        }

        $credentials = $this->credentialsFor($invoice);

        try {
            $capture = $this->api($credentials)->post("/v2/checkout/orders/{$orderId}/capture")->throw()->json();
        } catch (RuntimeException $e) {
            return new PaymentReturnResult('failed', message: $e->getMessage());
        }

        $captureUnit = $capture['purchase_units'][0]['payments']['captures'][0] ?? null;

        if (! $captureUnit || ($capture['status'] ?? null) !== 'COMPLETED') {
            return new PaymentReturnResult('failed', message: 'PayPal payment was not completed.');
        }

        $payment = $this->recordPayment(
            $invoice,
            $captureUnit['id'],
            (int) round(((float) $captureUnit['amount']['value']) * 100),
            $captureUnit['amount']['currency_code'],
        );

        return new PaymentReturnResult('completed', $payment);
    }

    public function handleWebhook(Request $request, Invoice $invoice): ?Payment
    {
        $credentials = $this->credentialsFor($invoice);

        if (! $this->verifySignature($request, $credentials)) {
            throw new RuntimeException('PayPal webhook signature verification failed.');
        }

        $event = $request->json()->all();

        if (($event['event_type'] ?? null) !== 'PAYMENT.CAPTURE.COMPLETED') {
            return null;
        }

        $resource = $event['resource'] ?? [];

        if ((string) ($resource['custom_id'] ?? '') !== (string) $invoice->id) {
            Log::warning('PayPal webhook custom_id did not match the route invoice.', [
                'invoice_id' => $invoice->id,
                'custom_id' => $resource['custom_id'] ?? null,
            ]);

            return null;
        }

        return app(TenantContext::class)->wrap($invoice->tenant, fn (): Payment => $this->recordPayment(
            $invoice,
            $resource['id'],
            (int) round(((float) $resource['amount']['value']) * 100),
            $resource['amount']['currency_code'],
        ));
    }

    public function refund(Payment $payment, ?int $amount = null): Payment
    {
        $amount ??= $payment->amount;
        $invoice = $payment->invoice()->withoutGlobalScopes()->firstOrFail();
        $credentials = $this->credentialsFor($invoice);

        $refund = $this->api($credentials)->post("/v2/payments/captures/{$payment->transaction_ref}/refund", [
            'amount' => [
                'value' => $this->toMajorUnits($amount),
                'currency_code' => $payment->currency,
            ],
        ])->throw()->json();

        return $payment->invoice->payments()->create([
            'tenant_id' => $payment->tenant_id,
            'amount' => $amount,
            'currency' => $payment->currency,
            'method' => 'paypal',
            'type' => 'refund',
            'status' => 'completed',
            'transaction_ref' => $refund['id'] ?? ('refund-'.$payment->transaction_ref),
            'paid_at' => now(),
        ]);
    }

    private function recordPayment(Invoice $invoice, string $captureId, int $amount, string $currency): Payment
    {
        return Payment::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $invoice->tenant_id, 'transaction_ref' => $captureId],
            [
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'currency' => $currency,
                'method' => 'paypal',
                'type' => 'installment',
                'status' => 'completed',
                'paid_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function verifySignature(Request $request, array $credentials): bool
    {
        $webhookId = $credentials['webhook_id'] ?? null;

        if (! $webhookId) {
            return false;
        }

        $response = $this->api($credentials)->post('/v1/notifications/verify-webhook-signature', [
            'transmission_id' => $request->header('Paypal-Transmission-Id'),
            'transmission_time' => $request->header('Paypal-Transmission-Time'),
            'cert_url' => $request->header('Paypal-Cert-Url'),
            'auth_algo' => $request->header('Paypal-Auth-Algo'),
            'transmission_sig' => $request->header('Paypal-Transmission-Sig'),
            'webhook_id' => $webhookId,
            'webhook_event' => $request->json()->all(),
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    private function toMajorUnits(int $minorUnits): string
    {
        return number_format($minorUnits / 100, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function api(array $credentials): PendingRequest
    {
        $baseUrl = $this->baseUrl($credentials);

        return Http::baseUrl($baseUrl)
            ->withToken($this->accessToken($credentials, $baseUrl))
            ->acceptJson();
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials, string $baseUrl): string
    {
        return Http::asForm()
            ->withBasicAuth($credentials['client_id'] ?? '', $credentials['client_secret'] ?? '')
            ->baseUrl($baseUrl)
            ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->throw()
            ->json('access_token');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function baseUrl(array $credentials): string
    {
        return ($credentials['mode'] ?? 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }
}
