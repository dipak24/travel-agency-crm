<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Payments\Concerns\HandlesGatewayCheckout;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    use HandlesGatewayCheckout;

    public function start(Request $request, string $gateway, int $invoice): RedirectResponse
    {
        $invoiceModel = $this->authorizedInvoice($invoice);
        $returnUrl = route('payments.return', ['gateway' => $gateway, 'invoice' => $invoiceModel->id]);

        return $this->startGatewayCheckout($invoiceModel, $gateway, $this->backTo($invoiceModel), $returnUrl);
    }

    public function return(Request $request, string $gateway, int $invoice): RedirectResponse
    {
        $invoiceModel = $this->authorizedInvoice($invoice);

        return $this->handleGatewayReturn($request, $invoiceModel, $gateway, $this->backTo($invoiceModel));
    }

    private function authorizedInvoice(int $invoiceId): Invoice
    {
        $invoice = Invoice::withoutGlobalScopes()->findOrFail($invoiceId);

        abort_unless(
            auth('customer')->check() && $invoice->customer_id === auth('customer')->id(),
            403,
        );

        return $invoice;
    }

    private function backTo(Invoice $invoice): string
    {
        return route('filament.portal.resources.invoices.view', ['record' => $invoice->id]);
    }
}
