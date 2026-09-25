<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2430;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;">
        <tr>
            <td style="padding:0 0 12px;font-size:14px;font-weight:600;color:{{ $brandColor }};text-transform:uppercase;letter-spacing:0.04em;">
                {{ $senderName }}
            </td>
        </tr>
        <tr>
            <td style="background:#ffffff;border-radius:10px;border-top:4px solid {{ $brandColor }};padding:28px;font-size:15px;line-height:1.6;">
                {!! $html !!}
            </td>
        </tr>
        <tr>
            <td style="padding:16px 4px 0;font-size:12px;line-height:1.5;color:#6b7280;">
                Sent by {{ $senderName }}.
                @if ($unsubscribeUrl)
                    You are receiving this because you are on our mailing list.
                    <a href="{{ $unsubscribeUrl }}" style="color:#6b7280;">Unsubscribe</a>.
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
