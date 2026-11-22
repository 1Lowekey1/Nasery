<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Uploaded pictures (profile photos, organizer logos and covers, event covers).
 *
 * The original upload is never kept. Each file is:
 *   1. size-checked (bytes) and identified by its CONTENT (magic bytes via
 *      finfo + getimagesize), not its name or the browser's claim: only JPEG,
 *      PNG or WebP. SVG/GIF/anything else is refused (SVG can carry scripts).
 *   2. dimension-checked before decoding (a tiny file that expands into a
 *      gigantic bitmap can't exhaust memory).
 *   3. decoded by GD, turned upright (camera EXIF orientation), resized or
 *      cropped to a fixed size, and re-encoded as a NEW WebP. Anything that
 *      isn't pixels — EXIF/GPS, comments, appended data, polyglot payloads —
 *      is gone.
 *   4. written under a random name (UUID) in storage/app/media/<folder>/,
 *      outside the public web folder; served by MediaController with a fixed
 *      image/webp type and nosniff.
 */
class ImageStore
{
    /**
     * kind => [folder, width, height, fit, WebP quality (default 82)]. fit: cover (crop to fill) |
     * contain (whole image, transparent padding) | within (whole image, its own shape, only
     * shrunk to fit the box, never enlarged: for pictures people look at closely).
     */
    public const KINDS = [
        'avatar' => ['avatars', 512, 512, 'cover'],
        'logo' => ['logos', 512, 512, 'contain'],
        'cover' => ['covers', 1600, 600, 'cover'],
        'event-cover' => ['event-covers', 1600, 900, 'cover'],
        // 0062 (organizer field test): a picture per price list item, and the client's mood board.
        // Members' testing (Nov 2026): no cropping and more detail (previews looked pixelated).
        'price-option' => ['price-options', 2400, 2400, 'within', 88],
        'mood' => ['mood-boards', 2400, 2400, 'within', 88],
    ];

    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    /** @return string relative path, e.g. "avatars/<uuid>.webp" */
    public function store(UploadedFile $file, string $kind): string
    {
        [$folder, $width, $height, $fit] = self::KINDS[$kind] ?? throw new \InvalidArgumentException("Unknown image kind {$kind}.");
        $quality = self::KINDS[$kind][4] ?? 82;
        $limits = config('nasery.media');

        if (! $file->isValid()) {
            $this->fail('The upload did not finish. Please try again.');
        }
        if ($file->getSize() > $limits['max_bytes']) {
            $this->fail('Choose an image under '.round($limits['max_bytes'] / 1048576).' MB.');
        }

        // Identify by content (read once into memory; no reliance on the temp file's name).
        // (An antivirus may block a malicious upload's temp file: that's a rejection, not an error.)
        try {
            $bytes = (string) $file->get();
        } catch (\Throwable) {
            $this->fail('That file could not be read. Choose a JPG, PNG or WebP image.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! isset(self::TYPES[$info[2]]) || self::TYPES[$info[2]] !== $mime) {
            $this->fail('Choose a JPG, PNG or WebP image.');
        }

        [$w, $h] = $info;
        if ($w > $limits['max_dimension'] || $h > $limits['max_dimension'] || $w * $h > $limits['max_pixels']) {
            $this->fail("That image is too large ({$w}×{$h} px). Use one up to {$limits['max_dimension']} px on each side.");
        }
        if ($w < $limits['min_dimension'] || $h < $limits['min_dimension']) {
            $this->fail("That image is too small ({$w}×{$h} px). Use one at least {$limits['min_dimension']} px on each side.");
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            $this->fail('That file could not be read as an image.');
        }

        try {
            $source = $this->upright($source, $bytes, $info[2]);
            $canvas = $this->fit($source, $width, $height, $fit);
            imagedestroy($source);

            $relative = $folder.'/'.Str::uuid7().'.webp';
            $target = $this->absolute($relative);
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0750, true);
            }
            if (! imagewebp($canvas, $target, $quality)) {
                throw new \RuntimeException('Could not save the image.');
            }
            imagedestroy($canvas);
        } catch (\Throwable $e) {
            report($e);
            $this->fail('That image could not be processed. Try another one.');
        }

        return $relative;
    }

    /** Remove a stored file (ignores anything that isn't one of ours). */
    public function delete(?string $relative): void
    {
        if ($relative !== null && self::valid($relative) && is_file($file = $this->absolute($relative))) {
            @unlink($file);
        }
    }

    public function absolute(string $relative): string
    {
        return rtrim((string) config('nasery.media.root'), '\\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /** A stored path looks exactly like "<known folder>/<uuid>.webp" (no .., no slashes elsewhere). */
    public static function valid(string $relative): bool
    {
        $folders = implode('|', array_map(fn ($k) => preg_quote($k[0], '/'), self::KINDS));

        return (bool) preg_match('/^('.$folders.')\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.webp$/', $relative);
    }

    /** Public URL for a stored path (null-safe). */
    public static function url(?string $relative): ?string
    {
        return $relative !== null && self::valid($relative) ? url('/api/v1/media/'.$relative) : null;
    }

    /** Phone photos store "rotate me" in EXIF instead of rotating the pixels. */
    private function upright(\GdImage $image, string $bytes, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $orientation = (int) (@exif_read_data($stream)['Orientation'] ?? 1);
        fclose($stream);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private function fit(\GdImage $source, int $width, int $height, string $fit): \GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        if ($fit === 'within') {
            // The whole picture in its own shape, shrunk only if it's bigger than the box.
            $scale = min(1, $width / $sw, $height / $sh);
            $width = max(1, (int) round($sw * $scale));
            $height = max(1, (int) round($sh * $scale));
        }
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        if ($fit === 'within') {
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $sw, $sh);
        } elseif ($fit === 'cover') {
            // Crop the centre to the target shape, then scale.
            $scale = max($width / $sw, $height / $sh);
            $cw = (int) round($width / $scale);
            $ch = (int) round($height / $scale);
            imagecopyresampled($canvas, $source, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $width, $height, $cw, $ch);
        } else {
            // Whole image inside the square (logos), centred, transparent around it.
            $scale = min($width / $sw, $height / $sh);
            $dw = (int) round($sw * $scale);
            $dh = (int) round($sh * $scale);
            imagecopyresampled($canvas, $source, (int) (($width - $dw) / 2), (int) (($height - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        }
        imagesavealpha($canvas, true);

        return $canvas;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['image' => $message]);
    }
}
