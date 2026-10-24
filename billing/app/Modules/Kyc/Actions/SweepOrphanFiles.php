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
use SplMaxHeap;
use Throwable;

/**
 * Daily (HOUSEKEEPING, #298): deletes identity image files under kyc/ that no document row refers to, left behind
 * when an upload died between storing a file and writing its row (a killed container skips StoreDocument's cleanup).
 * Only files named kyc/<client id>/<name> older than kyc.orphan_min_age_hours (at least 24) are candidates, so an
 * upload or a re-encryption still on its way is never touched, and files in the re-encoder's rewrite journal are left
 * to its own recovery. References are read per client (kyc_documents_client_current_index), then again under the
 * client's row lock just before the delete, the lock StoreDocument and the re-encoders take first (ADR 0021).
 *
 * Bounded per run: the listing of kyc/ is read once (S3-compatible stores return 1,000 keys a request), and only the
 * kyc.orphan_scan_limit smallest paths after a stored cursor are checked; at most kyc.orphan_delete_limit files are
 * deleted. A run that left paths unchecked stores its largest checked path, and the next run continues after it.
 * Logs counts and client ids, never paths (spec §5).
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

        $scanned = count($modified);
        $last = $window->isEmpty() ? null : $window->top();
        $candidates = [];

        foreach ($modified as $path => $time) {
            if (preg_match(self::PATTERN, $path, $match) === 1 && $time !== null && $time < $before) {
                $candidates[(int) $match[1]][] = $path;
            }
        }

        [$deleted, $clients, $failed] = $this->deleteUnreferenced($candidates, max(1, (int) config('kyc.orphan_delete_limit', 200)));

        // A capped run continues after its last file; a full pass starts the next run from the top.
        $more && $last !== null ? Cache::forever(self::CURSOR, $last) : Cache::forget(self::CURSOR);

        Log::info('KYC orphan sweep finished.', ['scanned' => $scanned, 'deleted' => $deleted, 'failed' => $failed, 'client_ids' => $clients, 'scan_limit_reached' => $more]);

        return $deleted;
    }

    /**
     * @param  array<int, list<string>>  $candidates  paths by client id
     * @return array{int, list<int>, int} deleted, the clients whose files were deleted, failed deletes
     */
    private function deleteUnreferenced(array $candidates, int $limit): array
    {
        $deleted = 0;
        $failed = 0;
        $clients = [];

        foreach (array_chunk(array_keys($candidates), 500) as $clientIds) {
            $referenced = $this->referencedPaths($clientIds);

            foreach ($clientIds as $clientId) {
                foreach (array_diff($candidates[$clientId], $referenced) as $path) {
                    if ($deleted >= $limit) {
                        break 3;
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
            }
        }

        return [$deleted, array_values($clients), $failed];
    }

    /**
     * @param  list<int>  $clientIds
     * @return list<string>
     */
    private function referencedPaths(array $clientIds): array
    {
        $paths = KycDocument::query()->whereIn('client_id', $clientIds)->get(['id', 'front_path', 'back_path'])
            ->flatMap(fn (KycDocument $document): array => $document->paths());

        return [...$paths->all(), ...$this->journalPaths()];
    }

    /**
     * Both paths of every half-finished rewrite (a short table: rows live only while a re-encode run is cut off).
     *
     * @return list<string>
     */
    private function journalPaths(): array
    {
        return DB::table(StoredImageReencoder::REWRITES)->get(['old_path', 'new_path'])
            ->flatMap(fn (object $row): array => [(string) $row->old_path, (string) $row->new_path])
            ->all();
    }

    private function deleteIfUnreferenced(int $clientId, string $path): bool
    {
        return DB::transaction(function () use ($clientId, $path): bool {
            Client::query()->whereKey($clientId)->lockForUpdate()->first(['id']);

            $stillReferenced = KycDocument::query()->where('client_id', $clientId)
                ->where(fn ($query) => $query->where('front_path', $path)->orWhere('back_path', $path))
                ->exists()
                || DB::table(StoredImageReencoder::REWRITES)->where('old_path', $path)->orWhere('new_path', $path)->exists();

            return ! $stillReferenced && Storage::disk(KycDocument::DISK)->delete($path);
        });
    }
}
