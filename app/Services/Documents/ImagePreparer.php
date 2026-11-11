<?php

namespace App\Services\Documents;

/**
 * An image made ready for a vision call: scaled so its longest edge is at
 * most `vision_max_edge`, and re-encoded.
 *
 * Re-encoding is the point as much as the size. It drops EXIF and every
 * other piece of metadata -- a phone photo's GPS position among them -- so
 * the provider sees pixels only. The stored original keeps its metadata.
 */
final class ImagePreparer
{
    /** The smallest per-image ceiling among the vision APIs (Anthropic's). */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @return array{bytes: string, mime: string, width: int, height: int}
     *
     * @throws ExtractionFailed when the image cannot be decoded or made small enough
     */
    public static function forVision(string $bytes, string $mime, int $maxEdge): array
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw new ExtractionFailed('The image could not be decoded; it may be damaged.');
        }
        [$width, $height] = $size;

        if (! function_exists('imagecreatefromstring')) {
            if (strlen($bytes) > self::MAX_BYTES) {
                throw new ExtractionFailed('The image is too large to send for a description, and the GD extension is not installed to shrink it.');
            }

            return ['bytes' => $bytes, 'mime' => $mime, 'width' => $width, 'height' => $height];
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new ExtractionFailed('The image could not be decoded; it may be damaged.');
        }

        $scale = min(1.0, $maxEdge / max($width, $height, 1));
        if ($scale < 1.0) {
            $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
            if ($scaled !== false) {
                $image = $scaled;
            }
        }

        // PNG stays PNG: screenshots and diagrams keep crisp text. A PNG that
        // is still too large, and everything else, goes as JPEG.
        $out = null;
        $outMime = 'image/jpeg';
        if ($mime === 'image/png') {
            imagesavealpha($image, true);
            $out = self::encode(fn () => imagepng($image, null, 9));
            $outMime = 'image/png';
        }
        if ($out === null || strlen($out) > self::MAX_BYTES) {
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $out = self::encode(fn () => imagejpeg($flat, null, 85));
            $outMime = 'image/jpeg';
        }

        if ($out === null || strlen($out) > self::MAX_BYTES) {
            throw new ExtractionFailed('The image could not be made small enough to send for a description.');
        }

        return ['bytes' => $out, 'mime' => $outMime, 'width' => $width, 'height' => $height];
    }

    private static function encode(callable $write): ?string
    {
        ob_start();
        $ok = $write();
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? $bytes : null;
    }
}
