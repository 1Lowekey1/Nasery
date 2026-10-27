<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f5f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
    <tr><td>
        @include('mail.partials.brand')
        <p style="margin:24px 0 8px;">Hi {{ $firstName }},</p>
        @if ($invitedBy)
            @if ($team)
                <p style="margin:0 0 20px;">{{ $invitedBy }} invited you to join <strong>{{ $team['organization'] }}</strong> on NaSeRy as <strong>{{ $team['role'] }}</strong>. Choose your password to create your team account:</p>
            @else
                <p style="margin:0 0 20px;">{{ $invitedBy }} added you as a <strong>NaSeRy administrator</strong>. Choose your password to activate your account:</p>
            @endif
            <p style="margin:0 0 24px;text-align:center;">
                <a href="{{ $setupUrl }}" style="display:inline-block;background:#285f6b;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 24px;border-radius:8px;">{{ $team ? 'Join the team' : 'Set up my admin account' }}</a>
            </p>
            <p style="margin:0 0 8px;color:#6b7280;font-size:14px;">The link works for {{ $inviteHours }} hours. If the button doesn't open, copy this address into your browser:</p>
            <p style="margin:0 0 20px;font-size:12px;word-break:break-all;"><a href="{{ $setupUrl }}" style="color:#1f6f5c;">{{ $setupUrl }}</a></p>
            <p style="margin:0;color:#6b7280;font-size:14px;">Never share this link with anyone, including NaSeRy staff. If it has expired, ask the admin who invited you to resend it. If you weren't expecting this, you can ignore this email.</p>
        @else
            <p style="margin:0 0 20px;">Use this code to {{ $action }}:</p>
            <p style="margin:0 0 20px;font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;">{{ $code }}</p>
            <p style="margin:0 0 8px;color:#6b7280;font-size:14px;">It expires in {{ $minutes }} minutes. Never share it with anyone, including NaSeRy staff.</p>
            @if ($isReset)
                <p style="margin:0;color:#6b7280;font-size:14px;">If you didn't ask to reset your password, you can ignore this email; your password stays the same.</p>
            @elseif ($isConversion)
                <p style="margin:0;color:#6b7280;font-size:14px;">Someone signed up with this e-mail, which already has a NaSeRy account that isn't in use. The code switches that account to the new type with the new password; your photo and history stay. If this wasn't you, ignore this email and nothing changes.</p>
            @else
                <p style="margin:0;color:#6b7280;font-size:14px;">If you didn't create a NaSeRy account, you can ignore this email.</p>
            @endif
        @endif
    </td></tr>
</table>
</body>
</html>
