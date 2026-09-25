<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Unsubscribe — {{ $senderName }}</title>
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
        .sender { color: #6b7280; font-size: 13px; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
        h1 { font-size: 22px; margin: 0 0 16px; }
        p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
        button {
            width: 100%;
            padding: 12px 16px;
            border: 0;
            border-radius: 8px;
            background: #1f2430;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }
        .alert { padding: 12px 14px; border-radius: 8px; font-size: 14px; background: #dcfce7; color: #15803d; }
    </style>
</head>
<body>
    <div class="card">
        <div class="sender">{{ $senderName }}</div>

        @if ($unsubscribed)
            <h1>You're unsubscribed</h1>
            <p class="alert">{{ $email }} will no longer receive marketing emails from {{ $senderName }}.</p>
            <p>You'll still receive emails about your own bookings and invoices.</p>
        @else
            <h1>Unsubscribe from marketing emails?</h1>
            <p>{{ $email }} will stop receiving marketing emails from {{ $senderName }}. Emails about your own bookings and invoices are not affected.</p>
            <form method="POST" action="{{ $actionUrl }}">
                <button type="submit">Unsubscribe</button>
            </form>
        @endif
    </div>
</body>
</html>
