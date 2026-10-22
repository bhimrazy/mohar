<?php

use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Review probes for #286 (KYC image re-encoding): polyglots and metadata, decoder edge cases, type confusion and the
 * refusal shape. Every fixture is generated here, except two tiny base64 files GD cannot write (CMYK JPEG, animated
 * WebP, both under 1 KB).
 */
beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    actingAsClient();
    app(Settings::class)->set('kyc.enabled', true);
});

function p286Chunk(string $type, string $data): string
{
    return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
}

/** 40 × 20: left half red, right half blue, a green 10 × 10 top-left corner. */
function p286Gd(): GdImage
{
    $image = imagecreatetruecolor(40, 20);
    imagefilledrectangle($image, 0, 0, 19, 19, (int) imagecolorallocate($image, 255, 0, 0));
    imagefilledrectangle($image, 20, 0, 39, 19, (int) imagecolorallocate($image, 0, 0, 255));
    imagefilledrectangle($image, 0, 0, 9, 9, (int) imagecolorallocate($image, 0, 255, 0));

    return $image;
}

function p286Encode(GdImage $image, string $format): string
{
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($image, null, 95),
        'png' => imagepng($image),
        'webp' => imagewebp($image, null, 100),
    };

    return (string) ob_get_clean();
}

/** Little-endian TIFF: IFD0 = orientation, make, maker note; IFD1 = an embedded JPEG thumbnail carrying P286THUMB. */
function p286Tiff(int $orientation): string
{
    $thumb = imagecreatetruecolor(4, 4);
    $thumbJpeg = p286Encode($thumb, 'jpeg');
    $thumbJpeg = "\xFF\xD8\xFF\xFE".pack('n', 11).'P286THUMB'.substr($thumbJpeg, 2);
    $make = "P286MAKE\0";
    $note = 'P286MAKERNOTE';
    $entry = fn (int $tag, int $type, int $count, string $value): string => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");

    // Layout: header (8), IFD0 (2 + 3 × 12 + 4 = 42), IFD1 (2 + 2 × 12 + 4 = 30), make, note, thumbnail.
    $ifd1At = 8 + 42;
    $makeAt = $ifd1At + 30;
    $noteAt = $makeAt + strlen($make);
    $thumbAt = $noteAt + strlen($note);

    return "II\x2A\0".pack('V', 8)
        .pack('v', 3).$entry(0x010F, 2, strlen($make), pack('V', $makeAt)).$entry(0x0112, 3, 1, pack('v', $orientation)).$entry(0x927C, 7, strlen($note), pack('V', $noteAt)).pack('V', $ifd1At)
        .pack('v', 2).$entry(0x0201, 4, 1, pack('V', $thumbAt)).$entry(0x0202, 4, 1, pack('V', strlen($thumbJpeg))).pack('V', 0)
        .$make.$note.$thumbJpeg;
}

/** Appended after the image: a ZIP local header, PHP and HTML. */
function p286Tail(): string
{
    return "PK\x03\x04P286ZIP".'<?php echo "P286PHP"; ?>'.'<html><script>P286HTML</script></html>';
}

function p286Jpeg(int $orientation = 1, ?GdImage $image = null): string
{
    $segment = fn (int $marker, string $data): string => "\xFF".chr($marker).pack('n', strlen($data) + 2).$data;
    $bytes = p286Encode($image ?? p286Gd(), 'jpeg');

    return "\xFF\xD8"
        .$segment(0xE1, "Exif\0\0".p286Tiff($orientation))
        .$segment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>P286XMP</x:xmpmeta>")
        .$segment(0xE2, "ICC_PROFILE\0\x01\x01".'P286ICC'.str_repeat("\0", 120))
        .$segment(0xFE, 'P286COMMENT')
        .substr($bytes, 2)
        .p286Tail();
}

