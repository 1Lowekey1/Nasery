<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f5f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
    <tr><td>
        <p style="margin:0 0 4px;font-size:20px;font-weight:bold;">NaSeRy</p>
        <p style="margin:24px 0 20px;">Someone is registering this e-mail address for <strong>{{ $eventName }}</strong>. Use this code to confirm it:</p>
        <p style="margin:0 0 20px;font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;">{{ $code }}</p>
        <p style="margin:0 0 8px;color:#6b7280;font-size:14px;">It expires in {{ $minutes }} minutes. Never share it with anyone, including NaSeRy staff.</p>
        <p style="margin:0;color:#6b7280;font-size:14px;">If you aren't registering for this event, you can ignore this email; nothing happens without the code.</p>
    </td></tr>
</table>
</body>
</html>
