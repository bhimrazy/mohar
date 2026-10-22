<?php

namespace App\Modules\Kyc\Console\Commands;

use App\Modules\Core\Security\Reencryptor;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\StoredImageReencoder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Re-encodes the identity images stored before #286 (docs/devops/kyc-images.md). Run once after upgrading, never while
 * an APP_KEY change is under way: the run encrypts with the key it started with (docs/devops/key-rotation.md).
 *
 * Exit codes: 0 every document is re-encoded; 1 some documents could not be re-encoded and are flagged for staff
 * (listed by id, left as stored), or a storage error stopped some (logged by document id, retried on the next run);
 * 2 invalid options; 3 work remains (a dry run found documents to re-encode, --limit stopped the run, an upload held
 * the decode lock, or a document changed during the run: run it again); 4 another run, or security:reencrypt, holds
 * its lock.
 */
#[Signature('kyc:reencode-images
    {--dry-run : Count the documents and images to re-encode without changing anything}
    {--chunk=100 : Documents read per query}
    {--limit= : Stop after this many documents; the next run resumes}
    {--pause=200 : Milliseconds to wait between documents, so uploads get the decode lock}
    {--retry-flagged : Try the documents flagged by an earlier run again}')]
#[Description('Re-encode identity images stored before uploads were re-encoded (drops EXIF, GPS and other metadata). Start it only after an APP_KEY restart is complete, and not during a key rotation\'s security:reencrypt.')]
class ReencodeImagesCommand extends Command
{
    public const INCOMPLETE = 3;

    public const LOCKED = 4;

    /** Seconds the run lock lasts; it is also released when the process ends, even after a fatal error. */
    private const LOCK_SECONDS = 6 * 3600;

    /** Flagged ids printed at most; the rest are counted. */
    private const LISTED = 100;

