Hi {{ $firstName }},

@if ($invitedBy)
@if ($team)
{{ $invitedBy }} invited you to join {{ $team['organization'] }} on NaSeRy as {{ $team['role'] }}. Choose your password to create your team account:
@else
{{ $invitedBy }} added you as a NaSeRy administrator. Choose your password to activate your account:
@endif

{{ $setupUrl }}

The link works for {{ $inviteHours }} hours.

Never share this link with anyone, including NaSeRy staff. If it has expired, ask the admin who invited you to resend it. If you weren't expecting this, you can ignore this email.
@else
Use this code to {{ $action }}: {{ $code }}

It expires in {{ $minutes }} minutes. Never share it with anyone, including NaSeRy staff.

@if ($isReset)
If you didn't ask to reset your password, you can ignore this email; your password stays the same.
@elseif ($isConversion)
Someone signed up with this e-mail, which already has a NaSeRy account that isn't in use. The code switches that account to the new type with the new password; your photo and history stay. If this wasn't you, ignore this email and nothing changes.
@else
If you didn't create a NaSeRy account, you can ignore this email.
@endif
@endif

NaSeRy
