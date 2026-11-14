Hi {{ $name }},

@if ($reminder)
{{ $cards->first()['event'] }} is tomorrow. Your ticket PDF is attached: show the QR code at the entrance.
@else
Your ticket{{ $cards->count() > 1 ? 's are' : ' is' }} ready. The PDF attached has the QR code{{ $cards->count() > 1 ? 's' : '' }}: show it at the entrance.
@endif

@foreach ($cards as $card)
- {{ $card['attendee'] }} · {{ $card['tier'] }}@if ($card['zone']) · {{ $card['zone'] }}@endif · {{ $card['event'] }} (ticket {{ $card['code'] }})
@foreach ($card['sessions'] as $session)
  {{ $session['name'] ? $session['name'].': ' : '' }}{{ $session['when'] }}
@endforeach
@endforeach

@if ($cards->contains(fn ($c) => (float) ($c['price'] ?? 0) > 0))
Need a refund? Contact {{ $cards->first()['organizer'] }}, who handles refunds for this event. With a NaSeRy account you can also ask from My Tickets. Refunds go back to the account you paid with.

@endif
My Tickets: {{ $myTickets }}
