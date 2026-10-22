<?php

namespace App\Modules\Kyc\Services;

use GdImage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Decodes an identity image with GD and encodes it again in the same format, so only pixels are kept: EXIF (GPS
 * position, device, embedded thumbnails), ICC and text chunks, comments, and anything appended after the image data
 * are dropped. A payload hidden in the pixel data itself (a lossless PNG can carry one) is not removed. The EXIF
 * orientation is applied to the pixels first, so a portrait phone photo stays upright for staff.
 *
 * Memory: the dimensions are checked from the header before decoding (kyc.max_image_pixels), a large photo is scaled
 * down before it is turned (kyc.stored_image_pixels), and one decode runs at a time across all web processes that
 * share the cache lock store, because a single 25 MP decode needs about 200 MB.
 */
class ImageSanitiser
{
    /** The formats we accept (DocumentRequest::IMAGE_TYPES), keyed by the IMAGETYPE_* constant GD reports. */
    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    /** Held while an image is decoded and encoded again; released when the closure returns or throws. */
    public const LOCK = 'kyc:image-decode';

    /** Seconds the lock outlives a process that dies while holding it. */
    private const LOCK_TTL_SECONDS = 60;

    /**
     * @return array{bytes: string, mime: string}
     *
     * @throws ValidationException when the bytes are not a JPEG, PNG or WebP image GD can decode, or are too large
     */
    public function clean(string $bytes, string $field): array
    {
        $size = @getimagesizefromstring($bytes);
        $type = is_array($size) ? $size[2] : null;

        if (! is_array($size) || ! isset(self::TYPES[$type]) || $size[0] < 1 || $size[1] < 1) {
            throw $this->refuse($field, __('Upload a photo or scan as JPEG, PNG or WebP.'));
        }

        $limit = (int) config('kyc.max_image_pixels', 25_000_000);

        if ($limit < $size[0] * $size[1]) {
            throw $this->refuse($field, __('The image must be :count megapixels or smaller.', ['count' => intdiv($limit, 1_000_000)]));
        }

        try {
            $encoded = Cache::lock(self::LOCK, self::LOCK_TTL_SECONDS)
                ->block((int) config('kyc.image_decode_wait_seconds', 15), fn (): string => $this->reencode($bytes, $type, $field));
        } catch (LockTimeoutException) {
            throw $this->refuse($field, __('Other photos are being processed. Try again in a moment.'));
        }

        // The stored copy obeys the upload limit too: the review page and security:reencrypt hold it in memory.
        $maxKb = (int) config('kyc.max_upload_kb', 5120);

        if ($maxKb * 1024 < strlen($encoded)) {
            throw $this->refuse($field, $maxKb >= 1024
                ? __('The image must be :count MB or smaller.', ['count' => intdiv($maxKb, 1024)])
                : __('The image must be :count KB or smaller.', ['count' => $maxKb]));
        }

        return ['bytes' => $encoded, 'mime' => self::TYPES[$type]];
    }

    /**
     * Decode, scale, turn and encode. Runs only under self::LOCK; the images are freed when it returns.
     */
    private function reencode(string $bytes, int $type, string $field): string
    {
        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw $this->refuse($field, __('Upload a photo or scan as JPEG, PNG or WebP.'));
        }

        // One statement each, so only two images are alive at a time.
        $image = $this->shrink($image);
        $image = $this->orient($image, $this->orientation($bytes, $type));
        $encoded = $this->encode($image, $type);

