<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Confirm email change — {{ $senderName }}</title>
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
        a { color: #1f2430; font-weight: 600; }
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
        .alert { padding: 12px 14px; border-radius: 8px; font-size: 14px; }
        .alert.success { background: #dcfce7; color: #15803d; }
        .alert.danger { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <div class="card">
        <div class="sender">{{ $senderName }}</div>

        @if ($status === 'confirmed')
            <h1>Email address updated</h1>
            <p class="alert success">Your travel portal account now uses {{ $newEmail }}.</p>
            <p><a href="{{ $loginUrl }}">Go to the travel portal</a></p>
        @elseif ($status === 'confirm')
            <h1>Confirm your new email address?</h1>
            <p>Your {{ $senderName }} travel portal account will use {{ $newEmail }} from now on.</p>
            <form method="POST" action="{{ $actionUrl }}">
                @csrf
                <button type="submit">Confirm new email</button>
            </form>
        @else
            <h1>This link is no longer valid</h1>
            <p class="alert danger">This email change link has already been used, was replaced by a newer request, or the address is no longer available.</p>
        @endif
    </div>
</body>
</html>
