<?php

namespace App\Support\Qr;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * QR codes without GD/Imagick (neither is installed; hosting may not have
 * them either). bacon/bacon-qr-code computes the module matrix; we draw it:
 *  - png(): a tiny grayscale PNG written by hand (zlib only), for inline
 *    e-mail images (CID); e-mail clients don't show SVG
 *  - svg(): one path, no seams; dompdf renders it without GD (it can't
 *    read PNGs without GD)
 */
class QrImage
{
    private const QUIET_ZONE = 4;

    /** @return list<list<bool>> rows of dark(true)/light(false) modules, quiet zone included */
    public function matrix(string $text): array
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
        $size = $matrix->getWidth();
        $total = $size + 2 * self::QUIET_ZONE;
        $rows = [];

        for ($y = 0; $y < $total; $y++) {
            $row = [];
            for ($x = 0; $x < $total; $x++) {
                $mx = $x - self::QUIET_ZONE;
                $my = $y - self::QUIET_ZONE;
                $row[] = $mx >= 0 && $my >= 0 && $mx < $size && $my < $size && $matrix->get($mx, $my) === 1;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Grayscale PNG, $scale pixels per module. */
    public function png(string $text, int $scale = 8): string
    {
        $rows = $this->matrix($text);
        $side = count($rows) * $scale;
        $raw = '';

        foreach ($rows as $row) {
            $line = "\0"; // filter type: none
            foreach ($row as $dark) {
                $line .= str_repeat($dark ? "\x00" : "\xFF", $scale);
            }
            $raw .= str_repeat($line, $scale);
        }

        return "\x89PNG\r\n\x1a\n"
            .$this->chunk('IHDR', pack('NNCCCCC', $side, $side, 8, 0, 0, 0, 0)) // 8-bit grayscale
            .$this->chunk('IDAT', gzcompress($raw, 9))
            .$this->chunk('IEND', '');
    }

    /** One-path SVG (no seams between modules), for dompdf and browsers. */
    public function svg(string $text): string
    {
        $rows = $this->matrix($text);
        $side = count($rows);
        $path = '';

        foreach ($rows as $y => $row) {
            // Merge horizontal runs of dark modules into one rectangle each.
            $x = 0;
            while ($x < $side) {
                if (! $row[$x]) {
                    $x++;

                    continue;
                }
                $start = $x;
                while ($x < $side && $row[$x]) {
                    $x++;
                }
                $path .= 'M'.$start.' '.$y.'h'.($x - $start).'v1h-'.($x - $start).'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$side.' '.$side.'" shape-rendering="crispEdges">'
            .'<rect width="'.$side.'" height="'.$side.'" fill="#fff"/><path fill="#000" d="'.$path.'"/></svg>';
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
