<?php

namespace App\Modules\Kyc\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\StoredImageReencoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use SplMaxHeap;
use Throwable;

/**
 * Daily (HOUSEKEEPING, #298): deletes identity image files under kyc/ that no document row refers to, left behind
 * when an upload died between storing a file and writing its row (a killed container skips StoreDocument's cleanup).
 * Only files named kyc/<client id>/<name> older than kyc.orphan_min_age_hours (at least 24) are candidates, so an
 * upload or a re-encryption still on its way is never touched, and files in the re-encoder's rewrite journal are left
 * to its own recovery. A file `security:reencrypt` wrote but had not switched a row to yet is old only if that run was
 * killed, and its own settle() deletes whichever file the row does not point at, so deleting it here first is harmless.
 *
 * References are read per client (kyc_documents_client_current_index), then again just before the delete under the
 * client's row lock (the lock StoreDocument and the re-encoders take first, ADR 0021), there by path alone, so a row
 * on another client than the path names still keeps its file.
 *
 * Circuit breaker: real orphans are rare (#298 could not produce one), so a run that finds more than
 * kyc.orphan_breaker unreferenced files, or finds any while kyc_documents is empty, deletes nothing and reports it.
 * That is what a restored or older database, or a second install sharing the bucket, looks like.
 *
 * Bounded per run: the listing of kyc/ is read once (S3-compatible stores return 1,000 keys a request), and only the
 * kyc.orphan_scan_limit smallest paths after a stored cursor are checked; at most kyc.orphan_delete_limit files are
 * deleted. The next run continues after the last path this run finished with. Logs counts and client ids, never
 * paths (spec §5).
 */
class SweepOrphanFiles
{
    public const CURSOR = 'kyc:orphan-sweep:after';

    private const PATTERN = '#^kyc/(\d+)/[^/]+$#';

    public function handle(): int
    {
        $scanLimit = max(1, (int) config('kyc.orphan_scan_limit', 5000));
        $before = now()->subHours(max(24, (int) config('kyc.orphan_min_age_hours', 24)))->getTimestamp();
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

        ksort($modified, SORT_STRING);
        $orphans = $this->unreferenced($modified, $before);
        $breaker = max(0, (int) config('kyc.orphan_breaker', 20));

        if ($orphans !== [] && (count($orphans) > $breaker || ! KycDocument::query()->exists())) {
            // The cursor stays: every run trips again until someone looks.
            report(new RuntimeException('KYC orphan sweep stopped: '.count($orphans).' identity image files have no document row, more than expected. Check that this database owns the objects bucket; nothing was deleted.'));
            Log::warning('KYC orphan sweep stopped by its circuit breaker; nothing was deleted.', ['scanned' => count($modified), 'unreferenced' => count($orphans), 'breaker' => $breaker, 'documents' => KycDocument::query()->count()]);

            return 0;
        }

        [$deleted, $clients, $failed, $stoppedAt] = $this->delete($orphans, max(1, (int) config('kyc.orphan_delete_limit', 200)));

        // Stopped by the delete limit: continue just before the first orphan left. Otherwise after the window, or from
        // the top when the window reached the end of the listing.
        $paths = array_keys($modified);
        $next = $stoppedAt !== null
            ? ($paths[array_search($stoppedAt, $paths, true) - 1] ?? $after)
            : ($more ? end($paths) : null);
        $next !== null && $next !== false ? Cache::forever(self::CURSOR, $next) : Cache::forget(self::CURSOR);

        Log::info('KYC orphan sweep finished.', ['scanned' => count($modified), 'deleted' => $deleted, 'failed' => $failed, 'client_ids' => $clients, 'scan_limit_reached' => $more]);

        return $deleted;
    }

    /**
     * Candidate paths (old enough, named kyc/<client id>/<name>) that no document or rewrite refers to, in path order.
     *
     * @param  array<string, int|null>  $modified  path => last modified, sorted by path
     * @return array<string, int> path => client id
     */
    private function unreferenced(array $modified, int $before): array
    {
        $candidates = [];

        foreach ($modified as $path => $time) {
            if (preg_match(self::PATTERN, (string) $path, $match) === 1 && $time !== null && $time < $before) {
                $candidates[(string) $path] = (int) $match[1];
            }
        }

        $referenced = array_flip($this->journalPaths());

        foreach (array_chunk(array_values(array_unique($candidates)), 500) as $clientIds) {
            foreach (KycDocument::query()->whereIn('client_id', $clientIds)->get(['id', 'front_path', 'back_path']) as $document) {
                foreach ($document->paths() as $path) {
                    $referenced[$path] = true;
                }
            }
        }

        return array_diff_key($candidates, $referenced);
    }

    /**
     * @param  array<string, int>  $orphans  path => client id, in path order
     * @return array{int, list<int>, int, ?string} deleted, the clients whose files were deleted, failed deletes, the
     *                                             first orphan left because the delete limit was reached
     */
    private function delete(array $orphans, int $limit): array
    {
        $deleted = 0;
        $failed = 0;
        $clients = [];

        foreach ($orphans as $path => $clientId) {
            if ($deleted >= $limit) {
                return [$deleted, array_values($clients), $failed, $path];
            }

            try {
                if ($this->deleteIfUnreferenced($clientId, $path)) {
                    $deleted++;
                    $clients[$clientId] = $clientId;
                }
            } catch (Throwable $exception) {
                // The exception class only: storage messages carry the path (spec §5). The next run retries.
                Log::warning('An unreferenced identity image could not be deleted.', ['client_id' => $clientId, 'error' => $exception::class]);
                $failed++;
            }
        }

        return [$deleted, array_values($clients), $failed, null];
    }

    /**
     * Both paths of every half-finished rewrite (a short table: rows live only while a re-encode run is cut off).
     *
     * @return list<string>
     */
    private function journalPaths(): array
    {
        $paths = [];

        foreach (DB::table(StoredImageReencoder::REWRITES)->get(['old_path', 'new_path']) as $row) {
            array_push($paths, (string) $row->old_path, (string) $row->new_path);
        }

        return $paths;
    }

    /**
     * Matches on the path alone (no index on the path columns: at most kyc.orphan_delete_limit scans of a table with a
     * few rows per verified client).
     */
    private function deleteIfUnreferenced(int $clientId, string $path): bool
    {
        return DB::transaction(function () use ($clientId, $path): bool {
            Client::query()->whereKey($clientId)->lockForUpdate()->first(['id']);

            $stillReferenced = KycDocument::query()->where(fn ($query) => $query->where('front_path', $path)->orWhere('back_path', $path))->exists()
                || DB::table(StoredImageReencoder::REWRITES)->where('old_path', $path)->orWhere('new_path', $path)->exists();

            return ! $stillReferenced && Storage::disk(KycDocument::DISK)->delete($path);
        });
    }
}
