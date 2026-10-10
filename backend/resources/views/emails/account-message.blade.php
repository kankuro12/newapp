<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }}</title>
    </head>
    <body style="margin:0;background:#f2f5f8;color:#182b3a;font-family:Arial,sans-serif">
        <table role="presentation" style="width:100%;padding:24px 12px">
            <tr>
                <td align="center">
                    <table role="presentation" style="width:100%;max-width:600px;background:#fff;border-radius:12px;padding:28px">
                        <tr>
                            <td>
                                <p style="color:#166b66;font-weight:bold;font-size:18px">Business Book</p>
                                <h1 style="font-size:24px;line-height:1.3">{{ $title }}</h1>
                                <p>Hello {{ $recipient_name }},</p>
                                @foreach ($lines as $line)
                                    <p style="line-height:1.6;white-space:pre-line">{{ $line }}</p>
                                @endforeach
                                @if ($action_url)
                                    <p style="margin:28px 0">
                                        <a href="{{ $action_url }}" style="display:inline-block;background:#166b66;color:white;padding:14px 20px;border-radius:6px;text-decoration:none">{{ $action_label }}</a>
                                    </p>
                                    <p style="font-size:12px;word-break:break-all">
                                        Button unavailable? Open this link:
                                        <a href="{{ $action_url }}">{{ $action_url }}</a>
                                    </p>
                                @endif
                                @if ($unsubscribe_url)
                                    <p><a href="{{ $unsubscribe_url }}">Manage promotional messages</a></p>
                                @endif
                                <hr style="border:0;border-top:1px solid #e1e7ec">
                                <p style="font-size:12px;color:#526574">
                                    Business Book account message. Never share passwords or verification codes.
                                </p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
