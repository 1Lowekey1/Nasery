Hi {{ $firstName }},

@if ($invitedBy)
{{ $invitedBy }} added you as a NaSeRy administrator. Choose your password to activate your account:

{{ $setupUrl }}

The link works for {{ $inviteHours }} hours.

Never share this link with anyone, including NaSeRy staff. If it has expired, ask the admin who invited you to resend it. If you weren't expecting this, you can ignore this email.
@else
Use this code to {{ $action }}: {{ $code }}

It expires in {{ $minutes }} minutes. Never share it with anyone, including NaSeRy staff.

@if ($isReset)
If you didn't ask to reset your password, you can ignore this email; your password stays the same.
@else
If you didn't create a NaSeRy account, you can ignore this email.
@endif
@endif

NaSeRy
