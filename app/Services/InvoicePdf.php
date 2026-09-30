<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Services\PaymentGateways\PaymentGatewayResolver;
use App\Support\Money;
use App\Support\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders every invoice PDF in the app from one branded template (pdf.invoice): the agency's
 * invoices to its customers carry the agency's own logo (or name, without one) and brand colour,
 * and the platform's invoices to agencies carry the platform's name.
 */
class InvoicePdf
{
    public const DEFAULT_COLOR = '#1f2937';

    public function forInvoice(Invoice $invoice): DomPdf
    {
        $invoice->loadMissing(['tenant', 'customer', 'booking', 'items']);
        $tenant = $invoice->tenant;
        $customer = $invoice->customer;

        $items = $invoice->items->map(fn ($item): array => $this->line($item->description, $item->qty, $item->unit_price, $item->total, (string) $invoice->currency))->all();

        if ($items === []) {
            $items[] = $this->line($invoice->booking?->trip_name ?? 'Invoice amount', 1, $invoice->amount, $invoice->amount, (string) $invoice->currency);
        }

        return $this->render([
            'issuer' => [
                'name' => $tenant?->name ?? config('app.name'),
                'logo' => $this->logoDataUri($tenant?->logo),
                'lines' => array_filter([
                    $tenant?->address,
                    implode(' ', array_filter([$tenant?->billing_email, $tenant?->phone_number ?: $tenant?->mobile_number])),
                ]),
            ],
            'brandColor' => $this->brandColor($tenant),
            'invoice' => $invoice,
            'invoiceDate' => $invoice->created_at,
            'dueDate' => $invoice->due_date,
            'billedTo' => [
                'name' => $customer?->name ?? $invoice->purchaserName() ?? '—',
                'lines' => array_filter([
                    $customer?->address,
                    $customer?->email ?? $invoice->purchaser_email,
                    $customer?->phone ?? $invoice->purchaser_phone,
                ]),
            ],
            'items' => $items,
            'subtotal' => $invoice->amount,
            'transactions' => $this->transactions($invoice->payments()->where('status', 'completed')->oldest('paid_at')->get(), (string) $invoice->currency),
        ]);
    }

    /**
     * Rendered inside the billed agency's tenant context: tenant-owned lines and payments are
     * invisible to the admin panel otherwise (it never sets one).
     */
    public function forTenantInvoice(TenantInvoice $invoice): DomPdf
    {
        $tenant = $invoice->tenant()->firstOrFail();

        return app(TenantContext::class)->wrap($tenant, fn (): DomPdf => $this->render([
            'issuer' => ['name' => config('app.name'), 'logo' => null, 'lines' => []],
            'brandColor' => self::DEFAULT_COLOR,
            'invoice' => $invoice,
            'invoiceDate' => $invoice->issue_date ?? $invoice->created_at,
            'dueDate' => $invoice->due_date,
            'billedTo' => [
                'name' => $tenant->name,
                'lines' => array_filter([$tenant->address, $tenant->billing_email]),
            ],
            'items' => $invoice->items()->get()->map(fn ($item): array => $this->line($item->description, $item->qty, $item->unit_price, $item->total, (string) $invoice->currency))->all(),
            'subtotal' => $invoice->subtotal,
            'transactions' => $this->transactions($invoice->payments()->where('status', 'completed')->oldest('paid_at')->get(), (string) $invoice->currency),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data): DomPdf
    {
        /** @var Invoice|TenantInvoice $invoice */
        $invoice = $data['invoice'];
        $currency = (string) $invoice->currency;

        return Pdf::loadView('pdf.invoice', [
            ...$data,
            'title' => ($invoice->status === 'paid' ? 'Invoice' : 'Proforma Invoice').' #'.$invoice->invoice_no,
            'tintColor' => $this->tint($data['brandColor']),
            'status' => $this->statusBadge($invoice->status),
            'subtotal' => Money::format($data['subtotal'], $currency),
            'discount' => '-'.Money::format($invoice->discount, $currency),
            'tax' => Money::format($invoice->tax, $currency),
            'total' => Money::format($invoice->total, $currency),
            'balance' => Money::format($invoice->balanceDue(), $currency),
            'generatedAt' => now(),
        ])->setPaper('a4');
    }

    /**
     * @return array{description: string, qty: int, unit_price: string, total: string}
     */
    private function line(?string $description, ?int $qty, ?int $unitPrice, ?int $total, string $currency): array
    {
        return [
            'description' => (string) $description,
            'qty' => (int) ($qty ?? 1),
            'unit_price' => Money::format($unitPrice, $currency),
            'total' => Money::format($total, $currency),
        ];
    }

    /**
     * @param  iterable<Payment|TenantPayment>  $payments
     * @return array<int, array{date: ?Carbon, gateway: string, reference: string, amount: string}>
     */
    private function transactions(iterable $payments, string $currency): array
    {
        $resolver = app(PaymentGatewayResolver::class);
        $rows = [];

        foreach ($payments as $payment) {
            $method = (string) $payment->method;

            $rows[] = [
                'date' => $payment->paid_at ?? $payment->created_at,
                'gateway' => in_array($method, $resolver->keys(), true) ? $resolver->for($method)->label() : Str::headline($method ?: 'Manual'),
                'reference' => $payment->transaction_ref ?: '—',
                'amount' => ($payment->type === 'refund' ? '-' : '').Money::format($payment->amount, $currency),
            ];
        }

        return $rows;
    }

    /**
     * @return array{label: string, color: string}
     */
    private function statusBadge(?string $status): array
    {
        return match ($status) {
            'paid' => ['label' => 'Paid', 'color' => '#16a34a'],
            'partially_paid' => ['label' => 'Partially paid', 'color' => '#d97706'],
            'overdue' => ['label' => 'Overdue', 'color' => '#dc2626'],
            'cancelled' => ['label' => 'Cancelled', 'color' => '#6b7280'],
            'draft' => ['label' => 'Draft', 'color' => '#6b7280'],
            default => ['label' => 'Unpaid', 'color' => '#dc2626'],
        };
    }

    private function brandColor(?Tenant $tenant): string
    {
        $color = (string) $tenant?->primary_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::DEFAULT_COLOR;
    }

    /**
     * A pale version of the brand colour for header and table backgrounds, so any brand colour
     * keeps dark text readable on top of it.
     */
    private function tint(string $hex): string
    {
        $channels = array_map(fn (string $pair): int => (int) round(hexdec($pair) * 0.12 + 255 * 0.88), str_split(ltrim($hex, '#'), 2));

        return vsprintf('#%02x%02x%02x', $channels);
    }

    /**
     * The logo is embedded as a data URI: dompdf never has to reach the disk or network for it.
     * Returns null (the template then shows the business name) when there is no usable file.
     */
    private function logoDataUri(?string $path): ?string
    {
        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path);

        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
    }
}
