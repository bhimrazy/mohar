<?php

use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\CsvFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Re-review probes for #161 (B2), adopted: the record limit holds across quoted line breaks, which made fgetcsv read
 * the rest of a record without any length limit.
 */
function rereviewBomb(int $separators): string
{
    $path = tempnam(sys_get_temp_dir(), 'import-rereview-');
    file_put_contents($path, "First Name,Last Name,Email\n\"Asha\nRai\"".str_repeat(',', $separators)."\nAsha,Rai,asha@example.com\n");

    return $path;
}

test('probe B2-bis: a record continued after a quoted line break is held to the record limit', function () {
    expect(fn () => CsvFile::inspect(rereviewBomb(300 * 1024), null, 100_000, 200))
        ->toThrow(ValidationException::class, 'Row 1 is longer than 256 KB, the limit per row.');

    foreach ([2, 4] as $megabytes) {
        memory_reset_peak_usage();
        $before = memory_get_usage();
        try {
            CsvFile::inspect(rereviewBomb($megabytes * 1024 * 1024), null, 100_000, 200);
        } catch (ValidationException) {
        }
        $peakMb = (memory_get_peak_usage() - $before) / 1048576;

        expect($peakMb)->toBeLessThan(32.0, sprintf('inspect() of a %d MB file peaked %.1f MB above baseline', $megabytes, $peakMb));
    }
});

test('probe B2-bis: the upload refuses the quoted-line-break bomb and stores nothing', function () {
    Storage::fake('objects');
    actingAsStaff();
    confirmPassword();

    $this->post(route('admin.migration.imports.store'), ['file' => new UploadedFile(rereviewBomb(300 * 1024), 'clients.csv', 'text/csv', null, true), 'source_name' => 'Old', 'preset' => 'generic'])
        ->assertSessionHasErrors('file');

    expect(MigrationRun::query()->count())->toBe(0);
});

test('quoted cells keep their line breaks and doubled quotes', function () {
    $path = tempnam(sys_get_temp_dir(), 'import-quotes-');
    file_put_contents($path, "First Name,Last Name,Notes\r\nAsha,Rai,\"Line one\r\nLine \"\"two\"\"\"\r\n\r\nBikash,Thapa,\r\n");
    $handle = fopen($path, 'rb');

    expect(CsvFile::read($handle, ','))->toBe(['First Name', 'Last Name', 'Notes'])
        ->and(CsvFile::read($handle, ','))->toBe(['Asha', 'Rai', "Line one\r\nLine \"two\""])
        ->and(CsvFile::read($handle, ','))->toBe(['Bikash', 'Thapa', ''])
        ->and(CsvFile::read($handle, ','))->toBeNull();
});
