<?php

namespace App\Modules\Kyc\Actions;

use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Models\KycOrphanFile;
use App\Modules\Kyc\Services\OrphanFiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use SplMaxHeap;

/**
 * Daily (HOUSEKEEPING, #298): finds identity image files under kyc/ that no document refers to (an upload killed
 * between storing a file and writing its row) and records them in kyc_orphan_files. It never deletes a file: a
 * database that does not own the bucket (restored, older, or a second install sharing it) would see the owner's
 * files as orphans. Staff delete the recorded files after a step-up (DeleteOrphanFiles); the count shows under Needs
 * attention and on the identity verification page.
 *
 * Candidates: files named kyc/<client id>/<name>, older than kyc.orphan_min_age_hours (at least 24), referred to by no
 * document or rewrite (OrphanFiles). Bounded: the listing of kyc/ is read once (S3-compatible stores return 1,000
 * keys a request) and only the kyc.orphan_scan_limit smallest paths after a stored cursor are checked. Records of
 * checked paths that are referenced again are dropped, and a full pass drops records of files that are gone. Logs
 * counts only (spec §5).
 */
class FindOrphanFiles
{
    public const CURSOR = 'kyc:orphan-check:after';

    public function __construct(private OrphanFiles $files) {}

    public function handle(): int
    {
        $scanLimit = max(1, (int) config('kyc.orphan_scan_limit', 5000));
        $after = Cache::get(self::CURSOR);
        $after = is_string($after) ? $after : null;

        // The scan limit's smallest paths after the cursor, whatever order the store lists in.
        $window = new SplMaxHeap;
        $modified = [];
        $more = false;

        foreach (Storage::disk(KycDocument::DISK)->getDriver()->listContents('kyc', true) as $entry) {
            /** @var StorageAttributes $entry */
            $path = $entry->path();

            if (! $entry->isFile() || ($after !== null && strcmp($path, $after) <= 0)) {
                continue;
            }

            if ($window->count() >= $scanLimit) {
                $more = true;

                if (strcmp($path, $window->top()) >= 0) {
                    continue;
                }

                unset($modified[$window->extract()]);
            }

            $window->insert($path);
            $modified[$path] = $entry->lastModified();
        }

        $before = $this->files->olderThan();
        $candidates = array_filter($modified, fn (?int $time, string $path): bool => preg_match(OrphanFiles::PATTERN, $path) === 1 && $time !== null && $time < $before, ARRAY_FILTER_USE_BOTH);
        $orphans = array_diff_key($candidates, $this->files->referenced(array_map('strval', array_keys($candidates))));

        $now = now()->startOfSecond();
        foreach (array_chunk($orphans, 500, true) as $chunk) {
            KycOrphanFile::query()->upsert(array_map(fn (string $path, ?int $time): array => [
                'path' => $path,
                'client_id' => (int) preg_replace(OrphanFiles::PATTERN, '$1', $path),
                'modified_at' => $time === null ? null : date('Y-m-d H:i:s', $time),
                'found_at' => $now,
                'last_seen_at' => $now,
            ], array_map('strval', array_keys($chunk)), array_values($chunk)), ['path'], ['modified_at', 'last_seen_at']);
        }

        // Checked paths that are no longer orphans; on a full pass, also records of files no longer listed.
        $settled = array_values(array_diff(array_map('strval', array_keys($modified)), array_map('strval', array_keys($orphans))));
        foreach (array_chunk($settled, 500) as $chunk) {
            KycOrphanFile::query()->whereIn('path', $chunk)->delete();
        }
        if ($after === null && ! $more) {
            // A full pass listed every file: records of files not listed are gone (the table holds few rows).
            $gone = KycOrphanFile::query()->pluck('path')->reject(fn (string $path): bool => array_key_exists($path, $modified))->values()->all();
            foreach (array_chunk($gone, 500) as $chunk) {
                KycOrphanFile::query()->whereIn('path', $chunk)->delete();
            }
        }

        $last = $window->isEmpty() ? null : $window->top();
        $more && $last !== null ? Cache::forever(self::CURSOR, $last) : Cache::forget(self::CURSOR);

        Log::info('KYC orphan check finished.', ['scanned' => count($modified), 'found' => count($orphans), 'recorded' => KycOrphanFile::query()->count(), 'scan_limit_reached' => $more]);

        return count($orphans);
    }
}
