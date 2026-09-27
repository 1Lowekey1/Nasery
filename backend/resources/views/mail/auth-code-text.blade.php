Hi {{ $firstName }},

@if ($invitedBy)
{{ $invitedBy }} added you as a NaSeRy administrator. Set your password to sign in:
open {{ $resetUrl }}, enter this e-mail address, and use this code: {{ $code }}
@else
Use this code to {{ $action }}: {{ $code }}
@endif

It expires in {{ $minutes }} minutes. Never share it with anyone, including NaSeRy staff.

@if ($invitedBy)
If the code has expired, request a new one on the same page. If you weren't expecting this, you can ignore this email.
@elseif ($isReset)
If you didn't ask to reset your password, you can ignore this email; your password stays the same.
@else
If you didn't create a NaSeRy account, you can ignore this email.
@endif

NaSeRy
