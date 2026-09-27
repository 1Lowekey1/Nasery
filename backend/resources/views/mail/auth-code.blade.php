<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f5f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
    <tr><td>
        <p style="margin:0 0 4px;font-size:20px;font-weight:bold;">NaSeRy</p>
        <p style="margin:24px 0 8px;">Hi {{ $firstName }},</p>
        @if ($invitedBy)
            <p style="margin:0 0 12px;">{{ $invitedBy }} added you as a <strong>NaSeRy administrator</strong>. Set your password to sign in:</p>
            <p style="margin:0 0 20px;">Open <a href="{{ $resetUrl }}" style="color:#1f6f5c;">{{ $resetUrl }}</a>, enter this e-mail address, and use this code:</p>
        @else
            <p style="margin:0 0 20px;">Use this code to {{ $action }}:</p>
        @endif
        <p style="margin:0 0 20px;font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;">{{ $code }}</p>
        <p style="margin:0 0 8px;color:#6b7280;font-size:14px;">It expires in {{ $minutes }} minutes. Never share it with anyone, including NaSeRy staff.</p>
        @if ($invitedBy)
            <p style="margin:0;color:#6b7280;font-size:14px;">If the code has expired, request a new one on the same page. If you weren't expecting this, you can ignore this email.</p>
        @elseif ($isReset)
            <p style="margin:0;color:#6b7280;font-size:14px;">If you didn't ask to reset your password, you can ignore this email; your password stays the same.</p>
        @else
            <p style="margin:0;color:#6b7280;font-size:14px;">If you didn't create a NaSeRy account, you can ignore this email.</p>
        @endif
    </td></tr>
</table>
</body>
</html>
