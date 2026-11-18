<?php

namespace App\Support\Qr;

/**
 * One ticket as a PNG: the QR with the event, ticket type, guest's name and
 * their table or seat printed around it (organizer field test, Nov 2026:
 * guests screenshot or forward the QR alone, so the details must be on it).
 * Used inline in the ticket e-mail and for "Save image". Needs GD with
 * FreeType; the fonts are dompdf's DejaVu (already installed with dompdf).
 */
class TicketImage
{
    private const WIDTH = 720;

    private const PAD = 48;

    public function __construct(private readonly QrImage $qr) {}

    /** @param  array<string, mixed>  $card  one entry of TicketCards::load() */
    public function png(array $card): string
    {
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $inner = self::WIDTH - 2 * self::PAD;

        // Lines below the QR, measured first so the image is exactly as tall as it needs.
        $tier = trim($card['tier'].(! empty($card['zone']) ? ' · '.$card['zone'] : ''));
        $session = $card['sessions'][0] ?? null;
        $below = array_values(array_filter([
            ['text' => (string) $card['attendee'], 'font' => $bold, 'size' => 24, 'color' => 'ink'],
            ! empty($card['seat']) ? ['text' => 'Table / seat: '.$card['seat'], 'font' => $bold, 'size' => 18, 'color' => 'brand'] : null,
            $session ? ['text' => $session['when'], 'font' => $font, 'size' => 15, 'color' => 'muted'] : null,
            ! empty($card['venue']) ? ['text' => (string) $card['venue'], 'font' => $font, 'size' => 15, 'color' => 'muted'] : null,
        ]));

        $titleLines = $this->wrap((string) $card['event'], $bold, 26, $inner, 2);
        $headerHeight = 40 + 30 + count($titleLines) * 38 + 24;
        $qrSide = 440;
        $belowLines = [];
        foreach ($below as $line) {
            foreach ($this->wrap($line['text'], $line['font'], $line['size'], $inner, 2) as $text) {
                $belowLines[] = [...$line, 'text' => $text];
            }
        }
        $height = $headerHeight + 28 + 40 + 16 + $qrSide + 20 + 24 + 22
            + array_sum(array_map(fn ($l) => (int) round($l['size'] * 1.7), $belowLines)) + 30 + 40;

        $img = imagecreatetruecolor(self::WIDTH, $height);
        $c = [
            'white' => imagecolorallocate($img, 255, 255, 255),
            'brand' => imagecolorallocate($img, 0x28, 0x5F, 0x6B),
            'tint' => imagecolorallocate($img, 0xE8, 0xF1, 0xF3),
            'ink' => imagecolorallocate($img, 0x11, 0x18, 0x27),
            'muted' => imagecolorallocate($img, 0x4B, 0x55, 0x63),
            'faint' => imagecolorallocate($img, 0x9C, 0xA3, 0xAF),
            'line' => imagecolorallocate($img, 0xE5, 0xE7, 0xEB),
            'red' => imagecolorallocate($img, 0x9F, 0x12, 0x39),
            'black' => imagecolorallocate($img, 0, 0, 0),
        ];
        imagefill($img, 0, 0, $c['white']);

        // Header: brand band with the event name.
        imagefilledrectangle($img, 0, 0, self::WIDTH, $headerHeight, $c['brand']);
        imagettftext($img, 13, 0, self::PAD, 52, $c['tint'], $bold, 'NASERY TICKET');
        $y = 52 + 20;
        foreach ($titleLines as $text) {
            $y += 38;
            imagettftext($img, 26, 0, self::PAD, $y, $c['white'], $bold, $text);
        }

        // Ticket type pill, centred.
        $y = $headerHeight + 28;
        $tierText = $this->fit($tier, $bold, 16, $inner - 40);
        $tw = $this->width($tierText, $bold, 16);
        $x = (int) ((self::WIDTH - $tw) / 2);
        imagefilledrectangle($img, $x - 20, $y, $x + $tw + 20, $y + 40, $c['tint']);
        imagettftext($img, 16, 0, $x, $y + 27, $c['brand'], $bold, $tierText);
        $y += 40 + 16;

        // The QR (or why there's none).
        $qx = (int) ((self::WIDTH - $qrSide) / 2);
        if (! empty($card['token'])) {
            $matrix = $this->qr->matrix((string) $card['token']);
            $scale = max(1, intdiv($qrSide, count($matrix)));
            $offset = (int) (($qrSide - $scale * count($matrix)) / 2);
            foreach ($matrix as $row => $cells) {
                foreach ($cells as $col => $dark) {
                    if ($dark) {
                        imagefilledrectangle($img, $qx + $offset + $col * $scale, $y + $offset + $row * $scale,
                            $qx + $offset + ($col + 1) * $scale - 1, $y + $offset + ($row + 1) * $scale - 1, $c['black']);
                    }
                }
            }
        } else {
            imagerectangle($img, $qx, $y, $qx + $qrSide, $y + $qrSide, $c['line']);
            $this->centre($img, 'This ticket is no longer valid.', $bold, 18, $y + (int) ($qrSide / 2), $c['red']);
        }
        $y += $qrSide + 20;
        $this->centre($img, 'TICKET '.$card['code'], $font, 13, $y + 16, $c['faint']);
        $y += 24 + 22;

        // Name, seat, date, venue.
        foreach ($belowLines as $line) {
            $y += (int) round($line['size'] * 1.7);
            $this->centre($img, $line['text'], $line['font'], $line['size'], $y, $c[$line['color']]);
        }

        // Footer.
        $y += 30;
        imageline($img, self::PAD, $y, self::WIDTH - self::PAD, $y, $c['line']);
        $this->centre($img, 'Show this QR at the entrance. One code admits one person.', $font, 12, $y + 26, $c['faint']);

        ob_start();
        imagepng($img, null, 6);

        return (string) ob_get_clean();
    }

    private function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return (int) abs($box[2] - $box[0]);
    }

    private function centre(\GdImage $img, string $text, string $font, int $size, int $baseline, int $color): void
    {
        $text = $this->fit($text, $font, $size, self::WIDTH - 2 * self::PAD);
        imagettftext($img, $size, 0, (int) ((self::WIDTH - $this->width($text, $font, $size)) / 2), $baseline, $color, $font, $text);
    }

    /** Shorten with "…" until it fits. */
    private function fit(string $text, string $font, int $size, int $max): string
    {
        if ($this->width($text, $font, $size) <= $max) {
            return $text;
        }
        while (mb_strlen($text) > 1 && $this->width($text.'…', $font, $size) > $max) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    /** @return list<string> at most $maxLines lines; the last is shortened if needed */
    private function wrap(string $text, string $font, int $size, int $max, int $maxLines): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $try = $current === '' ? $word : "{$current} {$word}";
            if ($current !== '' && $this->width($try, $font, $size) > $max) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $try;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        if (count($lines) > $maxLines) {
            $lines = [...array_slice($lines, 0, $maxLines - 1), implode(' ', array_slice($lines, $maxLines - 1))];
        }

        return array_map(fn ($l) => $this->fit($l, $font, $size, $max), $lines);
    }
}