    public function handle(StoredImageReencoder $reencoder, ActivityLogger $logger): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $limit = $this->option('limit') === null ? null : filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $pause = filter_var($this->option('pause'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60000]]);

        if ($chunk === false || $limit === false || $pause === false) {
            $this->components->error('--chunk must be between 1 and 1000, --limit a positive number and --pause between 0 and 60000.');

            return self::INVALID;
        }

        $retry = (bool) $this->option('retry-flagged');

        if ($this->option('dry-run')) {
            return $this->dryRun($reencoder, $retry);
        }

        $lock = Cache::lock(StoredImageReencoder::RUN_LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->components->error('Another kyc:reencode-images run is in progress.');

            return self::LOCKED;
        }

        // A fatal error (out of memory) skips `finally`; a shutdown function still runs, so the next run is not refused.
        $released = false;
        $release = function () use ($lock, &$released): void {
            $released || $lock->release();
            $released = true;
        };
        register_shutdown_function($release);

        try {
            // A key rotation's re-encryption must not meet files this run writes with the key it started with.
            $reencryption = Cache::lock(Reencryptor::RUN_LOCK, 10);

            if (! $reencryption->get()) {
                $this->components->error('security:reencrypt is running. Start this command after it, once every PHP process runs on the new APP_KEY (docs/devops/key-rotation.md).');

                return self::LOCKED;
            }

            $reencryption->release();

            // A CLI run can wait longer than an upload for the decode lock (it never holds a web request).
            config(['kyc.image_decode_wait_seconds' => (int) config('kyc.reencode_decode_wait_seconds', 30)]);

            return $this->reencodeAll($reencoder, $logger, $chunk, $limit, $pause, $retry);
        } finally {
            $release();
        }
    }

    private function reencodeAll(StoredImageReencoder $reencoder, ActivityLogger $logger, int $chunk, ?int $limit, int $pause, bool $retry): int
    {
        $settle = $reencoder->settle();
        $counts = ['settled' => $settle['settled'], 'settle_failed' => $settle['failed'], 'documents' => 0, 'images' => 0, 'reencoded' => 0, 'flagged' => 0, 'changed' => 0, 'busy' => 0, 'failed' => 0];
        $after = 0;
        $budget = $limit;

        while ($budget === null || $budget > 0) {
            $size = $budget === null ? $chunk : min($chunk, $budget);
            // Keyset paging: a document left for the next run (busy, changed, failed) is not read again in this one.
            $documents = $reencoder->remaining($retry)->where('id', '>', $after)->limit($size)->get();

            foreach ($documents as $document) {
                $result = $reencoder->reencode($document);
                $counts['documents']++;
                $counts['images'] += $result['images'];
                $counts[$result['outcome']]++;
                $after = $document->id;
                $pause > 0 && usleep($pause * 1000);
            }

            $budget = $budget === null ? null : $budget - $documents->count();

            if ($documents->count() < $size) {
                break;
            }
        }

        $remaining = $reencoder->remaining()->count();
        $flagged = $reencoder->flagged()->count();
        $failed = $counts['failed'] + $counts['settle_failed'];

        $this->table(['Documents', 'Re-encoded', 'Images', 'Flagged', 'Changed during the run', 'Waited too long', 'Failed', 'Left to do'], [
            [$counts['documents'], $counts['reencoded'], $counts['images'], $counts['flagged'], $counts['changed'], $counts['busy'], $failed, $remaining],
        ]);

        // Counts only: no ids, paths or image data.
        $logger->log('Identity images stored before re-encoding were re-encoded.', properties: [
            ...$counts,
            'remaining' => $remaining,
            'flagged_total' => $flagged,
            'complete' => $remaining === 0 && $failed === 0,
        ], event: 'kyc.images.reencoded');

        if ($failed > 0) {
            $this->components->error("{$failed} document(s) or leftover file(s) failed with a storage or database error; they are unchanged, logged by document id, and retried on the next run.");
        }

        return $this->conclude($reencoder, $flagged, $remaining, $failed > 0, "Done: {$counts['reencoded']} document(s) re-encoded ({$counts['images']} image(s)).");
    }

    private function dryRun(StoredImageReencoder $reencoder, bool $retry): int
    {
        $remaining = $reencoder->remaining($retry)->count();
        $images = $reencoder->remaining($retry)->whereNotNull('back_path')->count() + $remaining;
        $flagged = $reencoder->flagged()->count();

        $this->table(['To re-encode', 'Images', 'Already re-encoded', 'Flagged'], [
            [$remaining, $images, KycDocument::query()->whereNotNull('sanitised_at')->count(), $flagged],
        ]);

        return $this->conclude($reencoder, $retry ? 0 : $flagged, $remaining, false, 'Every identity image is re-encoded.');
    }

    private function conclude(StoredImageReencoder $reencoder, int $flagged, int $remaining, bool $failed, string $done): int
    {
        if ($flagged > 0) {
            $this->components->error("{$flagged} document(s) could not be re-encoded and are flagged for staff review (left as stored).");

            foreach ($reencoder->flagged()->limit(self::LISTED)->pluck('sanitise_issue', 'id') as $id => $issue) {
                $this->line("  document {$id}: ".($issue === 'unreadable' ? 'the file is missing or no key decrypts it' : 'not an image GD can decode, or over the size limits'));
            }

            if ($flagged > self::LISTED) {
                $this->line('  and '.($flagged - self::LISTED).' more.');
            }

            $this->line('Ask these clients to upload the document again; the replaced document is deleted with its images.');
        }

        if ($remaining > 0) {
            $this->components->warn("Not finished: {$remaining} document(s) left (--limit, an upload held the decode lock, a document changed during the run, or an error). Run the command again.");
        }

        if ($flagged > 0 || $failed) {
            return self::FAILURE;
        }

        if ($remaining > 0) {
            return self::INCOMPLETE;
        }

        $this->components->info($done);

        return self::SUCCESS;
    }
}
