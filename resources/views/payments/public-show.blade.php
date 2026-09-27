@php
    use App\Support\Money;
    use Illuminate\Support\Facades\URL;
    use Illuminate\Support\Str;

    $currency = $invoice->currency;
    $money = fn (int $cents): string => Money::format($cents, $currency);
    $days = $booking?->duration_days
        ?: ($booking?->start_date && $booking?->end_date ? $booking->start_date->diffInDays($booking->end_date) + 1 : null);
    $startDate = $booking?->start_date ?? $booking?->fixedDeparture?->start_date;
    $endDate = $booking?->end_date ?? $booking?->fixedDeparture?->end_date;
    $subtotal = $invoice->amount ?: $invoice->items->sum('total');
    $contact = collect([$tenant?->phone_number, $tenant?->mobile_number])->filter()->unique()->implode(' · ');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Pay invoice {{ $invoice->invoice_no }} — {{ $tenant?->name }}</title>
    <style>
        :root { color-scheme: light; --accent: {{ $accentColor }}; }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f4f5f7;
            color: #1f2430;
            margin: 0;
            padding: 32px 16px;
            line-height: 1.45;
        }
        .card {
            max-width: 640px;
            margin: 0 auto;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .header { padding: 24px 28px; border-bottom: 4px solid var(--accent); display: flex; align-items: center; gap: 16px; }
        .header img { max-height: 56px; max-width: 180px; }
        .header .agency { font-weight: 700; font-size: 18px; }
        .header .sub { color: #6b7280; font-size: 13px; }
        .section { padding: 22px 28px; border-bottom: 1px solid #edeef1; }
        .section:last-child { border-bottom: none; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; margin: 0 0 12px; }
        .hero { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
        .hero .label { color: #6b7280; font-size: 13px; }
        .balance { font-size: 30px; font-weight: 700; margin: 2px 0 6px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge.paid { background: #dcfce7; color: #15803d; }
        .badge.outstanding { background: #fef3c7; color: #92400e; }
        .meta { text-align: right; font-size: 13px; color: #4b5563; }
        .meta strong { color: #1f2430; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 20px; }
        .grid .label { color: #6b7280; font-size: 12px; }
        .grid .value { font-size: 15px; font-weight: 600; }
        .grid .wide { grid-column: 1 / -1; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; color: #6b7280; font-weight: 500; font-size: 12px; padding: 6px 0; border-bottom: 1px solid #edeef1; }
        td { padding: 8px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; padding-left: 12px; }
        .totals td { border-bottom: none; padding: 4px 0; }
        .totals .grand td { font-weight: 700; font-size: 16px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
        .totals .due td { font-weight: 700; color: #92400e; }
        .muted { color: #6b7280; font-size: 13px; }
        .alert { padding: 12px 14px; border-radius: 8px; font-size: 14px; margin: 16px 28px 0; }
        .alert.success { background: #dcfce7; color: #15803d; }
        .alert.pending { background: #dbeafe; color: #1e40af; }
        .alert.error { background: #fee2e2; color: #b91c1c; }
        .gateways { margin-top: 18px; display: flex; flex-direction: column; gap: 10px; }
        .gateway-button {
            display: block;
            text-align: center;
            padding: 13px 16px;
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
        }
        .gateway-button:hover { filter: brightness(0.92); }
        .empty { color: #6b7280; font-size: 14px; margin-top: 16px; }
        .footer { text-align: center; color: #9ca3af; font-size: 12px; max-width: 640px; margin: 16px auto 0; }
        @media (max-width: 520px) {
            .grid { grid-template-columns: 1fr; }
            .meta { text-align: left; }
            .section, .header { padding-left: 18px; padding-right: 18px; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $tenant?->name }}">
            @endif
            <div>
                <div class="agency">{{ $tenant?->name }}</div>
                <div class="sub">Payment request · Invoice {{ $invoice->invoice_no }}</div>
            </div>
        </div>

        @if (session('payment_success'))
            <div class="alert success">{{ session('payment_success') }}</div>
        @endif
        @if (session('payment_pending'))
            <div class="alert pending">{{ session('payment_pending') }}</div>
        @endif
        @if (session('payment_error'))
            <div class="alert error">{{ session('payment_error') }}</div>
        @endif

        {{-- Amount due and how to pay, first: it's why the customer is here. --}}
        <div class="section">
            <div class="hero">
                <div>
                    <div class="label">{{ $balanceDue > 0 ? 'Amount due' : 'Balance' }}</div>
                    <div class="balance">{{ $money(max($balanceDue, 0)) }}</div>
                    <span class="badge {{ $balanceDue > 0 ? 'outstanding' : 'paid' }}">
                        {{ $balanceDue > 0 ? ($paidAmount > 0 ? 'Partially paid — balance due' : 'Balance due') : 'Fully paid' }}
                    </span>
                </div>
                <div class="meta">
                    <div>Invoice <strong>{{ $invoice->invoice_no }}</strong></div>
                    <div>Issued {{ $invoice->created_at?->format('j M Y') }}</div>
                    @if ($invoice->due_date)
                        <div>Due <strong>{{ $invoice->due_date->format('j M Y') }}</strong></div>
                    @endif
                </div>
            </div>

            @if ($balanceDue > 0)
                @if (count($gateways) > 0)
                    <div class="gateways">
                        @foreach ($gateways as $gateway)
                            <a
                                class="gateway-button"
                                href="{{ URL::temporarySignedRoute('public.pay.start', now()->addMinutes(30), ['gateway' => $gateway->key(), 'invoice' => $invoice->id]) }}"
                            >
                                Pay {{ $money($balanceDue) }} via {{ $gateway->label() }}
                            </a>
                        @endforeach
                    </div>
                    <p class="muted" style="margin: 10px 0 0;">Paid to <strong>{{ $tenant?->name }}</strong>. You'll be taken to a secure payment page — no account or login needed.</p>
                @else
                    <p class="empty">No online payment methods are currently available for this invoice — please contact {{ $tenant?->name }} directly to arrange payment.</p>
                @endif
            @endif
        </div>

        @if ($booking)
            <div class="section">
                <h2>Trip details</h2>
                <div class="grid">
                    <div class="wide">
                        <div class="label">Tour</div>
                        <div class="value">{{ $booking->trip_name }}</div>
                    </div>
                    @if ($startDate)
                        <div>
                            <div class="label">Arrival / start date</div>
                            <div class="value">{{ $startDate->format('D, j M Y') }}</div>
                        </div>
                    @endif
                    @if ($endDate)
                        <div>
                            <div class="label">End date</div>
                            <div class="value">{{ $endDate->format('D, j M Y') }}</div>
                        </div>
                    @endif
                    @if ($days)
                        <div>
                            <div class="label">Duration</div>
                            <div class="value">{{ $days }} {{ Str::plural('day', $days) }}@if ($days > 1) / {{ $days - 1 }} {{ Str::plural('night', $days - 1) }}@endif</div>
                        </div>
                    @endif
                    <div>
                        <div class="label">Travellers</div>
                        <div class="value">{{ $booking->pax_count }} {{ Str::plural('person', $booking->pax_count) }}</div>
                    </div>
                    @if ($booking->booking_type)
                        <div>
                            <div class="label">Booking type</div>
                            <div class="value">{{ $booking->booking_type->getLabel() }}</div>
                        </div>
                    @endif
                    <div>
                        <div class="label">Booking reference</div>
                        <div class="value">#{{ $booking->id }}</div>
                    </div>
                </div>
            </div>
        @endif

        <div class="section">
            <h2>Invoice details</h2>
            <div class="grid" style="margin-bottom: 14px;">
                <div>
                    <div class="label">Billed to</div>
                    <div class="value">{{ $invoice->customer?->name }}</div>
                    @if ($invoice->customer?->email)
                        <div class="muted">{{ $invoice->customer->email }}</div>
                    @endif
                </div>
                <div>
                    <div class="label">Pay to</div>
                    <div class="value">{{ $tenant?->name }}</div>
                    @if ($tenant?->address)
                        <div class="muted">{{ $tenant->address }}</div>
                    @endif
                </div>
            </div>

            @if ($invoice->items->isNotEmpty())
                <table>
                    <thead>
                        <tr><th>Description</th><th class="num">Qty</th><th class="num">Amount</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td>{{ $item->description }}</td>
                                <td class="num">{{ $item->qty }}</td>
                                <td class="num">{{ $money($item->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <table class="totals" style="margin-top: 8px;">
                <tr><td>Subtotal</td><td class="num">{{ $money($subtotal) }}</td></tr>
                @if ($invoice->discount > 0)
                    <tr><td>Discount</td><td class="num">− {{ $money($invoice->discount) }}</td></tr>
                @endif
                @if ($invoice->tax > 0)
                    <tr><td>Tax</td><td class="num">{{ $money($invoice->tax) }}</td></tr>
                @endif
                <tr class="grand"><td>Invoice total</td><td class="num">{{ $money($invoice->total) }}</td></tr>
                @foreach ($payments as $payment)
                    <tr>
                        <td class="muted">
                            {{ $payment->type === 'refund' ? 'Refund' : 'Paid' }}
                            {{ $payment->paid_at?->format('j M Y') }}
                            · {{ Str::headline((string) $payment->method) }}
                        </td>
                        <td class="num muted">{{ $payment->type === 'refund' ? '+' : '−' }} {{ $money($payment->amount) }}</td>
                    </tr>
                @endforeach
                <tr class="{{ $balanceDue > 0 ? 'due' : '' }}"><td>Balance due</td><td class="num">{{ $money(max($balanceDue, 0)) }}</td></tr>
            </table>
        </div>

        <div class="section">
            <h2>Questions?</h2>
            <p class="muted" style="margin: 0;">
                Contact <strong>{{ $tenant?->name }}</strong>
                @if ($contact) · {{ $contact }} @endif
                @if ($tenant?->billing_email) · <a href="mailto:{{ $tenant->billing_email }}">{{ $tenant->billing_email }}</a> @endif
                and quote invoice {{ $invoice->invoice_no }}.
            </p>
        </div>
    </div>

    <p class="footer">
        Secure payment link for invoice {{ $invoice->invoice_no }}@if ($linkExpiresAt) · valid until {{ $linkExpiresAt->format('j M Y') }}@endif.
        Please don't share it.
    </p>
</body>
</html>
