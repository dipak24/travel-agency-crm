<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Payments\Concerns\HandlesGatewayCheckout;
use App\Models\Invoice;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The public, no-login "pay this invoice" flow reachable via a signed link (see
 * App\Filament\Tenant\Resources\InvoiceResource's "Send payment link" action). `show`/`start` are
 * behind Laravel's signed-URL middleware — unguessable and tamper-proof without a login. `return`
 * is deliberately left unsigned: it's a redirect target the gateway itself drives, and the actual
 * money-moving step is verified server-to-server (capture/webhook), not by trusting this hit.
 */
class PublicPaymentController extends Controller
{
    use HandlesGatewayCheckout;

    public function show(int $invoice): View
    {
        $invoiceModel = $this->findInvoice($invoice);

        return app(TenantContext::class)->wrap($invoiceModel->tenant, function () use ($invoiceModel): View {
            $invoiceModel->loadMissing(['tenant', 'customer']);

            return view('payments.public-show', [
                'invoice' => $invoiceModel,
                'gateways' => app(PaymentGatewayResolver::class)->enabledFor($invoiceModel),
            ]);
        });
    }

    public function start(Request $request, string $gateway, int $invoice): RedirectResponse
    {
        $invoiceModel = $this->findInvoice($invoice);
        $returnUrl = route('public.pay.return', ['gateway' => $gateway, 'invoice' => $invoiceModel->id]);

        return app(TenantContext::class)->wrap(
            $invoiceModel->tenant,
            fn (): RedirectResponse => $this->startGatewayCheckout($invoiceModel, $gateway, $this->backTo($invoiceModel), $returnUrl),
        );
    }

    public function return(Request $request, string $gateway, int $invoice): RedirectResponse
    {
        $invoiceModel = $this->findInvoice($invoice);

        return app(TenantContext::class)->wrap(
            $invoiceModel->tenant,
            fn (): RedirectResponse => $this->handleGatewayReturn($request, $invoiceModel, $gateway, $this->backTo($invoiceModel)),
        );
    }

    private function findInvoice(int $invoiceId): Invoice
    {
        return Invoice::withoutGlobalScopes()->findOrFail($invoiceId);
    }

    /**
     * A fresh signed URL back to the public show page — never reuse the query string of the
     * request that got us here, since `show` itself requires a valid signature.
     */
    private function backTo(Invoice $invoice): string
    {
        return URL::temporarySignedRoute('public.pay.show', now()->addDays(14), ['invoice' => $invoice->id]);
    }
}
