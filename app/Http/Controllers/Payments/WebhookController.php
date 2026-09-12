<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway, int $invoice, PaymentGatewayResolver $resolver): Response
    {
        $invoiceModel = Invoice::withoutGlobalScopes()->find($invoice);

        if (! $invoiceModel) {
            return response('', 404);
        }

        try {
            app(TenantContext::class)->wrap(
                $invoiceModel->tenant,
                fn () => $resolver->for($gateway)->handleWebhook($request, $invoiceModel),
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            Log::warning('Payment webhook rejected.', ['gateway' => $gateway, 'invoice_id' => $invoice, 'message' => $e->getMessage()]);

            return response('', 400);
        }

        return response('', 200);
    }
}