        return $type === IMAGETYPE_JPEG ? $this->withoutComments($encoded) : $encoded;
    }

    /**
     * Remove the COM segments (0xFFFE) before the scan data; GD writes one naming itself and its JPEG library.
     */
    private function withoutComments(string $jpeg): string
    {
        $kept = substr($jpeg, 0, 2);
        $offset = 2;
        $length = strlen($jpeg);

        while ($offset + 4 <= $length && $jpeg[$offset] === "\xFF" && $jpeg[$offset + 1] !== "\xDA") {
            $size = $this->number('n', $jpeg, $offset + 2);

            if ($size < 2) {
                break;
            }

            if ($jpeg[$offset + 1] !== "\xFE") {
                $kept .= substr($jpeg, $offset, 2 + $size);
            }

            $offset += 2 + $size;
        }

        return $kept.substr($jpeg, $offset);
    }

    private function refuse(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }

    private function encode(GdImage $image, int $type): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();

        try {
            $written = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($image, null, 90),
                IMAGETYPE_PNG => imagepng($image, null, 6),
                default => imagewebp($image, null, 90),
            };
        } finally {
            $encoded = (string) ob_get_clean();
        }

        if (! $written || $encoded === '') {
            throw new RuntimeException('GD could not encode the identity image.');
        }

        return $encoded;
    }

    /**
     * Scale an image above kyc.stored_image_pixels down to that size, keeping its aspect ratio, so the rotation that
     * may follow copies the smaller image.
     */
    private function shrink(GdImage $image): GdImage
    {
        $target = (int) config('kyc.stored_image_pixels', 16_000_000);
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width * $height <= $target) {
            return $image;
        }

        $scale = sqrt($target / ($width * $height));
        $scaled = imagescale($image, max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale)));

        if ($scaled === false) {
            throw new RuntimeException('GD could not scale the identity image.');
        }

        return $scaled;
    }

    /**
     * Turn the pixels the way the EXIF orientation (1–8) says a viewer should show them.
     */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        // imagerotate turns counter-clockwise; 270 is a quarter turn clockwise.
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);

            if ($rotated === false) {
                throw new RuntimeException('GD could not rotate the identity image.');
            }

            $image = $rotated;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /**
     * The EXIF orientation of a JPEG (APP1), PNG (eXIf) or WebP (EXIF chunk), or 1 when there is none. Read from the
     * bytes so the `exif` extension is not needed.
     */
    private function orientation(string $bytes, int $type): int
    {
        $tiff = match ($type) {
            IMAGETYPE_JPEG => $this->jpegExif($bytes),
            IMAGETYPE_PNG => $this->chunk($bytes, 8, 'eXIf', riff: false),
            default => $this->chunk($bytes, 12, 'EXIF', riff: true),
        };

        if ($tiff === null) {
            return 1;
        }

        if (str_starts_with($tiff, "Exif\0\0")) {
            $tiff = substr($tiff, 6);
        }

        return $this->tiffOrientation($tiff);
    }

    private function jpegExif(string $bytes): ?string
    {
        $offset = 2;
        $length = strlen($bytes);

        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);
            $size = $this->number('n', $bytes, $offset + 2);

            // Start of scan or end of image: no more metadata segments follow.
            if ($marker === 0xDA || $marker === 0xD9 || $size < 2) {
                return null;
            }

            if ($marker === 0xE1 && substr($bytes, $offset + 4, 6) === "Exif\0\0") {
                return substr($bytes, $offset + 10, $size - 8);
            }

            $offset += 2 + $size;
        }

        return null;
    }

    /**
     * Walk PNG chunks (big-endian length, type, data, CRC) or RIFF chunks (type, little-endian length, data padded to
     * an even length) from $offset and return the data of the first chunk named $name.
     */
    private function chunk(string $bytes, int $offset, string $name, bool $riff): ?string
    {
        $length = strlen($bytes);

        while ($offset + 8 <= $length) {
            [$size, $type] = $riff
                ? [$this->number('V', $bytes, $offset + 4), substr($bytes, $offset, 4)]
                : [$this->number('N', $bytes, $offset), substr($bytes, $offset + 4, 4)];

            if ($type === $name) {
                return substr($bytes, $offset + 8, $size);
            }

            $offset += 8 + $size + ($riff ? $size % 2 : 4);
        }

        return null;
    }

    private function tiffOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);

        if (strlen($tiff) < 8 || ($order !== 'II' && $order !== 'MM')) {
            return 1;
        }

        [$short, $long] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];
        $ifd = $this->number($long, $tiff, 4);

        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $entries = $this->number($short, $tiff, $ifd);

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > strlen($tiff)) {
                return 1;
            }

            if ($this->number($short, $tiff, $entry) === 0x0112) {
                $value = $this->number($short, $tiff, $entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * An unsigned integer read with unpack() ('n', 'v': 16 bits; 'N', 'V': 32 bits), or 0 past the end of $bytes.
     */
    private function number(string $format, string $bytes, int $offset): int
    {
        $width = $format === 'n' || $format === 'v' ? 2 : 4;
        $value = $offset >= 0 && $offset + $width <= strlen($bytes) ? unpack($format, $bytes, $offset) : false;

        return is_array($value) && is_int($value[1]) ? $value[1] : 0;
    }
}
