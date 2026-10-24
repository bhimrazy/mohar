<?php

namespace App\Modules\Kyc\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Models\KycOrphanFile;
use App\Modules\Kyc\Services\OrphanFiles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Staff delete the identity image files FindOrphanFiles recorded (#298), after a password step-up. Confirm-then-apply:
 * the page shows a list and its digest; the delete acts only when the recorded list still has that digest, and each
 * file is checked again under its client's row lock (ADR 0021 order) by path on any client, against the rewrite
 * journal and for its age, just before it is deleted. A file that is referenced again or gone only loses its record.
 * Audited with the count and client ids, never paths (spec §5).
 */
class DeleteOrphanFiles
{
    public function __construct(private OrphanFiles $files, private ActivityLogger $activity) {}

    /**
     * @return array{files: Collection<int, KycOrphanFile>, digest: string}
     */
    public function snapshot(): array
    {
        $files = KycOrphanFile::query()->orderBy('id')->get();

        return ['files' => $files, 'digest' => hash_hmac('sha256', $files->map(fn (KycOrphanFile $file): string => $file->id.':'.$file->path)->implode('|'), (string) config('app.key'))];
    }

    /**
     * @return int files deleted
     *
     * @throws ValidationException when the recorded list changed since the page was shown
     */
    public function handle(string $digest): int
    {
        ['files' => $files, 'digest' => $current] = $this->snapshot();

        if ($files->isEmpty() || ! hash_equals($current, $digest)) {
            throw ValidationException::withMessages(['digest' => __('The list of files changed since you opened this page. Review it again before deleting.')]);
        }

        $deleted = 0;
        $clients = [];

        foreach ($files as $file) {
            try {
                if ($this->deleteOne($file)) {
                    $deleted++;
                    $clients[$file->client_id] = $file->client_id;
                }
            } catch (Throwable $exception) {
                // The exception class only: storage messages carry the path. The record stays for another try.
                Log::warning('An unreferenced identity image could not be deleted.', ['client_id' => $file->client_id, 'error' => $exception::class]);
            }
        }

        $this->activity->log('Unreferenced identity images deleted', properties: ['count' => $deleted, 'client_ids' => array_values($clients)], event: 'kyc.orphans.deleted');

        return $deleted;
    }

    private function deleteOne(KycOrphanFile $file): bool
    {
        return DB::transaction(function () use ($file): bool {
            Client::query()->whereKey($file->client_id)->lockForUpdate()->first(['id']);
            $disk = Storage::disk(KycDocument::DISK);

            $orphan = $this->files->referenced([$file->path]) === []
                && $disk->exists($file->path)
                && $disk->lastModified($file->path) < $this->files->olderThan();

            $removed = $orphan && $disk->delete($file->path);
            $file->delete();

            return $removed;
        });
    }
}
