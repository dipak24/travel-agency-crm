<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_no }}</title>
    <style>
        @page { margin: 0; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111827; margin: 0; padding: 48px 56px 40px; }
        table { width: 100%; border-collapse: collapse; }
        .ribbon { position: absolute; top: 52px; right: -62px; width: 260px; padding: 6px 0; background: {{ $status['color'] }}; color: #ffffff; text-align: center; font-size: 12px; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; transform: rotate(45deg); }
        .header td { vertical-align: top; }
        .logo img { max-height: 56px; max-width: 220px; }
        .brand-name { font-size: 20px; font-weight: bold; color: {{ $brandColor }}; }
        .issuer { text-align: right; padding-right: 70px; line-height: 1.7; }
        .issuer strong { font-size: 12px; }
        .summary { margin-top: 28px; background: {{ $tintColor }}; padding: 20px 28px; }
        .summary h1 { margin: 0 0 10px; font-size: 22px; }
        .summary p { margin: 3px 0; }
        .billed-to { margin-top: 28px; line-height: 1.7; }
        .billed-to h3 { margin: 0 0 6px; font-size: 12px; }
        .grid { margin-top: 24px; }
        .grid th, .grid td { border: 1px solid #b8bec8; padding: 10px 14px; }
        .grid th { background: {{ $tintColor }}; font-weight: bold; }
        .grid .num { text-align: right; }
        .grid .center { text-align: center; }
        .grid .label { text-align: right; font-weight: bold; background: #f3f4f6; }
        .grid .sum { text-align: right; background: #f3f4f6; }
        .grid .strong td { background: {{ $tintColor }}; font-weight: bold; }
        h2 { margin: 30px 0 0; font-size: 15px; }
        .footer { margin-top: 36px; text-align: center; color: #6b7280; font-size: 10px; }
    </style>
</head>
<body>
    <div class="ribbon">{{ $status['label'] }}</div>

    <table class="header">
        <tr>
            <td class="logo">
                @if ($issuer['logo'])
                    <img src="{{ $issuer['logo'] }}" alt="{{ $issuer['name'] }}">
                @else
                    <div class="brand-name">{{ $issuer['name'] }}</div>
                @endif
            </td>
            <td class="issuer">
                <strong>{{ $issuer['name'] }}</strong><br>
                @foreach ($issuer['lines'] as $line)
                    {{ $line }}<br>
                @endforeach
            </td>
        </tr>
    </table>

    <div class="summary">
        <h1>{{ $title }}</h1>
        <p><strong>Invoice Date:</strong> {{ $invoiceDate?->format('l, F jS, Y') ?? '—' }}</p>
        @if ($dueDate)
            <p><strong>Due Date:</strong> {{ $dueDate->format('l, F jS, Y') }}</p>
        @endif
    </div>

    <div class="billed-to">
        <h3>Invoiced To</h3>
        <strong>{{ $billedTo['name'] }}</strong><br>
        @foreach ($billedTo['lines'] as $line)
            {{ $line }}<br>
        @endforeach
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th>Description</th>
                <th class="center" style="width: 60px;">Qty</th>
                <th class="num" style="width: 120px;">Unit Price</th>
                <th class="num" style="width: 130px;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="center">{{ $item['qty'] }}</td>
                    <td class="num">{{ $item['unit_price'] }}</td>
                    <td class="num">{{ $item['total'] }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" class="label">Sub Total</td>
                <td class="sum">{{ $subtotal }}</td>
            </tr>
            <tr>
                <td colspan="3" class="label">Credit/Discount</td>
                <td class="sum">{{ $discount }}</td>
            </tr>
            <tr>
                <td colspan="3" class="label">Tax</td>
                <td class="sum">{{ $tax }}</td>
            </tr>
            <tr class="strong">
                <td colspan="3" class="num">Total</td>
                <td class="num">{{ $total }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Transactions</h2>
    <table class="grid" style="margin-top: 12px;">
        <thead>
            <tr>
                <th class="center">Transaction Date</th>
                <th class="center">Gateway</th>
                <th class="center">Transaction ID</th>
                <th class="center">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transactions as $transaction)
                <tr>
                    <td class="center">{{ $transaction['date']?->format('M j, Y') ?? '—' }}</td>
                    <td class="center">{{ $transaction['gateway'] }}</td>
                    <td class="center">{{ $transaction['reference'] }}</td>
                    <td class="center">{{ $transaction['amount'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="center">No related transactions found</td>
                </tr>
            @endforelse
            <tr class="strong">
                <td colspan="3" class="num">Balance</td>
                <td class="num">{{ $balance }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">PDF Generated on {{ $generatedAt->format('l, F jS, Y') }}</div>
</body>
</html>
