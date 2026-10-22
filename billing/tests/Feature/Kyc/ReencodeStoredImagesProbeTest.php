<?php

use App\Modules\Kyc\Exceptions\ImageDecodeBusy;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\ImageSanitiser;
use App\Modules\Kyc\Services\StoredImageReencoder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/image-fixtures.php';

/**
 * Review probes for #314 (kyc:reencode-images). Each guards a line that a mutation could remove with every existing test
 * still passing, except the first, which reproduces the spec §5 finding (a storage error carries the path).
 */
beforeEach(function () {
    Storage::fake(KycDocument::DISK);
});

function probe314Document(?string $front = null): KycDocument
{
    $document = KycDocument::factory()->create(['front_mime' => 'image/jpeg']);
    Storage::disk(KycDocument::DISK)->put($document->front_path, Crypt::encryptString($front ?? reencodeImage('jpeg', 6)));

    if ($document->back_path !== null) {
        Storage::disk(KycDocument::DISK)->put($document->back_path, Crypt::encryptString(reencodeImage('png')));
    }

    return $document;
}

function probe314Run(array $options = []): int
{
    return test()->artisan('kyc:reencode-images', ['--pause' => 0, ...$options])->run();
}

test('a storage error does not put the image path into what the run reports (spec §5)', function () {
    $document = probe314Document();
    $file = Storage::disk(KycDocument::DISK)->path($document->front_path);
    chmod($file, 0);

    try {
        probe314Run();
        $message = '';
    } catch (Throwable $exception) {
        // The console kernel reports whatever escapes the command to the log, message included.
        $message = $exception->getMessage();
    } finally {
        chmod($file, 0600);
    }

    expect($message)->not->toContain($document->front_path)->not->toContain('kyc/');
});

test('--retry-flagged re-encodes a flagged document once its file is back', function () {
    $document = probe314Document();
    $bytes = Storage::disk(KycDocument::DISK)->get($document->front_path);
    Storage::disk(KycDocument::DISK)->delete($document->front_path);

    expect(probe314Run())->toBe(1)
        ->and($document->fresh()->sanitise_issue)->toBe('unreadable');

    Storage::disk(KycDocument::DISK)->put($document->front_path, (string) $bytes);

    expect(probe314Run())->toBe(1) // still flagged without the option
        ->and(probe314Run(['--retry-flagged' => true]))->toBe(0)
        ->and($document->fresh()->sanitised_at)->not->toBeNull()
        ->and($document->fresh()->sanitise_issue)->toBeNull();
});

test('a decode lock timeout on the back image removes the front file already written and its record', function () {
    $document = probe314Document();
    $paths = $document->paths();

    app()->bind(ImageSanitiser::class, fn () => new class extends ImageSanitiser
    {
        public function clean(string $bytes, string $field): array
        {
            if ($field === 'back') {
                throw ImageDecodeBusy::withMessages([$field => 'busy']);
            }

            return parent::clean($bytes, $field);
        }
    });

    expect(probe314Run())->toBe(3)
        ->and($document->fresh()->paths())->toBe($paths)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toEqualCanonicalizing($paths)
        ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(0);
});

test('a new file removed before the switch (an overlapping run settled it) is not switched to', function () {
    $document = probe314Document();
    $paths = $document->paths();

    Event::listen(TransactionBeginning::class, function () use ($paths): void {
        foreach (array_diff(Storage::disk(KycDocument::DISK)->allFiles(), $paths) as $new) {
            Storage::disk(KycDocument::DISK)->delete($new);
        }
    });

    expect(probe314Run())->toBe(3)
        ->and($document->fresh()->paths())->toBe($paths)
        ->and($document->fresh()->sanitised_at)->toBeNull()
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toEqualCanonicalizing($paths);
});

test('a document marked re-encoded meanwhile is not switched again', function () {
    $document = probe314Document();
    $paths = $document->paths();
    $done = false;

    Event::listen(TransactionBeginning::class, function () use (&$done, $document): void {
        if (! $done) {
            $done = true;
            DB::table('kyc_documents')->where('id', $document->id)->update(['sanitised_at' => now()]);
        }
    });

    probe314Run();

    expect($document->fresh()->paths())->toBe($paths)
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toEqualCanonicalizing($paths);
});

test('settle deletes both files of a document deleted mid-run', function () {
    $disk = Storage::disk(KycDocument::DISK);
    $disk->put('kyc/1/old', 'x');
    $disk->put('kyc/1/new', 'y');
    DB::table(StoredImageReencoder::REWRITES)->insert(['document_id' => 999999999, 'old_path' => 'kyc/1/old', 'new_path' => 'kyc/1/new', 'created_at' => now(), 'updated_at' => now()]);

    expect(probe314Run())->toBe(0)
        ->and($disk->allFiles())->toBe([])
        ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(0);
});
