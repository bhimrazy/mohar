<?php

/*
 * Image fixtures and upload helpers for the KYC re-encoding tests (ImageReencodeTest and the #286 probes). Required
 * with require_once by each file that uses them, so every file runs on its own and in any parallel worker.
 */

use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** A big-endian TIFF block: IFD0 with the camera make, the orientation and a GPS IFD (latitude 27° 42' 12.34" N). */
function reencodeTiff(int $orientation): string
{
    $entry = fn (int $tag, int $type, int $count, string $value): string => pack('nnN', $tag, $type, $count).str_pad($value, 4, "\0");
    $make = "ReencodeTestPhone\0";
    $gpsAt = 50 + strlen($make);

    return "MM\0\x2A".pack('N', 8)
        .pack('n', 3).$entry(0x010F, 2, strlen($make), pack('N', 50)).$entry(0x0112, 3, 1, pack('n', $orientation)).$entry(0x8825, 4, 1, pack('N', $gpsAt)).pack('N', 0)
        .$make
        .pack('n', 2).$entry(0x0001, 2, 2, "N\0").$entry(0x0002, 5, 3, pack('N', $gpsAt + 30)).pack('N', 0)
        .pack('N6', 27, 1, 42, 1, 1234, 100);
}

/** A 40 × 20 image, left half red and right half blue with a green 10 × 10 top-left corner, in $format, carrying reencodeTiff($orientation). */
function reencodeImage(string $format, int $orientation = 1): string
{
    $image = imagecreatetruecolor(40, 20);
    imagefilledrectangle($image, 0, 0, 19, 19, (int) imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 20, 0, 39, 19, (int) imagecolorallocate($image, 0, 0, 255));
    imagefilledrectangle($image, 0, 0, 9, 9, (int) imagecolorallocate($image, 0, 255, 0));
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image, null, 95),
        'png' => imagepng($image),
        'webp' => imagewebp($image, null, 100),
    };
    $bytes = (string) ob_get_clean();
    $tiff = reencodeTiff($orientation);

    if ($format === 'jpeg') {
        $app1 = "Exif\0\0".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($bytes, 2);
    }

    if ($format === 'png') {
        // An eXIf chunk and a text chunk after IHDR (8-byte signature + 25-byte IHDR).
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return substr($bytes, 0, 33).$chunk('eXIf', $tiff).$chunk('tEXt', "Comment\0ReencodeTestPhone").substr($bytes, 33);
    }

    // WebP: the simple VP8 file becomes the extended format (VP8X, flag 0x08 = EXIF) with an EXIF chunk.
    $vp8x = 'VP8X'.pack('V', 10).pack('V', 0x08).substr(pack('V', 39), 0, 3).substr(pack('V', 19), 0, 3);
    $exif = 'EXIF'.pack('V', strlen($tiff)).$tiff.(strlen($tiff) % 2 ? "\0" : '');
    $body = 'WEBP'.$vp8x.substr($bytes, 12).$exif;

    return 'RIFF'.pack('V', strlen($body)).$body;
}

function reencodeSubmit(string $front, ?string $back = null): TestResponse
{
    $upload = function (string $bytes, string $name): UploadedFile {
        $path = (string) tempnam(sys_get_temp_dir(), 'kyc');
        file_put_contents($path, $bytes);

        // The MIME type comes from the bytes (finfo), as in production.
        return new UploadedFile($path, $name, null, null, true);
    };

    return test()->post(route('client.kyc.documents.store'), [
        'type' => DocumentType::Citizenship->value,
        'number' => '27-01-74-01234',
        'front' => $upload($front, 'front.jpg'),
        'back' => $upload($back ?? reencodeImage('png'), 'back.png'),
    ]);
}

function reencodeStoredFront(): string
{
    return Crypt::decryptString((string) Storage::disk(KycDocument::DISK)->get(KycDocument::query()->sole()->front_path));
}

/** @return array{0: int, 1: int, 2: int} */
function reencodeRgb(GdImage $image, int $x, int $y): array
{
    $colour = imagecolorsforindex($image, imagecolorat($image, $x, $y));

    return [$colour['red'], $colour['green'], $colour['blue']];
}

/**
 * $width × $height of colour noise, detail JPEG cannot compress away. Images wider than 800 pixels repeat a 256-pixel
 * tile so they build in well under a second (JPEG does not gain from the repetition; PNG does).
 */
function reencodeNoise(int $width, int $height, bool $alpha = false): GdImage
{
    mt_srand(286);
    $side = $width > 800 ? 256 : max($width, $height);
    $tile = imagecreatetruecolor($side, $side);
    imagealphablending($tile, false);
    for ($i = 0; $i < $side * $side; $i++) {
        imagesetpixel($tile, $i % $side, intdiv($i, $side), $alpha ? mt_rand(0, 0x7FFFFFFF) : mt_rand(0, 0xFFFFFF));
    }
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    // Without it imagepng writes an RGB PNG (colour type 2), with it an RGBA one (6).
    imagesavealpha($image, $alpha);
    for ($x = 0; $x < $width; $x += $side) {
        for ($y = 0; $y < $height; $y += $side) {
            imagecopy($image, $tile, $x, $y, 0, 0, $side, $side);
        }
    }

    return $image;
}

function reencodeBytes(GdImage $image, string $format, int $quality = 0): string
{
    ob_start();
    $format === 'jpeg' ? imagejpeg($image, null, $quality) : imagepng($image, null, 9);

    return (string) ob_get_clean();
}
