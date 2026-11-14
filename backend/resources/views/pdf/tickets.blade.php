<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 10pt; }
    .page { padding: 26pt 28pt; page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    .brand { font-size: 13pt; font-weight: bold; color: #285F6b; }
    .brand small { font-size: 8pt; color: #6b7280; font-weight: normal; }
    .event { font-size: 17pt; font-weight: bold; margin: 14pt 0 2pt; line-height: 1.2; }
    .muted { color: #6b7280; }
    .tier { display: inline-block; margin-top: 8pt; padding: 3pt 8pt; border-radius: 8pt; background: #e8f1f3; color: #285F6b; font-weight: bold; font-size: 9pt; }
    .qr { text-align: center; margin: 14pt 0 6pt; }
    .qr img { width: 170pt; height: 170pt; }
    .void { margin: 14pt 0; padding: 30pt 10pt; text-align: center; border: 1.5pt dashed #e11d48; color: #9f1239; font-weight: bold; }
    .name { font-size: 14pt; font-weight: bold; text-align: center; }
    .code { text-align: center; font-size: 8pt; color: #6b7280; letter-spacing: 1pt; }
    table.facts { width: 100%; margin-top: 12pt; border-collapse: collapse; }
    table.facts td { padding: 3pt 0; vertical-align: top; font-size: 9pt; }
    table.facts td.k { width: 62pt; color: #6b7280; }
    .foot { margin-top: 12pt; padding-top: 8pt; border-top: 1pt solid #e5e7eb; font-size: 7.5pt; color: #6b7280; }
</style>
</head>
<body>
@foreach ($cards as $card)
<div class="page">
    <div class="brand">@include('pdf.partials.logo', ['size' => '16pt'])NaSeRy <small>· Ticket</small></div>
    <div class="event">{{ $card['event'] }}</div>
    <div class="muted">by {{ $card['organizer'] }}</div>
    <div class="tier">{{ $card['tier'] }}@if ($card['zone']) · {{ $card['zone'] }}@endif</div>

    @if ($card['qr_svg'])
        <div class="qr"><img src="{{ $card['qr_svg'] }}" alt="QR code"></div>
    @else
        <div class="void">This ticket is no longer valid ({{ $card['status'] }}).</div>
    @endif
    <div class="name">{{ $card['attendee'] }}</div>
    <div class="code">TICKET {{ $card['code'] }}</div>

    <table class="facts">
        @foreach ($card['sessions'] as $session)
            <tr><td class="k">{{ $loop->first ? 'When' : '' }}</td><td>@if ($session['name']){{ $session['name'] }}: @endif{{ $session['when'] }}</td></tr>
        @endforeach
        @if ($card['seat'] ?? null)<tr><td class="k">Seat or table</td><td>{{ $card['seat'] }}</td></tr>@endif
        @if ($card['venue'])<tr><td class="k">Where</td><td>{{ $card['venue'] }}</td></tr>@endif
        @if ($card['benefits'])<tr><td class="k">Includes</td><td>{{ implode(' · ', $card['benefits']) }}</td></tr>@endif
        @if ((float) $card['price'] > 0)<tr><td class="k">Price</td><td>₱{{ number_format((float) $card['price'], 2) }}</td></tr>@endif
    </table>

    <div class="foot">Show this QR at the entrance; it admits one person. Keep it private: anyone with this code can use it. Lost it? Ask the organizer to issue a new one.</div>
</div>
@endforeach
</body>
</html>
