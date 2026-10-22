<?php

use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Actions\StoreDocument;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\ImageSanitiser;
use App\Modules\Kyc\Services\StoredImageReencoder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/image-fixtures.php';

/**
 * `kyc:reencode-images` (#287): documents stored before #286 keep their original bytes (EXIF GPS, device). The command
 * re-encodes them through ImageSanitiser, writes the new file before switching the row and deletes the old one after.
 */
beforeEach(function () {
    Storage::fake(KycDocument::DISK);
});

/** A document as stored before #286: the uploaded bytes, encrypted, no sanitised_at. */
function legacyDocument(?string $front = null, ?string $back = null, array $attributes = []): KycDocument
{
    $document = KycDocument::factory()->create(['front_mime' => 'image/jpeg', ...$attributes]);
    Storage::disk(KycDocument::DISK)->put($document->front_path, Crypt::encryptString($front ?? reencodeImage('jpeg', 6)));
    if ($document->back_path !== null) {
        Storage::disk(KycDocument::DISK)->put($document->back_path, Crypt::encryptString($back ?? reencodeImage('png')));
    }

    return $document;
}

function storedImage(string $path): string
{
    return Crypt::decryptString((string) Storage::disk(KycDocument::DISK)->get($path));
}

function reencodeRun(array $options = []): int
{
    return test()->artisan('kyc:reencode-images', ['--pause' => 0, ...$options])->run();
}

test('EXIF, GPS and the device are removed from an old image, the row moves to the new files and the old ones are deleted', function () {
    $document = legacyDocument();
    [$oldFront, $oldBack] = [$document->front_path, $document->back_path];
    expect(storedImage($oldFront))->toContain('ReencodeTestPhone');

    test()->artisan('kyc:reencode-images', ['--pause' => 0])->expectsOutputToContain('Done: 1 document(s) re-encoded (2 image(s))')->assertExitCode(0);

    $document->refresh();
    $front = storedImage($document->front_path);
    expect($document->front_path)->not->toBe($oldFront)->toStartWith(dirname($oldFront).'/')
        ->and($document->sanitised_at)->not->toBeNull()
        ->and($document->sanitise_issue)->toBeNull()
        ->and($document->front_mime)->toBe('image/jpeg')
        ->and($front)->not->toContain('ReencodeTestPhone')->not->toContain('Exif')->not->toContain(pack('N6', 27, 1, 42, 1, 1234, 100))
        ->and(storedImage((string) $document->back_path))->not->toContain('ReencodeTestPhone')->not->toContain('eXIf')
        // Orientation 6 applied: 40 × 20 becomes 20 × 40.
        ->and(array_slice(getimagesizefromstring($front) ?: [], 0, 2))->toBe([20, 40])
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toEqualCanonicalizing($document->paths())
        ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(0);

    // Run once: a second run finds nothing to do.
    test()->artisan('kyc:reencode-images', ['--pause' => 0])->expectsOutputToContain('Done: 0 document(s)')->assertExitCode(0);
    expect($document->fresh()->front_path)->toBe($document->front_path);
});

test('documents uploaded since #286 are marked at upload and skipped', function () {
    actingAsClient();
    app(Settings::class)->set('kyc.enabled', true);
    reencodeSubmit(reencodeImage('jpeg'))->assertSessionHasNoErrors();
    $uploaded = KycDocument::query()->sole();

    expect($uploaded->sanitised_at)->not->toBeNull()
        ->and(reencodeRun())->toBe(0)
        ->and($uploaded->fresh()->front_path)->toBe($uploaded->front_path);
});

test('a dry run counts and changes nothing', function () {
    $document = legacyDocument();
    legacyDocument(attributes: ['back_path' => null]);
    $files = Storage::disk(KycDocument::DISK)->allFiles();

    test()->artisan('kyc:reencode-images', ['--dry-run' => true])
        ->expectsTable(['To re-encode', 'Images', 'Already re-encoded', 'Flagged'], [[2, 3, 0, 0]])
        ->assertExitCode(3);

    expect($document->fresh()->front_path)->toBe($document->front_path)
        ->and($document->fresh()->sanitised_at)->toBeNull()
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toBe($files)
        ->and(storedImage($document->front_path))->toContain('ReencodeTestPhone')
        ->and(ActivityLog::query()->where('event', 'kyc.images.reencoded')->exists())->toBeFalse()
        ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(0);
});