function p286Png(int $orientation = 1, string $extra = ''): string
{
    $bytes = p286Encode(p286Gd(), 'png');

    return substr($bytes, 0, 33)
        .p286Chunk('eXIf', str_replace("II\x2A\0", "II\x2A\0", p286Tiff($orientation)))
        .p286Chunk('tEXt', "Comment\0P286TEXT")
        .p286Chunk('zTXt', "Comment\0\0".(string) gzcompress('P286ZTXT'))
        .p286Chunk('iTXt', "Comment\0\0\0en\0\0P286ITXT")
        .$extra
        .substr($bytes, 33)
        .p286Tail();
}

function p286Webp(int $orientation = 1, int $canvasWidth = 40, int $canvasHeight = 20): string
{
    $bytes = p286Encode(p286Gd(), 'webp');
    $riffChunk = fn (string $type, string $data): string => $type.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
    // VP8X flags: ICC 0x20, EXIF 0x08, XMP 0x04.
    $vp8x = $riffChunk('VP8X', pack('V', 0x2C).substr(pack('V', $canvasWidth - 1), 0, 3).substr(pack('V', $canvasHeight - 1), 0, 3));
    $body = 'WEBP'.$vp8x.$riffChunk('ICCP', 'P286ICC'.str_repeat("\0", 120)).substr($bytes, 12)
        .$riffChunk('EXIF', p286Tiff($orientation)).$riffChunk('XMP ', '<x:xmpmeta>P286XMP</x:xmpmeta>');

    return 'RIFF'.pack('V', strlen($body)).$body.p286Tail();
}

function p286Submit(string $front, string $name = 'front.jpg', ?string $clientMime = null, bool $json = false): TestResponse
{
    $path = (string) tempnam(sys_get_temp_dir(), 'kyc');
    file_put_contents($path, $front);
    $data = [
        'type' => DocumentType::Citizenship->value,
        'number' => '27-01-74-01234',
        'front' => new UploadedFile($path, $name, $clientMime, null, true),
        'back' => UploadedFile::fake()->createWithContent('back.png', p286Encode(p286Gd(), 'png')),
    ];

    return $json ? test()->postJson(route('client.kyc.documents.store'), $data) : test()->post(route('client.kyc.documents.store'), $data);
}

function p286Stored(): string
{
    return Crypt::decryptString((string) Storage::disk(KycDocument::DISK)->get(KycDocument::query()->sole()->front_path));
}

/** @return array{0: int, 1: int, 2: int} */
function p286Rgb(GdImage $image, int $x, int $y): array
{
    $colour = imagecolorsforindex($image, imagecolorat($image, $x, $y));

    return [$colour['red'], $colour['green'], $colour['blue']];
}

function p286AssertNothingStored(): void
{
    expect(KycDocument::query()->count())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
}

test('polyglot and metadata payloads are not in the decrypted output', function (string $format, string $mime) {
    $source = match ($format) {
        'jpeg' => p286Jpeg(6),
        'png' => p286Png(6),
        'webp' => p286Webp(6),
    };
    // The fixture really carries the payloads.
    expect($source)->toContain('P286THUMB')->toContain('P286MAKERNOTE')->toContain('P286PHP')->toContain('PK'."\x03\x04");

    p286Submit($source)->assertSessionHasNoErrors();
    $stored = p286Stored();

    expect(KycDocument::query()->sole()->front_mime)->toBe($mime)
        ->and(getimagesizefromstring($stored)['mime'] ?? null)->toBe($mime)
        ->and($stored)->not->toContain('P286')->not->toContain('<?php')->not->toContain('<html')->not->toContain("PK\x03\x04")
        ->not->toContain('Exif')->not->toContain('eXIf')->not->toContain('EXIF')->not->toContain('ICC_PROFILE')->not->toContain('ICCP')
        ->not->toContain('iCCP')->not->toContain('http://ns.adobe.com')->not->toContain('XMP ')
        ->not->toContain('tEXt')->not->toContain('zTXt')->not->toContain('iTXt')
        // Nothing after the end of the image.
        ->and(match ($format) {
            'jpeg' => str_ends_with($stored, "\xFF\xD9"),
            'png' => str_ends_with($stored, 'IEND'.pack('N', crc32('IEND'))),
            'webp' => strlen($stored) === 8 + unpack('V', $stored, 4)[1],
        })->toBeTrue();
})->with([
    'jpeg' => ['jpeg', 'image/jpeg'],
    'png' => ['png', 'image/png'],
    'webp' => ['webp', 'image/webp'],
]);

