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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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

    public function show(Request $request, int $invoice): View
    {
        $invoiceModel = $this->findInvoice($invoice);

        return app(TenantContext::class)->wrap($invoiceModel->tenant, function () use ($request, $invoiceModel): View {
            $invoiceModel->loadMissing(['tenant', 'customer', 'items', 'booking.package', 'booking.fixedDeparture']);
            $tenant = $invoiceModel->tenant;

            return view('payments.public-show', [
                'invoice' => $invoiceModel,
                'tenant' => $tenant,
                'booking' => $invoiceModel->booking,
                'logoUrl' => filled($tenant?->logo) ? Storage::disk('public')->url($tenant->logo) : null,
                'accentColor' => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $tenant?->primary_color) ? $tenant->primary_color : '#111827',
                'payments' => $invoiceModel->payments()->where('status', 'completed')->oldest('paid_at')->get(),
                'paidAmount' => $invoiceModel->paidAmount(),
                'balanceDue' => $invoiceModel->balanceDue(),
                'linkExpiresAt' => $request->filled('expires') ? Carbon::createFromTimestamp((int) $request->query('expires'), $tenant?->timezone ?: config('app.timezone')) : null,
                'gateways' => $request->boolean('pay_later')
                    ? app(PaymentGatewayResolver::class)->enabledFor($invoiceModel)
                    : app(PaymentGatewayResolver::class)->payNowFor($invoiceModel),
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
