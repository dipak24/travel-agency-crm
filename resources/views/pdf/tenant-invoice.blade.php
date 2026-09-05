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
    <h1>Invoice {{ $invoice->invoice_no }}</h1>
    <p class="muted">
        Status: {{ ucfirst(str_replace('_', ' ', $invoice->status)) }}<br>
        Issued: {{ optional($invoice->issue_date)->format('M j, Y') }}
        @if ($invoice->due_date)
            &middot; Due: {{ $invoice->due_date->format('M j, Y') }}
        @endif
    </p>

    <p>
        <strong>Billed to:</strong><br>
        {{ $invoice->tenant?->name }}<br>
        {{ $invoice->tenant?->billing_email }}
    </p>

    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', $item->type)) }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->qty }}</td>
                    <td>{{ number_format($item->unit_price / 100, 2) }}</td>
                    <td>{{ number_format($item->total / 100, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td></td>
            <td class="label">Subtotal</td>
            <td>{{ $invoice->currency }} {{ number_format($invoice->subtotal / 100, 2) }}</td>
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
