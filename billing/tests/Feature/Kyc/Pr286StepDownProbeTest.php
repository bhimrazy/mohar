<?php

// Security re-check of #286 at d2bd85df: the step-down paths. Uses the helpers in ImageReencodeTest.php (run both files together).

use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\ImageSanitiser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake(KycDocument::DISK);
    actingAsClient();
    app(Settings::class)->set('kyc.enabled', true);
});

/** Wrap encoded bytes with EXIF (GPS + make), a comment / text chunk and an appended payload. */
function sdTainted(string $bytes, string $format): string
{
    $tiff = reencodeTiff(6);
    $tail = 'PK'."\x03\x04".'P286SD<?php system($_GET[1]); ?>';
    if ($format === 'jpeg') {
        $app1 = "Exif\0\0".$tiff;
        $com = 'P286SD-comment';

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1."\xFF\xFE".pack('n', strlen($com) + 2).$com.substr($bytes, 2).$tail;
    }
    if ($format === 'png') {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        // eXIf and tEXt go after IHDR; for an RGBA PNG the tRNS check stays on the colour type.
        return substr($bytes, 0, 33).$chunk('eXIf', $tiff).$chunk('tEXt', "Comment\0P286SD").substr($bytes, 33).$tail;
    }
    $vp8x = 'VP8X'.pack('V', 10).pack('V', 0x08).substr(pack('V', imagesx(imagecreatefromstring($bytes)) - 1), 0, 3).substr(pack('V', imagesy(imagecreatefromstring($bytes)) - 1), 0, 3);
    $exif = 'EXIF'.pack('V', strlen($tiff)).$tiff.(strlen($tiff) % 2 ? "\0" : '');
    $body = 'WEBP'.$vp8x.substr($bytes, 12).$exif;

    return 'RIFF'.pack('V', strlen($body)).$body.$tail;
}

function sdAssertClean(array $out, string $expectedMime): void
{
    $info = getimagesizefromstring($out['bytes']);
    expect($out['mime'])->toBe($expectedMime)
        ->and($info['mime'])->toBe($expectedMime)
        ->and($out['bytes'])->not->toContain('P286SD')
        ->and($out['bytes'])->not->toContain('ReencodeTestPhone')
        ->and($out['bytes'])->not->toContain('Exif')
        ->and($out['bytes'])->not->toContain('CREATOR')
        ->and($out['bytes'])->not->toContain("PK\x03\x04")
        ->and($out['bytes'])->not->toContain('<?php');
    // Nothing after the end of the image.
    match ($expectedMime) {
        'image/jpeg' => expect(substr($out['bytes'], -2))->toBe("\xFF\xD9"),
        'image/png' => expect(substr($out['bytes'], -8, 4))->toBe('IEND'),
        'image/webp' => expect(unpack('V', $out['bytes'], 4)[1] + 8)->toBe(strlen($out['bytes'])),
    };
}

test('probe: every step-down output path drops metadata and appended bytes, and the MIME follows the bytes', function (string $format, bool $alpha, string $expectedMime, array $expectedSize) {
    // 400 x 400 noise; the limit makes the first encode too large, so the step-down runs.
    config(['kyc.max_upload_kb' => 60, 'kyc.step_down_pixels' => [90_000, 10_000]]);
    $im = reencodeNoise(400, 400, $alpha);
    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($im, null, 30), 'png' => imagepng($im, null, 9), 'webp' => imagewebp($im, null, 30)
    };
    $src = sdTainted((string) ob_get_clean(), $format);
    expect($src)->toContain('P286SD');

    $out = app(ImageSanitiser::class)->clean($src, 'front');
    sdAssertClean($out, $expectedMime);
    $info = getimagesizefromstring($out['bytes']);
    expect(strlen($out['bytes']))->toBeLessThanOrEqual(60 * 1024);
    if ($expectedSize !== []) {
        expect($info[0])->toBeLessThan(400);
    }
})->with([
    'jpeg stepped' => ['jpeg', false, 'image/jpeg', [300, 300]],
    'opaque png to jpeg' => ['png', false, 'image/jpeg', []],
    'transparent png scaled' => ['png', true, 'image/png', [200, 200]],
    'webp stepped' => ['webp', false, 'image/webp', [300, 300]],
]);

test('probe: the lock is released when the last step refuses and when the decode fails', function () {
    config(['kyc.max_upload_kb' => 10, 'kyc.step_down_pixels' => [100_000]]);
    $im = reencodeNoise(400, 400);
    ob_start();
    imagejpeg($im, null, 10);
    $jpeg = (string) ob_get_clean();

    expect(fn () => app(ImageSanitiser::class)->clean($jpeg, 'front'))->toThrow(ValidationException::class);
    expect(Cache::lock(ImageSanitiser::LOCK, 1)->get())->toBeTrue();
    Cache::lock(ImageSanitiser::LOCK)->forceRelease();

    // A header that parses but pixel data that does not decode.
    $broken = substr($jpeg, 0, 600);
    expect(fn () => app(ImageSanitiser::class)->clean($broken, 'front'))->toThrow(ValidationException::class);
    expect(Cache::lock(ImageSanitiser::LOCK, 1)->get())->toBeTrue();
});

test('probe: a back image refused at the last step leaves no front file and no row', function () {
    config(['kyc.max_upload_kb' => 80, 'kyc.step_down_pixels' => [600_000]]);
    $im = reencodeNoise(800, 800);
    ob_start();
    imagejpeg($im, null, 10);
    $back = (string) ob_get_clean();
    expect(strlen($back))->toBeLessThan(80 * 1024);

    $r = reencodeSubmit(reencodeImage('jpeg'), $back);
    $r->assertSessionHasErrors(['back']);
    expect(KycDocument::query()->count())->toBe(0)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe([]);
});
