<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Pay invoice {{ $invoice->invoice_no }}</title>
    <style>
        :root { color-scheme: light; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f4f5f7;
            color: #1f2430;
            margin: 0;
            padding: 32px 16px;
        }
        .card {
            max-width: 480px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            padding: 32px;
        }
        .tenant { color: #6b7280; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
        h1 { font-size: 22px; margin: 0 0 20px; }
        .row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #edeef1; font-size: 14px; }
        .row:last-of-type { border-bottom: none; }
        .row .label { color: #6b7280; }
        .balance { font-size: 26px; font-weight: 700; margin: 20px 0 8px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge.paid { background: #dcfce7; color: #15803d; }
        .badge.outstanding { background: #fef3c7; color: #92400e; }
        .alert { padding: 12px 14px; border-radius: 8px; font-size: 14px; margin-bottom: 20px; }
        .alert.success { background: #dcfce7; color: #15803d; }
        .alert.pending { background: #dbeafe; color: #1e40af; }
        .alert.error { background: #fee2e2; color: #b91c1c; }
        .gateways { margin-top: 24px; display: flex; flex-direction: column; gap: 10px; }
        .gateway-button {
            display: block;
            text-align: center;
            padding: 12px 16px;
            border-radius: 8px;
            background: #111827;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
        }
        .gateway-button:hover { background: #1f2937; }
        .empty { color: #6b7280; font-size: 14px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="tenant">{{ $invoice->tenant?->name }}</div>
        <h1>Invoice {{ $invoice->invoice_no }}</h1>

        @if (session('payment_success'))
            <div class="alert success">{{ session('payment_success') }}</div>
        @endif
        @if (session('payment_pending'))
            <div class="alert pending">{{ session('payment_pending') }}</div>
        @endif
        @if (session('payment_error'))
            <div class="alert error">{{ session('payment_error') }}</div>
        @endif

        <div class="row">
            <span class="label">Billed to</span>
            <span>{{ $invoice->customer?->name }}</span>
        </div>
        @if ($invoice->due_date)
            <div class="row">
                <span class="label">Due date</span>
                <span>{{ $invoice->due_date->format('M j, Y') }}</span>
            </div>
        @endif
        <div class="row">
            <span class="label">Invoice total</span>
            <span>{{ $invoice->currency }} {{ number_format($invoice->total / 100, 2) }}</span>
        </div>

        @php $balanceDue = $invoice->balanceDue(); @endphp

        <div class="balance">{{ $invoice->currency }} {{ number_format(max($balanceDue, 0) / 100, 2) }}</div>
        <span class="badge {{ $balanceDue > 0 ? 'outstanding' : 'paid' }}">
            {{ $balanceDue > 0 ? 'Balance due' : 'Fully paid' }}
        </span>

        @if ($balanceDue > 0)
            @if (count($gateways) > 0)
                <div class="gateways">
                    @foreach ($gateways as $gateway)
                        <a
                            class="gateway-button"
                            href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('public.pay.start', now()->addMinutes(30), ['gateway' => $gateway->key(), 'invoice' => $invoice->id]) }}"
                        >
                            Pay via {{ match ($gateway->key()) {
                                'paypal' => 'PayPal',
                                'hbl' => 'HBL',
                                default => ucfirst($gateway->key()),
                            } }}
                        </a>
                    @endforeach
                </div>
            @else
                <p class="empty">No online payment methods are currently available for this invoice — please contact {{ $invoice->tenant?->name }} directly to arrange payment.</p>
            @endif
        @endif
    </div>
</body>
</html>
