<?php

use App\Modules\Core\Security\Reencryptor;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\ImageSanitiser;
use App\Modules\Kyc\Services\StoredImageReencoder;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/image-fixtures.php';

/**
 * Re-check probes for #314 after the fix round (head 71c7764f).
 */
function recheck314Document(): KycDocument
{
    $document = KycDocument::factory()->create(['front_mime' => 'image/jpeg', 'back_path' => null]);
    Storage::disk(KycDocument::DISK)->put($document->front_path, Crypt::encryptString(reencodeImage('jpeg', 6)));

    return $document;
}

function recheck314Run(array $options = []): int
{
    return test()->artisan('kyc:reencode-images', ['--pause' => 0, ...$options])->run();
}

test('a delete that returns false (a disk that does not throw) is logged by document id and the record is kept', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root ignores directory permissions.');
    }

    Storage::fake(KycDocument::DISK, ['throw' => false]);
    $disk = Storage::disk(KycDocument::DISK);
    $disk->put('kyc/314/old', 'x');
    $disk->put('kyc/314/new', 'y');
    DB::table(StoredImageReencoder::REWRITES)->insert(['document_id' => 999999998, 'old_path' => 'kyc/314/old', 'new_path' => 'kyc/314/new', 'created_at' => now(), 'updated_at' => now()]);
    $directory = $disk->path('kyc/314');
    chmod($directory, 0555);
    Log::spy();

    try {
        $code = recheck314Run();
    } finally {
        chmod($directory, 0755);
    }

    expect($code)->toBe(1)->and(DB::table(StoredImageReencoder::REWRITES)->count())->toBe(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['document_id' => 999999998, 'error' => RuntimeException::class]);
});

test('a record whose settle failed is not forgotten when the same document is then re-encoded in that run', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root ignores directory permissions.');
    }

    Storage::fake(KycDocument::DISK);
    $disk = Storage::disk(KycDocument::DISK);
    $document = recheck314Document();
    // A killed run wrote this new file before switching; the row still points at the old one.
    $disk->put('kyc/stuck/new', Crypt::encryptString('re-encoded copy'));
    DB::table(StoredImageReencoder::REWRITES)->insert(['document_id' => $document->id, 'old_path' => $document->front_path, 'new_path' => 'kyc/stuck/new', 'created_at' => now(), 'updated_at' => now()]);
    $directory = $disk->path('kyc/stuck');
    chmod($directory, 0555); // a transient delete failure during settle()

    try {
        recheck314Run();
    } finally {
        chmod($directory, 0755);
    }

    // The document itself was re-encoded in the same run. The stuck file is referenced by no row: either it is gone
    // or a record still points at it so the next run deletes it. Never an orphan with no record.
    expect($document->fresh()->sanitised_at)->not->toBeNull();
    expect($disk->exists('kyc/stuck/new') === false || DB::table(StoredImageReencoder::REWRITES)->where('new_path', 'kyc/stuck/new')->exists())
        ->toBeTrue('kyc/stuck/new is left with no row and no rewrite record: no later run or retention deletes it');
});

test('the lock kyc:reencode-images checks is the one security:reencrypt really takes', function () {
    Storage::fake(KycDocument::DISK);
    $held = Cache::lock(Reencryptor::RUN_LOCK, 60);
    $held->get();

    // The real command refuses on that lock (same name, same store), so the constant ties the two commands together.
    expect(test()->artisan('security:reencrypt')->run())->toBe(4)
        ->and(recheck314Run())->toBe(4);

    $held->release();
});

test('settle() deleting after its commit cannot remove a file a still-running run switches to in between (lock expired after 6 h)', function () {
    Storage::fake(KycDocument::DISK);
    $disk = Storage::disk(KycDocument::DISK);
    $document = recheck314Document();
    $old = $document->front_path;
    // Run A (still alive, its run lock expired) wrote this file and recorded it, and is about to switch.
    $new = dirname($old).'/run-a-new';
    $disk->put($new, Crypt::encryptString(reencodeImage('jpeg')));
    DB::table(StoredImageReencoder::REWRITES)->insert(['document_id' => $document->id, 'old_path' => $old, 'new_path' => $new, 'created_at' => now(), 'updated_at' => now()]);

    $runA = new StoredImageReencoder(app(ImageSanitiser::class));
    $fired = false;
    // Run B's settle() commits; before it deletes, run A's switch() runs (it sees the new file still there).
    Event::listen(TransactionCommitted::class, function () use (&$fired, $runA, $document, $old, $new): void {
        if ($fired) {
            return;
        }
        $fired = true;
        (fn () => $this->switch($document->id, ['front_path' => $old, 'back_path' => null], ['front_path' => ['old' => $old, 'new' => $new, 'mime_column' => 'front_mime', 'mime' => 'image/jpeg']], null))->call($runA);
    });

    recheck314Run();

    $path = $document->fresh()->front_path;
    expect($disk->exists($path))->toBeTrue("the row points at {$path}, which settle() deleted after run A switched to it");
});
