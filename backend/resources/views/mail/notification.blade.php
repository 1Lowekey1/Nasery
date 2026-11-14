<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f5f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
    <tr><td>
        @include('mail.partials.brand')
        <p style="margin:24px 0 8px;">Hi {{ $firstName ?: 'there' }},</p>
        <p style="margin:0 0 12px;font-size:18px;font-weight:bold;">{{ $heading }}</p>
        @if ($text)
            <p style="margin:0 0 20px;white-space:pre-line;">{{ $text }}</p>
        @endif
        @if ($link)
            <p style="margin:0 0 24px;"><a href="{{ $link }}" style="display:inline-block;background:#285F6b;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:8px;">Open in NaSeRy</a></p>
        @endif
        @if ($settings)
            <p style="margin:0;color:#6b7280;font-size:12px;">You get this because of your notification settings. <a href="{{ $settings }}" style="color:#6b7280;">Change them</a>.</p>
        @else
            <p style="margin:0;color:#6b7280;font-size:12px;">You get this because you bought tickets on NaSeRy with this e-mail address.</p>
        @endif
    </td></tr>
</table>
</body>
</html>
