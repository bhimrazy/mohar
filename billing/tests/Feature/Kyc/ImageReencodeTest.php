<?php

use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\DocumentVault;
use App\Modules\Kyc\Services\ImageSanitiser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

test('a detailed 16 MP photo that is over 5 MB at quality 90 is stepped down until it fits', function (int $sourceQuality, array $size) {
    // Saved at a low quality the upload is under 5 MB; encoded again at quality 90 the blocks make it larger than 5 MB.
    $upload = reencodeBytes(reencodeNoise(4000, 4000), 'jpeg', $sourceQuality);
    expect(strlen($upload))->toBeLessThan(5 * 1024 * 1024);

    reencodeSubmit($upload)->assertSessionHasNoErrors();
    $stored = reencodeStoredFront();
    $info = getimagesizefromstring($stored);

    expect(strlen($stored))->toBeLessThanOrEqual(5 * 1024 * 1024)
        ->and([$info[0], $info[1]])->toBe($size)
        ->and($info['mime'])->toBe('image/jpeg')
        ->and(KycDocument::query()->sole()->front_mime)->toBe('image/jpeg');
})->with([
    'quality 75 is enough' => [10, [4000, 4000]],
    'needs 12 MP as well' => [20, [3464, 3464]],
]);

test('an image still larger than the upload limit after every step is refused', function () {
    config(['kyc.max_upload_kb' => 80, 'kyc.step_down_pixels' => [600_000]]);
    $upload = reencodeBytes(reencodeNoise(800, 800), 'jpeg', 10);
    expect(strlen($upload))->toBeLessThan(80 * 1024);

    reencodeSubmit($upload)->assertSessionHasErrors(['front' => 'The image must be 80 KB or smaller.']);
    expect(KycDocument::query()->count())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
});

test('a PNG over the limit becomes a JPEG when it is opaque, and stays a smaller PNG when it has transparency', function () {
    config(['kyc.max_upload_kb' => 200, 'kyc.step_down_pixels' => [40_000]]);
    $sanitiser = app(ImageSanitiser::class);

    $opaque = $sanitiser->clean(reencodeBytes(reencodeNoise(400, 400), 'png'), 'front');
    $transparent = $sanitiser->clean(reencodeBytes(reencodeNoise(400, 400, alpha: true), 'png'), 'front');

    expect($opaque['mime'])->toBe('image/jpeg')
        ->and(getimagesizefromstring($opaque['bytes'])['mime'])->toBe('image/jpeg')
        ->and(strlen($opaque['bytes']))->toBeLessThanOrEqual(200 * 1024)
        ->and($transparent['mime'])->toBe('image/png')
        ->and(array_slice(getimagesizefromstring($transparent['bytes']), 0, 2))->toBe([200, 200])
        ->and(strlen($transparent['bytes']))->toBeLessThanOrEqual(200 * 1024);
});

test('a WebP is scaled down to kyc.stored_webp_pixels before it is encoded, and stays a WebP', function () {
    config(['kyc.stored_webp_pixels' => 200]);

    reencodeSubmit(reencodeImage('webp'))->assertSessionHasNoErrors();
    $stored = reencodeStoredFront();

    expect(array_slice(getimagesizefromstring($stored), 0, 2))->toBe([20, 10])
        ->and(getimagesizefromstring($stored)['mime'])->toBe('image/webp');
});

test('a progressive or four-channel JPEG has the lower pixel cap, read from its frame header', function (int $sof, int $components, string $message) {
    // A frame header for 5000 × 4500 (22.5 MP) and no scan data: only the header checks can pass it.
    $frame = pack('Cnn', 8, 4500, 5000).chr($components).implode('', array_map(fn (int $id): string => chr($id)."\x11\0", range(1, $components)));
    $jpeg = "\xFF\xD8\xFF".chr($sof).pack('n', strlen($frame) + 2).$frame."\xFF\xD9";

    reencodeSubmit($jpeg)->assertSessionHasErrors(['front' => $message]);
    expect(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
})->with([
    'progressive' => [0xC2, 3, 'The image must be 20 megapixels or smaller.'],
    'CMYK baseline' => [0xC0, 4, 'The image must be 20 megapixels or smaller.'],
    // Under the 25 MP cap, so it reaches the decoder, which finds no scan data.
    'RGB baseline' => [0xC0, 3, 'Upload a photo or scan as JPEG, PNG or WebP.'],
]);

test('document uploads are limited per IP address as well as per user', function () {
    foreach (range(1, 2) as $account) {
        $client = Client::factory()->create();
        $user = User::factory()->create(['email' => $client->email]);
        $client->users()->attach($user, ['is_owner' => true]);
        $this->actingAs($user);

        foreach (range(1, 10) as $attempt) {
            $this->post(route('client.kyc.documents.store'), [])->assertSessionHasErrors('front');
        }
    }

    // A third account from the same address: the 21st upload in the minute.
    $this->actingAs(User::factory()->create());
    $this->post(route('client.kyc.documents.store'), [])->assertTooManyRequests();
});

test('the images are stored outside the database transaction, so a contended decode lock refuses instead of failing', function () {
    $baseline = DB::transactionLevel();
    $vault = new class(app(ImageSanitiser::class)) extends DocumentVault
    {
        /** @var list<int> */
        public array $levels = [];

        public function put(int $clientId, UploadedFile $file, string $field): array
        {
            $this->levels[] = DB::transactionLevel();

            return parent::put($clientId, $file, $field);
        }
    };
    app()->instance(DocumentVault::class, $vault);

    reencodeSubmit(reencodeImage('jpeg'))->assertSessionHasNoErrors();

    expect($vault->levels)->toBe([$baseline, $baseline]);
});