test('a PNG with an iCCP colour profile chunk is accepted and the profile is dropped', function () {
    $iccp = p286Chunk('iCCP', "P286PROFILE\0\0".(string) gzcompress(str_repeat('P286ICC', 30)));

    p286Submit(p286Png(1, $iccp))->assertSessionHasNoErrors();

    expect(p286Stored())->not->toContain('iCCP')->not->toContain('P286');
});

test('JPEG and WebP orientations 3, 6 and 8 are applied to the pixels', function (string $format, int $orientation, array $size, array $greenAt) {
    p286Submit($format === 'jpeg' ? p286Jpeg($orientation) : p286Webp($orientation))->assertSessionHasNoErrors();
    $image = imagecreatefromstring(p286Stored());
    $green = p286Rgb($image, ...$greenAt);

    // Lossy formats: green dominant, not exact.
    expect([imagesx($image), imagesy($image)])->toBe($size)
        ->and($green[1])->toBeGreaterThan(180)->and($green[0])->toBeLessThan(80)->and($green[2])->toBeLessThan(80);
})->with([
    'jpeg 3' => ['jpeg', 3, [40, 20], [35, 15]],
    'jpeg 6' => ['jpeg', 6, [20, 40], [15, 5]],
    'jpeg 8' => ['jpeg', 8, [20, 40], [5, 35]],
    'webp 3' => ['webp', 3, [40, 20], [35, 15]],
    'webp 6' => ['webp', 6, [20, 40], [15, 5]],
    'webp 8' => ['webp', 8, [20, 40], [5, 35]],
]);

test('a CMYK progressive JPEG is stored as a three-channel JPEG', function () {
    $cmyk = base64_decode('/9j/7gAOQWRvYmUAZAAAAAAA/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/8IAFAgACAAQBEMRAE0RAFkRAEsRAP/EABUAAQEAAAAAAAAAAAAAAAAAAAAI/9oADgRDAE0AWQBLAAAAAaplSVKpD//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAUMAAQUCP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAU0AAQUCP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAVkAAQUCP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAUsAAQUCP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAUMABj8CP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAU0ABj8CP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAVkABj8CP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAUsABj8CP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAUMAAT8hP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAU0AAT8hP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAVkAAT8hP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAUsAAT8hP//aAA4EQwBNAFkASwAAABD/AP/EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAUMAAT8QP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAU0AAT8QP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAVkAAT8QP//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAUsAAT8QP//Z', true);
    expect(getimagesizefromstring($cmyk)['channels'] ?? null)->toBe(4);

    p286Submit($cmyk)->assertSessionHasNoErrors();
    $size = getimagesizefromstring(p286Stored());

    expect($size['mime'])->toBe('image/jpeg')->and($size['channels'] ?? null)->toBe(3)->and([$size[0], $size[1]])->toBe([16, 8]);
});