test('an image that cannot be re-encoded is left as stored, flagged and listed by id; the other image is re-encoded', function () {
    $corrupt = "\xFF\xD8\xFF\xE0".str_repeat('not a jpeg', 50);
    $refused = legacyDocument(front: $corrupt);
    $missing = legacyDocument();
    Storage::disk(KycDocument::DISK)->delete($missing->front_path);
    $oldBack = $refused->back_path;

    test()->artisan('kyc:reencode-images', ['--pause' => 0])
        ->expectsOutputToContain('2 document(s) could not be re-encoded and are flagged for staff review')
        ->expectsOutputToContain("document {$refused->id}: not an image GD can decode")
        ->expectsOutputToContain("document {$missing->id}: the file is missing or no key decrypts it")
        ->assertExitCode(1);

    $refused->refresh();
    expect($refused->sanitise_issue)->toBe('refused')
        ->and($refused->sanitised_at)->toBeNull()
        ->and(storedImage($refused->front_path))->toBe($corrupt)
        ->and($refused->back_path)->not->toBe($oldBack)
        ->and(storedImage((string) $refused->back_path))->not->toContain('ReencodeTestPhone')
        ->and(Storage::disk(KycDocument::DISK)->exists($oldBack))->toBeFalse()
        ->and($missing->fresh()->sanitise_issue)->toBe('unreadable');

    // A later run skips flagged documents unless asked, and keeps reporting them.
    test()->artisan('kyc:reencode-images', ['--pause' => 0])->expectsOutputToContain('2 document(s) could not be re-encoded')->assertExitCode(1);
    expect(storedImage($refused->fresh()->front_path))->toBe($corrupt);
});

test('the audit entry has counts only', function () {
    $document = legacyDocument();
    legacyDocument(front: 'garbage');

    reencodeRun();

    $log = ActivityLog::query()->where('event', 'kyc.images.reencoded')->sole();
    $raw = (string) json_encode($log->properties);
    expect($log->properties)->toBe(['settled' => 0, 'documents' => 2, 'images' => 3, 'reencoded' => 1, 'flagged' => 1, 'changed' => 0, 'busy' => 0, 'remaining' => 0, 'flagged_total' => 1, 'complete' => true])
        ->and($log->subject_id)->toBeNull()
        ->and($raw)->not->toContain('kyc/')->not->toContain($document->fresh()->front_path);
});

test('a run stopped by --limit resumes where it stopped', function () {
    $first = legacyDocument();
    $second = legacyDocument();

    expect(reencodeRun(['--limit' => 1]))->toBe(3)
        ->and($first->fresh()->sanitised_at)->not->toBeNull()
        ->and($second->fresh()->sanitised_at)->toBeNull()
        ->and(reencodeRun())->toBe(0)
        ->and($second->fresh()->sanitised_at)->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'kyc.images.reencoded')->count())->toBe(2);
});

test('a run killed around the switch loses no image and leaves no stray file after the next run', function (bool $afterCommit) {
    $document = legacyDocument();
    $old = $document->paths();

    app()->bind(StoredImageReencoder::class, fn () => new class(app(ImageSanitiser::class), $afterCommit) extends StoredImageReencoder
    {
        public function __construct(ImageSanitiser $sanitiser, private bool $afterCommit)
        {
            parent::__construct($sanitiser);
        }

        protected function switch(int $documentId, array $read, array $written, ?string $issue): ?array
        {
            $this->afterCommit && parent::switch($documentId, $read, $written, $issue);

            throw new RuntimeException('killed');
        }
    });

    expect(fn () => reencodeRun())->toThrow(RuntimeException::class, 'killed');

    // Both the old and the new files exist, the row points at one pair, and the rewrite is recorded.
    expect(Storage::disk(KycDocument::DISK)->allFiles())->toHaveCount(4)
        ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(2)
        ->and($document->fresh()->paths() === $old)->toBe(! $afterCommit)
        ->and(Cache::lock(StoredImageReencoder::RUN_LOCK)->get())->toBeTrue();
    Cache::lock(StoredImageReencoder::RUN_LOCK)->forceRelease();

    app()->forgetInstance(StoredImageReencoder::class);
    app()->offsetUnset(StoredImageReencoder::class);
    expect(reencodeRun())->toBe(0);

    $document->refresh();
    expect($document->sanitised_at)->not->toBeNull()
        ->and($document->paths())->not->toBe($old)
        ->and(storedImage($document->front_path))->not->toContain('ReencodeTestPhone')
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toEqualCanonicalizing($document->paths())
        ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(0)
        ->and(ActivityLog::query()->where('event', 'kyc.images.reencoded')->sole()->properties['settled'])->toBe(2);
})->with(['before the switch' => false, 'after the switch, before the old files are deleted' => true]);

