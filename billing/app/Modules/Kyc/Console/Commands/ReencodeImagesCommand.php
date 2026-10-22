<?php

namespace App\Modules\Kyc\Console\Commands;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\StoredImageReencoder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Re-encodes the identity images stored before #286 (docs/devops/kyc-images.md). Run once after upgrading.
 *
 * Exit codes: 0 every document is re-encoded; 1 some documents could not be re-encoded and are flagged for staff
 * (listed by id, left as stored); 2 invalid options; 3 work remains (a dry run found documents to re-encode, --limit
 * stopped the run, an upload held the decode lock, or a document changed during the run: run it again); 4 another
 * run holds the lock.
 */
#[Signature('kyc:reencode-images
    {--dry-run : Count the documents and images to re-encode without changing anything}
    {--chunk=100 : Documents read per query}
    {--limit= : Stop after this many documents; the next run resumes}
    {--pause=200 : Milliseconds to wait between documents, so uploads get the decode lock}
    {--retry-flagged : Try the documents flagged by an earlier run again}')]
#[Description('Re-encode identity images stored before uploads were re-encoded (drops EXIF, GPS and other metadata)')]
class ReencodeImagesCommand extends Command
{
    public const INCOMPLETE = 3;

    public const LOCKED = 4;

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

        $lock = Cache::lock(StoredImageReencoder::RUN_LOCK, 6 * 3600);

        if (! $lock->get()) {
            $this->components->error('Another kyc:reencode-images run is in progress.');

            return self::LOCKED;
        }

        try {
            // A CLI run can wait longer than an upload for the decode lock (it never holds a web request).
            config(['kyc.image_decode_wait_seconds' => (int) config('kyc.reencode_decode_wait_seconds', 30)]);

            return $this->reencodeAll($reencoder, $logger, $chunk, $limit, $pause, $retry);
        } finally {
            $lock->release();
        }
    }

    private function reencodeAll(StoredImageReencoder $reencoder, ActivityLogger $logger, int $chunk, ?int $limit, int $pause, bool $retry): int
    {
        $counts = ['settled' => $reencoder->settle(), 'documents' => 0, 'images' => 0, 'reencoded' => 0, 'flagged' => 0, 'changed' => 0, 'busy' => 0];
        $after = 0;
        $budget = $limit;
        $exhausted = false;

        while ($budget === null || $budget > 0) {
            $size = $budget === null ? $chunk : min($chunk, $budget);
            // Keyset paging: a document left for the next run (busy, changed) is not read again in this one.
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
                $exhausted = true;

                break;
            }
        }

        $remaining = $reencoder->remaining()->count();
        $flagged = $reencoder->flagged()->count();
        $complete = $exhausted && $remaining === 0;

        $this->table(['Documents', 'Re-encoded', 'Images', 'Flagged', 'Changed during the run', 'Waited too long', 'Left to do'], [
            [$counts['documents'], $counts['reencoded'], $counts['images'], $counts['flagged'], $counts['changed'], $counts['busy'], $remaining],
        ]);

        // Counts only: no ids, paths or image data.
        $logger->log('Identity images stored before re-encoding were re-encoded.', properties: [
            ...$counts,
            'remaining' => $remaining,
            'flagged_total' => $flagged,
            'complete' => $complete,
        ], event: 'kyc.images.reencoded');

        return $this->conclude($reencoder, $flagged, $remaining, $complete, "Done: {$counts['reencoded']} document(s) re-encoded ({$counts['images']} image(s)).");
    }

    private function dryRun(StoredImageReencoder $reencoder, bool $retry): int
    {
        $remaining = $reencoder->remaining($retry)->count();
        $images = $reencoder->remaining($retry)->whereNotNull('back_path')->count() + $remaining;
        $flagged = $reencoder->flagged()->count();

        $this->table(['To re-encode', 'Images', 'Already re-encoded', 'Flagged'], [
            [$remaining, $images, KycDocument::query()->whereNotNull('sanitised_at')->count(), $flagged],
        ]);

        return $this->conclude($reencoder, $retry ? 0 : $flagged, $remaining, $remaining === 0, 'Every identity image is re-encoded.');
    }

    private function conclude(StoredImageReencoder $reencoder, int $flagged, int $remaining, bool $complete, string $done): int
    {
        if ($flagged > 0) {
            $ids = $reencoder->flagged()->limit(self::LISTED)->pluck('sanitise_issue', 'id');
            $this->components->error("{$flagged} document(s) could not be re-encoded and are flagged for staff review (left as stored).");

            foreach ($ids as $id => $issue) {
                $this->line("  document {$id}: ".($issue === 'unreadable' ? 'the file is missing or no key decrypts it' : 'not an image GD can decode, or over the size limits'));
            }

            if ($flagged > self::LISTED) {
                $this->line('  and '.($flagged - self::LISTED).' more.');
            }

            $this->line('Ask these clients to upload the document again; the replaced document is deleted with its images.');

            if (! $complete) {
                $this->components->warn("Not finished either: {$remaining} other document(s) left. Run the command again.");
            }

            return self::FAILURE;
        }

        if (! $complete) {
            $this->components->warn("Not finished: {$remaining} document(s) left (--limit, an upload held the decode lock, or a document changed during the run). Run the command again.");

            return self::INCOMPLETE;
        }

        $this->components->info($done);

        return self::SUCCESS;
    }
}
