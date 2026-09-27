<?php

namespace App\Services\Tickets;

use App\Support\Qr\QrImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Printable tickets, one A6-ish card per page (B13, ported in spirit from
 * the old prototype's dompdf ticket). The QR is SVG: dompdf can't read PNGs
 * without GD. DejaVu Sans covers the peso sign; fonts are subset to keep
 * the file small.
 */
class TicketPdf
{
    public function __construct(private readonly QrImage $qr) {}

    /** @param  Collection<int, array<string, mixed>>  $cards  from TicketCards::load() */
    public function render(Collection $cards): string
    {
        $cards = $cards->map(fn ($c) => [...$c, 'qr_svg' => $c['token'] ? 'data:image/svg+xml;base64,'.base64_encode($this->qr->svg($c['token'])) : null]);

        return Pdf::loadView('pdf.tickets', ['cards' => $cards])
            ->setPaper([0, 0, 420, 595]) // A5 portrait, in points
            ->setOption(['isFontSubsettingEnabled' => true, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }
}
