<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\TenantInvoice;
use App\Services\InvoicePdf;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoice PDFs shown inline, so "Download PDF" opens the invoice in a new browser tab to view,
 * print or save — rather than forcing a file download. Registered as authenticated routes on
 * each panel (staff, customer portal, super admin), so each is behind that panel's own login.
 */
class InvoicePdfController extends Controller
{
    public function __construct(private InvoicePdf $invoicePdf) {}

    public function staff(int $invoice): Response
    {
        $record = Invoice::query()->findOrFail($invoice);
        Gate::forUser(auth('tenant')->user())->authorize('view', $record);

        return $this->invoicePdf->forInvoice($record)->stream("{$record->invoice_no}.pdf");
    }

    public function portal(int $invoice): Response
    {
        $record = Invoice::query()->where('status', '!=', 'draft')->findOrFail($invoice);
        Gate::forUser(auth('customer')->user())->authorize('view', $record);

        return $this->invoicePdf->forInvoice($record)->stream("{$record->invoice_no}.pdf");
    }

    public function admin(int $invoice): Response
    {
        $record = TenantInvoice::query()->withoutGlobalScopes()->findOrFail($invoice);
        Gate::forUser(auth('super_admin')->user())->authorize('view', $record);

        return $this->invoicePdf->forTenantInvoice($record)->stream("{$record->invoice_no}.pdf");
    }
}