test('16-bit and interlaced PNGs are accepted and stored as 8-bit', function () {
    // 16 × 8, 16-bit RGB, not interlaced; then the same image interlaced is written by GD.
    $raw = str_repeat("\0".str_repeat("\xFF\xFF\0\0\0\0", 16), 8);
    $png16 = "\x89PNG\r\n\x1a\n".p286Chunk('IHDR', pack('NNCCCCC', 16, 8, 16, 2, 0, 0, 0)).p286Chunk('IDAT', (string) gzcompress($raw)).p286Chunk('IEND', '');

    p286Submit($png16, 'front.png')->assertSessionHasNoErrors();
    $stored = p286Stored();
    expect(ord($stored[24]))->toBe(8)->and(p286Rgb(imagecreatefromstring($stored), 3, 3))->toBe([255, 0, 0]);

    KycDocument::query()->delete();
    $image = p286Gd();
    imageinterlace($image, true);
    p286Submit(p286Encode($image, 'png'), 'front.png')->assertSessionHasNoErrors();
    expect(getimagesizefromstring(p286Stored())[0])->toBe(40);
});

test('an animated WebP is refused with the usual error and nothing is stored', function () {
    $animated = base64_decode('UklGRoQAAABXRUJQVlA4WAoAAAACAAAADwAADwAAQU5JTQYAAAAAAAAAAABBTk1GKAAAAAAAAAAAAA8AAA8AAGQAAAJWUDhMDwAAAC8PwAMABxD9j/4HIqL/AQBBTk1GKAAAAAAAAAAAAA8AAA8AAGQAAABWUDhMDwAAAC8PwAMABxDR//4HIqL/AQA=', true);

    p286Submit($animated, 'front.webp')->assertSessionHasErrors(['front' => 'Upload a photo or scan as JPEG, PNG or WebP.']);
    p286AssertNothingStored();
});

test('a WebP whose VP8X canvas lies about the frame size is refused, not decoded at the frame size', function () {
    // Canvas 2 × 2 in the header, a 40 × 20 frame inside.
    p286Submit(p286Webp(1, 2, 2), 'front.webp')->assertSessionHasErrors(['front' => 'Upload a photo or scan as JPEG, PNG or WebP.']);
    p286AssertNothingStored();
});

test('a PNG header claiming 25 MP over truncated data is refused with the validation shape, not a 500', function () {
    $lying = "\x89PNG\r\n\x1a\n".p286Chunk('IHDR', pack('NNCCCCC', 5000, 5000, 8, 6, 0, 0, 0))
        .p286Chunk('IDAT', (string) gzcompress(str_repeat("\0", 4096))).p286Chunk('IEND', '');

    p286Submit($lying, 'front.png')->assertSessionHasErrors(['front' => 'Upload a photo or scan as JPEG, PNG or WebP.']);
    p286AssertNothingStored();
});

test('the stored MIME type follows the bytes, not the client name or MIME type', function (string $bytes, string $name, string $clientMime, string $expected) {
    p286Submit($bytes, $name, $clientMime)->assertSessionHasNoErrors();

    expect(KycDocument::query()->sole()->front_mime)->toBe($expected)
        ->and(getimagesizefromstring(p286Stored())['mime'] ?? null)->toBe($expected);
})->with([
    'PNG named .jpg' => [fn () => p286Encode(p286Gd(), 'png'), 'front.jpg', 'image/jpeg', 'image/png'],
    'JPEG named .webp' => [fn () => p286Encode(p286Gd(), 'jpeg'), 'front.webp', 'image/webp', 'image/jpeg'],
    'WebP named .png' => [fn () => p286Encode(p286Gd(), 'webp'), 'front.png', 'image/png', 'image/webp'],
]);

test('a refusal over JSON is the standard 422 validation shape', function () {
    p286Submit("\x89PNG\r\n\x1a\n".p286Chunk('IHDR', pack('NNCCCCC', 6000, 5000, 8, 2, 0, 0, 0)).p286Chunk('IEND', ''), json: true)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['front' => 'The image must be 25 megapixels or smaller.']);
    p286AssertNothingStored();
});

test('the stored JPEG does not name the encoder library', function () {
    p286Submit(p286Encode(p286Gd(), 'jpeg'))->assertSessionHasNoErrors();

    expect(p286Stored())->not->toContain('gd-jpeg');
});
