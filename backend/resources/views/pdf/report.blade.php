@php
    $t = $r['totals'];
    $peso = fn ($v) => '₱'.number_format((float) $v, 2);
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%';
    $plural = fn (int $n, string $word) => $n.' '.$word.($n === 1 ? '' : 's');
    $maxBucket = max(1, (int) collect($r['timeline']['buckets'])->max(fn ($b) => $b['check_ins'] + $b['walk_ins']));
    $statusLabel = ['attended' => 'Attended', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];
    $sourceLabel = ['online' => 'Online', 'organizer' => 'Guest list', 'staff' => 'Staff', 'walk_in' => 'Door sale'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28pt 32pt 36pt; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 9pt; }
    h1 { font-size: 18pt; margin: 4pt 0 2pt; }
    h2 { font-size: 11pt; margin: 16pt 0 6pt; color: #285F6b; border-bottom: 1pt solid #e5e7eb; padding-bottom: 3pt; }
    .brand { font-size: 11pt; font-weight: bold; color: #285F6b; }
    .muted { color: #6b7280; }
    .badge { display: inline-block; padding: 2pt 6pt; border-radius: 6pt; font-size: 8pt; font-weight: bold; }
    .final { background: #e8f5ec; color: #166534; }
    .draft { background: #fef3c7; color: #92400e; }
    table.cards { width: 100%; border-collapse: separate; border-spacing: 5pt; margin: 6pt -5pt 0; }
    table.cards td { width: 25%; border: 1pt solid #e5e7eb; border-radius: 6pt; padding: 7pt 8pt; vertical-align: top; }
    .big { font-size: 16pt; font-weight: bold; }
    .small { font-size: 7.5pt; color: #6b7280; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { text-align: left; font-size: 7.5pt; text-transform: uppercase; color: #6b7280; border-bottom: 1pt solid #e5e7eb; padding: 4pt 4pt 3pt 0; }
    table.grid td { padding: 3.5pt 4pt 3.5pt 0; border-bottom: 0.5pt solid #f1f5f9; vertical-align: top; }
    .num, table.grid th.num { text-align: right; }
    .bar { height: 7pt; background: #285F6b; }
    .bar-walk { height: 7pt; background: #9fc4cc; }
    .note { margin-top: 8pt; padding: 8pt; background: #f8fafc; border-left: 2pt solid #285F6b; white-space: pre-line; }
    .foot { position: fixed; bottom: -24pt; left: 0; right: 0; font-size: 7pt; color: #9ca3af; }
</style>
</head>
<body>
<div class="foot">NaSeRy · {{ $r['event']['name'] }} · generated {{ \Carbon\CarbonImmutable::parse($r['generated_at'])->format('M j, Y g:i A') }}@if (! empty($share['version'])) · shared version {{ $share['version'] }}@endif</div>

<div class="brand">@include('pdf.partials.logo')NaSeRy <span class="muted" style="font-weight:normal;font-size:8pt">· Post-event attendance report</span></div>
<h1>{{ $r['event']['name'] }}</h1>
<div class="muted">by {{ $r['event']['organizer'] }}@if ($r['event']['venue']) · {{ $r['event']['venue'] }}@endif</div>
@foreach ($r['event']['sessions'] as $s)
    <div>{{ $s['when'] }}@if (count($r['event']['sessions']) > 1) ({{ $s['label'] }})@endif</div>
@endforeach
<div style="margin-top:4pt"><span class="badge {{ $r['final'] ? 'final' : 'draft' }}">{{ $r['final'] ? 'Final: event completed' : 'Preliminary: event '.$r['event']['status'] }}</span></div>
@if (! empty($share['note']))
    <div class="note"><strong>Note from the organizer:</strong> {{ $share['note'] }}</div>
@endif

<table class="cards"><tr>
    <td><div class="small">Attended</div><div class="big">{{ $t['attended'] }}<span class="small"> / {{ $t['expected'] }}</span></div><div class="small">{{ $pct($t['attendance_rate']) }} of ticket holders</div></td>
    <td><div class="small">No-shows</div><div class="big">{{ $t['no_shows'] }}</div><div class="small">never checked in</div></td>
    <td><div class="small">Walk-ins</div><div class="big">{{ $t['walk_ins'] }}</div><div class="small">without a ticket · {{ $plural($t['door_sales'], 'door sale') }}</div></td>
    <td><div class="small">People in total</div><div class="big">{{ $t['people'] }}</div><div class="small">{{ $t['capacity'] ? $pct($t['capacity_used']).' of '.$t['capacity'].' capacity' : 'no capacity set' }}</div></td>
</tr></table>
<div class="small" style="margin-top:4pt">{{ $plural($t['cancelled_tickets'], 'cancelled ticket') }} not counted · {{ $plural($t['manual_check_ins'], 'manual check-in') }} · {{ $plural($t['repeat_scans'], 'repeat scan') }} caught at the door</div>

<h2>Attendance by ticket tier</h2>
<table class="grid">
    <tr><th>Tier</th><th class="num">Expected</th><th class="num">Attended</th><th class="num">No-shows</th><th style="width:34%">Rate</th></tr>
    @forelse ($r['tiers'] as $tier)
        <tr>
            <td>{{ $tier['name'] }}@if ($tier['zone']) <span class="muted">· {{ $tier['zone'] }}</span>@endif</td>
            <td class="num">{{ $tier['expected'] }}</td><td class="num">{{ $tier['attended'] }}</td><td class="num">{{ $tier['no_shows'] }}</td>
            <td><table style="width:100%;border-collapse:collapse"><tr>
                <td style="padding:0;width:80%;border:0"><div style="background:#eef2f4"><div class="bar" style="width:{{ $tier['rate'] }}%"></div></div></td>
                <td style="padding:0 0 0 4pt;border:0" class="num">{{ $pct($tier['rate']) }}</td>
            </tr></table></td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No tickets were issued.</td></tr>
    @endforelse
</table>

@if (count($r['sources']) > 1)
    <h2>How guests got their tickets</h2>
    <table class="grid">
        <tr><th>Source</th><th class="num">Tickets</th><th class="num">Attended</th></tr>
        @foreach ($r['sources'] as $src)
            <tr><td>{{ $src['label'] }}</td><td class="num">{{ $src['expected'] }}</td><td class="num">{{ $src['attended'] }}</td></tr>
        @endforeach
    </table>
@endif

@if (count($r['sessions']) > 1)
    <h2>Attendance by session</h2>
    <table class="grid">
        <tr><th>Session</th><th class="num">Checked in</th><th class="num">Walk-ins</th></tr>
        @foreach ($r['sessions'] as $s)
            <tr><td>{{ $s['label'] }}</td><td class="num">{{ $s['checked_in'] }}</td><td class="num">{{ $s['walk_ins'] }}</td></tr>
        @endforeach
    </table>
@endif

<h2>Check-in timeline</h2>
@if (count($r['timeline']['buckets']))
    <div class="small" style="margin-bottom:4pt">
        Arrivals per {{ $r['timeline']['bucket_minutes'] }} minutes. Dark: ticket check-ins · light: walk-ins.
        @if ($r['timeline']['peak']) Busiest: {{ $r['timeline']['peak']['label'] }} ({{ $r['timeline']['peak']['check_ins'] + $r['timeline']['peak']['walk_ins'] }} people).@endif
    </div>
    <table class="grid">
        @foreach ($r['timeline']['buckets'] as $b)
            @php $n = $b['check_ins'] + $b['walk_ins']; @endphp
            <tr>
                <td style="width:18%;padding:1.5pt 4pt 1.5pt 0;border:0" class="small">{{ $b['label'] }}</td>
                <td style="padding:1.5pt 0;border:0">
                    @if ($n > 0)
                        <table style="border-collapse:collapse;width:{{ $n * 100 / $maxBucket }}%"><tr>
                            @if ($b['check_ins'])<td style="padding:0;border:0;width:{{ $b['check_ins'] * 100 / $n }}%"><div class="bar"></div></td>@endif
                            @if ($b['walk_ins'])<td style="padding:0;border:0"><div class="bar-walk"></div></td>@endif
                        </tr></table>
                    @endif
                </td>
                <td style="width:8%;padding:1.5pt 0;border:0" class="num small">{{ $n }}</td>
            </tr>
        @endforeach
    </table>
@else
    <div class="muted">No one has checked in yet.</div>
@endif

@isset($r['revenue'])
    <h2>Revenue</h2>
    <table class="grid" style="width:60%">
        <tr><td>Online ticket sales</td><td class="num">{{ $peso($r['revenue']['online_gross']) }}</td></tr>
        <tr><td>NaSeRy platform fee</td><td class="num">−{{ $peso($r['revenue']['platform_fee']) }}</td></tr>
        <tr><td>Refunds</td><td class="num">−{{ $peso($r['revenue']['refunds']) }}</td></tr>
        <tr><td>Online, net</td><td class="num">{{ $peso($r['revenue']['online_net']) }}</td></tr>
        <tr><td>Cash at the door</td><td class="num">{{ $peso($r['revenue']['door_cash']) }}</td></tr>
        <tr><td><strong>Total</strong></td><td class="num"><strong>{{ $peso($r['revenue']['total']) }}</strong></td></tr>
    </table>
@endisset

@isset($r['attendees'])
    <h2 style="page-break-before:always">Guest list</h2>
    <table class="grid">
        <tr><th>Name</th><th>Tier</th><th>Source</th><th>Status</th><th>First check-in</th></tr>
        @foreach ($r['attendees'] as $a)
            <tr><td>{{ $a['name'] }}</td><td>{{ $a['tier'] }}</td><td>{{ $sourceLabel[$a['source']] ?? $a['source'] }}</td>
                <td>{{ $statusLabel[$a['status']] }}</td><td>{{ $a['first_check_in'] ?? '—' }}</td></tr>
        @endforeach
        @foreach ($r['walk_in_names'] ?? [] as $w)
            <tr><td>{{ $w['name'] }}</td><td class="muted">—</td><td>Walk-in</td><td>Attended</td><td>{{ $w['at'] }}</td></tr>
        @endforeach
    </table>
@endisset
</body>
</html>
