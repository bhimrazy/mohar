<?php

use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\ImageSanitiser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Issue #233: every identity image is decoded and encoded again with GD before it is encrypted, so EXIF (GPS, device),
 * other metadata and appended bytes never reach storage, and the EXIF orientation is applied to the pixels.
 */
beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    actingAsClient();
    app(Settings::class)->set('kyc.enabled', true);
});

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

test('EXIF GPS, the device and other metadata are not stored, the format is kept, and the orientation is applied', function (string $format, string $mime) {
    $source = reencodeImage($format, 6);
    expect($source)->toContain('ReencodeTestPhone');

    reencodeSubmit($source)->assertSessionHasNoErrors();
    $stored = reencodeStoredFront();
    $size = getimagesizefromstring($stored);

    expect(KycDocument::query()->sole()->front_mime)->toBe($mime)
        ->and($size['mime'] ?? null)->toBe($mime)
        ->and($stored)->not->toContain('ReencodeTestPhone')->not->toContain('Exif')->not->toContain('eXIf')->not->toContain('EXIF')
        ->not->toContain(pack('N6', 27, 1, 42, 1, 1234, 100));

    if (function_exists('exif_read_data') && $format === 'jpeg') {
        expect(@exif_read_data('data://image/jpeg;base64,'.base64_encode($source))['GPSLatitudeRef'] ?? null)->toBe('N')
            ->and(@exif_read_data('data://image/jpeg;base64,'.base64_encode($stored)) ?: [])->not->toHaveKey('GPSLatitudeRef');
    }

    // Orientation 6: turned a quarter clockwise, so the red left half is now on top of a 20 × 40 image.
    $image = imagecreatefromstring($stored);
    expect([$size[0], $size[1]])->toBe([20, 40])
        ->and(reencodeRgb($image, 5, 15)[0])->toBeGreaterThan(200)
        ->and(reencodeRgb($image, 10, 35)[2])->toBeGreaterThan(200);
})->with([
    'jpeg' => ['jpeg', 'image/jpeg'],
    'png' => ['png', 'image/png'],
    'webp' => ['webp', 'image/webp'],
]);

test('each EXIF orientation is turned the way a viewer shows it', function (int $orientation, array $size, array $greenAt) {
    reencodeSubmit(reencodeImage('png', $orientation))->assertSessionHasNoErrors();
    $image = imagecreatefromstring(reencodeStoredFront());

    // Where the green top-left corner of the stored pixels ends up once the orientation is applied.
    expect([imagesx($image), imagesy($image)])->toBe($size)
        ->and(reencodeRgb($image, ...$greenAt))->toBe([0, 255, 0]);
})->with([
    'none' => [1, [40, 20], [5, 5]],
    'mirrored' => [2, [40, 20], [35, 5]],
    'upside down' => [3, [40, 20], [35, 15]],
    'flipped' => [4, [40, 20], [5, 15]],
    'transposed' => [5, [20, 40], [5, 5]],
    'quarter clockwise' => [6, [20, 40], [15, 5]],
    'transversed' => [7, [20, 40], [15, 35]],
    'quarter anticlockwise' => [8, [20, 40], [5, 35]],
]);

test('a JPEG with HTML appended is stored as a plain JPEG', function () {
    $polyglot = reencodeImage('jpeg').'<html><script>alert(document.cookie)</script></html>';

    reencodeSubmit($polyglot)->assertSessionHasNoErrors();
    $stored = reencodeStoredFront();

    expect($stored)->not->toContain('<script')->not->toContain('<html')
        ->and(str_ends_with($stored, "\xFF\xD9"))->toBeTrue()
        ->and(getimagesizefromstring($stored)['mime'] ?? null)->toBe('image/jpeg');
});

test('a file GD cannot decode is refused with the usual error, and the front stored first is deleted', function () {
    // A valid PNG header (finfo and getimagesize accept it) over pixel data that is not zlib.
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $broken = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 8, 8, 8, 2, 0, 0, 0)).$chunk('IDAT', 'not zlib data').$chunk('IEND', '');

    reencodeSubmit(reencodeImage('jpeg'), back: $broken)->assertSessionHasErrors(['back' => 'Upload a photo or scan as JPEG, PNG or WebP.']);
    reencodeSubmit($broken)->assertSessionHasErrors(['front' => 'Upload a photo or scan as JPEG, PNG or WebP.']);

    expect(KycDocument::query()->count())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
});

test('an image above 25 megapixels is refused from its header, before it is decoded', function () {
    // 6000 × 5000 = 30 MP with pixel data GD could not decode: the size message proves it never tried.
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $huge = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0)).$chunk('IDAT', 'not zlib data').$chunk('IEND', '');

    reencodeSubmit($huge)->assertSessionHasErrors(['front' => 'The image must be 25 megapixels or smaller.']);

    expect(KycDocument::query()->count())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
});

test('an image above the stored size is scaled down, keeping its aspect ratio, before it is turned', function () {
    config(['kyc.stored_image_pixels' => 200]);

    reencodeSubmit(reencodeImage('png', 6))->assertSessionHasNoErrors();
    $image = imagecreatefromstring(reencodeStoredFront());

    // 40 × 20 scaled to 20 × 10 (200 pixels), then turned.
    expect([imagesx($image), imagesy($image)])->toBe([10, 20])
        ->and(reencodeRgb($image, 2, 7)[0])->toBeGreaterThan(200);
});

test('decoding waits for the decode lock; while another upload holds it the upload is refused and nothing is stored', function () {
    config(['kyc.image_decode_wait_seconds' => 0]);
    $held = Cache::lock(ImageSanitiser::LOCK, 60);
    expect($held->get())->toBeTrue();

    reencodeSubmit(reencodeImage('jpeg'))->assertSessionHasErrors(['front' => 'Other photos are being processed. Try again in a moment.']);
    expect(KycDocument::query()->count())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);

    $held->release();
    reencodeSubmit(reencodeImage('jpeg'))->assertSessionHasNoErrors();

    // Released after each decode, so the next upload does not wait.
    expect(Cache::lock(ImageSanitiser::LOCK, 1)->get())->toBeTrue();
});

test('a re-encoded image larger than the upload limit is refused', function () {
    // Noise saved at JPEG quality 10 is small; encoded again at quality 90 it is several times larger.
    $noise = imagecreatetruecolor(800, 800);
    for ($i = 0; $i < 800 * 800; $i++) {
        imagesetpixel($noise, $i % 800, intdiv($i, 800), mt_rand(0, 0xFFFFFF));
    }
    ob_start();
    imagejpeg($noise, null, 10);
    $small = (string) ob_get_clean();
    config(['kyc.max_upload_kb' => 120]);
    expect(strlen($small))->toBeLessThan(120 * 1024);

    reencodeSubmit($small)->assertSessionHasErrors(['front' => 'The image must be 120 KB or smaller.']);
    expect(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
});
