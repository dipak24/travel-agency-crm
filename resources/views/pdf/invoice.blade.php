<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_no }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 20px; margin-bottom: 0; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; }
        .totals td { border: none; }
        .totals .label { text-align: right; font-weight: bold; }
        .header { display: flex; justify-content: space-between; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>Invoice {{ $invoice->invoice_no }}</h1>
            <p class="muted">
                Status: {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}<br>
                @if ($invoice->due_date)
                    Due: {{ $invoice->due_date->format('M j, Y') }}
                @endif
            </p>
        </div>
        <div class="muted">
            {{ $invoice->tenant?->name }}
        </div>
    </div>

    <p>
        <strong>Billed to:</strong><br>
        {{ $invoice->customer?->name }}<br>
        {{ $invoice->customer?->email }}
    </p>

    @if ($invoice->booking)
        <p><strong>Trip:</strong> {{ $invoice->booking->trip_name }}</p>
    @endif

    <table class="totals">
        <tr>
            <td></td>
            <td class="label">Amount</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->amount / 100, 2) }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Tax</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->tax / 100, 2) }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Discount</td>
            <td>-{{ $invoice->currency }} {{ number_format($invoice->discount / 100, 2) }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Total</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->total / 100, 2) }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Amount paid</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->paidAmount() / 100, 2) }}</td>
        </tr>
        <tr>
            <td></td>
            <td class="label">Balance due</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->balanceDue() / 100, 2) }}</td>
        </tr>
    </table>
</body>
</html>