test('a document the client replaces during the run is not brought back and the new upload is not overwritten', function () {
    [, $client] = actingAsClient();
    $document = legacyDocument(attributes: ['client_id' => $client->id, 'type' => DocumentType::Citizenship]);
    $replacement = null;

    // Right before the switch: the client uploads a new citizenship document (StoreDocument deletes the old one).
    Event::listen(TransactionBeginning::class, function () use (&$replacement, $client): void {
        if ($replacement !== null) {
            return;
        }

        $replacement = false;
        $upload = function (string $bytes): UploadedFile {
            $path = (string) tempnam(sys_get_temp_dir(), 'kyc287');
            file_put_contents($path, $bytes);

            return new UploadedFile($path, 'photo', null, null, true);
        };
        $replacement = app(StoreDocument::class)->handle($client->id, DocumentType::Citizenship, '27-01-74-99999', $upload(reencodeImage('png')), $upload(reencodeImage('png')), null);
    });

    test()->artisan('kyc:reencode-images', ['--pause' => 0])->expectsTable(
        ['Documents', 'Re-encoded', 'Images', 'Flagged', 'Changed during the run', 'Waited too long', 'Left to do'],
        [[1, 0, 0, 0, 1, 0, 0]],
    )->assertExitCode(0);

    expect($replacement)->toBeInstanceOf(KycDocument::class)
        ->and(KycDocument::query()->find($document->id))->toBeNull()
        ->and($replacement->fresh()->paths())->toBe($replacement->paths())
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toEqualCanonicalizing($replacement->paths())
        ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(0);
});

test('a document given other files during the run keeps them, and the next run re-encodes those', function () {
    $document = legacyDocument();
    $moved = dirname($document->front_path).'/moved';
    Storage::disk(KycDocument::DISK)->put($moved, Crypt::encryptString(reencodeImage('jpeg', 1)));
    $done = false;

    // As security:reencrypt does: the row is switched to a new file of the same image.
    Event::listen(TransactionBeginning::class, function () use (&$done, $document, $moved): void {
        if (! $done) {
            $done = true;
            DB::table('kyc_documents')->where('id', $document->id)->update(['front_path' => $moved]);
        }
    });

    expect(reencodeRun())->toBe(3)
        ->and($document->fresh()->front_path)->toBe($moved)
        ->and(storedImage($moved))->toContain('ReencodeTestPhone')
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toHaveCount(3);

    Event::forget(TransactionBeginning::class);
    Storage::disk(KycDocument::DISK)->delete($document->front_path);

    expect(reencodeRun())->toBe(0)
        ->and(storedImage($document->fresh()->front_path))->not->toContain('ReencodeTestPhone');
});

test('two runs never overlap, and invalid options exit with code 2', function () {
    legacyDocument();
    $lock = Cache::lock(StoredImageReencoder::RUN_LOCK, 60);
    $lock->get();

    test()->artisan('kyc:reencode-images')->expectsOutputToContain('Another kyc:reencode-images run is in progress')->assertExitCode(4);
    $lock->release();

    expect(reencodeRun(['--chunk' => 0]))->toBe(2)
        ->and(reencodeRun(['--limit' => 'x']))->toBe(2);
});

test('an upload holding the decode lock leaves the document unchanged for the next run', function () {
    $document = legacyDocument();
    config(['kyc.reencode_decode_wait_seconds' => 0]);
    $decode = Cache::lock(ImageSanitiser::LOCK, 60);
    $decode->get();

    expect(reencodeRun())->toBe(3)
        ->and($document->fresh()->paths())->toBe($document->paths())
        ->and($document->fresh()->sanitise_issue)->toBeNull()
        ->and(Storage::disk(KycDocument::DISK)->allFiles())->toHaveCount(2)
        ->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(0);

    $decode->release();
    expect(reencodeRun())->toBe(0);
});

test('staff see which documents kept their metadata', function () {
    $flagged = legacyDocument(front: 'garbage');
    legacyDocument(attributes: ['client_id' => $flagged->client_id, 'type' => DocumentType::NationalId]);
    reencodeRun();
    actingAsStaff(['kyc.documents.view']);

    $this->get(route('admin.kyc.show', $flagged->client_id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('documents.0.metadataKept', true)
        ->where('documents.1.metadataKept', false));
});
