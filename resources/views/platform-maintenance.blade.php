<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Use your agency's link — {{ config('app.name') }}</title>
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
        h1 { font-size: 22px; margin: 0 0 16px; }
        p { font-size: 15px; line-height: 1.6; margin: 0 0 16px; }
        .muted { color: #6b7280; font-size: 14px; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>We'll be right back</h1>
        <p>{{ $message }}</p>
        <p class="muted">Please try again in a little while.</p>
    </div>
</body>
</html>
