<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f5f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px;">
    <tr><td>
        @include('mail.partials.brand', ['color' => '#285F6b'])
        <p style="margin:20px 0 8px;">Hi {{ $name }},</p>
        @if ($reminder)
            <p style="margin:0 0 16px;"><strong>{{ $cards->first()['event'] }}</strong> is tomorrow. Here {{ $cards->count() > 1 ? 'are your tickets' : 'is your ticket' }} again: show the QR code at the entrance.</p>
        @else
            <p style="margin:0 0 16px;">{{ $cards->count() > 1 ? 'Your tickets are' : 'Your ticket is' }} ready. Show the QR code at the entrance; each code admits one person. {{ $cards->count() > 1 ? 'They are' : 'It is' }} also attached as a PDF you can print.</p>
        @endif

        @foreach ($cards as $card)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border:1px solid #e5e7eb;border-radius:10px;">
                <tr><td style="padding:16px;">
                    <p style="margin:0;font-size:12px;font-weight:bold;color:#285F6b;text-transform:uppercase;letter-spacing:1px;">{{ $card['tier'] }}@if ($card['zone']) · {{ $card['zone'] }}@endif</p>
                    <p style="margin:4px 0 2px;font-size:18px;font-weight:bold;">{{ $card['event'] }}</p>
                    <p style="margin:0 0 8px;color:#6b7280;font-size:13px;">by {{ $card['organizer'] }}</p>
                    @foreach ($card['sessions'] as $session)
                        <p style="margin:0;font-size:14px;">@if ($session['name']){{ $session['name'] }}: @endif{{ $session['when'] }}</p>
                    @endforeach
                    @if ($card['venue'])<p style="margin:2px 0 0;font-size:14px;color:#374151;">{{ $card['venue'] }}</p>@endif
                    <p style="margin:12px 0 0;font-size:15px;font-weight:bold;">{{ $card['attendee'] }}</p>
                    @if ($pngs[$card['id']])
                        <p style="margin:10px 0 0;text-align:center;"><img src="{{ $message->embedData($pngs[$card['id']], 'ticket-'.$card['code'].'.png', 'image/png') }}" width="300" alt="Ticket with QR code for {{ $card['attendee'] }}" style="display:inline-block;max-width:100%;height:auto;border:1px solid #e5e7eb;border-radius:8px;"></p>
                    @else
                        <p style="margin:10px 0 0;color:#9f1239;font-weight:bold;">This ticket is no longer valid.</p>
                    @endif
                    <p style="margin:6px 0 0;text-align:center;font-size:11px;color:#6b7280;letter-spacing:1px;">TICKET {{ $card['code'] }}</p>
                    @if ($card['benefits'])<p style="margin:8px 0 0;font-size:13px;color:#374151;">Includes: {{ implode(' · ', $card['benefits']) }}</p>@endif
                </td></tr>
            </table>
        @endforeach

        @if ($cards->contains(fn ($c) => (float) ($c['price'] ?? 0) > 0))
            <p style="margin:0 0 6px;color:#6b7280;font-size:13px;">Need a refund? Contact {{ $cards->first()['organizer'] }}, who handles refunds for this event. With a NaSeRy account you can also ask from <a href="{{ $myTickets }}" style="color:#285F6b;">My Tickets</a>. Refunds go back to the account you paid with.</p>
        @endif
        <p style="margin:0 0 6px;color:#6b7280;font-size:13px;">Keep your QR private: anyone with the code can use it. If you have a NaSeRy account, your tickets are also in <a href="{{ $myTickets }}" style="color:#285F6b;">My Tickets</a>.</p>
    </td></tr>
</table>
</body>
</html>
