<?php

namespace App\Modules\Kyc\Services;

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Exceptions\ImageDecodeBusy;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Re-encodes the images of documents stored before #286 (`kyc:reencode-images`, #287), one image at a time through
 * ImageSanitiser, so under its decode lock and memory caps.
 *
 * Per document: each image is decrypted, re-encoded and encrypted under a new random name, recorded in
 * kyc_image_rewrites first. Then, in one transaction holding the client's lock and then the document's (ADR 0021 §3,
 * as StoreDocument, DecideDocument and DocumentVault::delete), the row is re-read and switched to the new files only
 * when it still points at the files that were read; a document replaced, deleted or moved meanwhile keeps what it has
 * and the new files are deleted. The old files are deleted after the commit. A run killed in between is finished by
 * settle() at the start of the next run, which keeps the file the row points at and deletes the other under the row
 * lock.
 *
 * A storage or database error on one document or record is logged with the document id and the exception class only
 * (storage messages carry the path, spec §5), counted as failed, and retried on the next run; the run carries on.
 */
class StoredImageReencoder
{
    /** The cache lock a real run holds, so two runs never overlap. */
    public const RUN_LOCK = 'kyc:reencode-images';

    /** Image files written but not yet switched to (or not yet cleaned up). */
    public const REWRITES = 'kyc_image_rewrites';

    /** The image columns per ImageSanitiser field: path, MIME type. */
    private const SIDES = ['front' => ['front_path', 'front_mime'], 'back' => ['back_path', 'back_mime']];

    public function __construct(private ImageSanitiser $sanitiser) {}

    /**
     * Documents still to re-encode, in id order (kyc_documents_unsanitised_index). Flagged ones only with $retryFlagged.
     *
     * @return Builder<KycDocument>
     */
    public function remaining(bool $retryFlagged = false): Builder
    {
        return KycDocument::query()->whereNull('sanitised_at')
            ->when(! $retryFlagged, fn (Builder $query) => $query->whereNull('sanitise_issue'))
            ->orderBy('id');
    }

    /**
     * Documents flagged because their images could not be re-encoded, in id order.
     *
     * @return Builder<KycDocument>
     */
    public function flagged(): Builder
    {
        return KycDocument::query()->whereNull('sanitised_at')->whereNotNull('sanitise_issue')->orderBy('id');
    }

    /**
     * Finish the rewrites a killed run left: under the document's lock, delete whichever of the two files the row does
     * not point at (both when the row is gone), then the record after the commit. The deletes stay inside the lock: a
     * run still alive after RUN_LOCK expired (6 h) may switch the row to its new file, and switch() checks that file
     * exists under the same lock, so it either switches before this reads the row (the file is kept) or finds it gone
     * (it keeps the old one). A record that fails (a storage error) is logged by document id, kept for the next run, and
     * skipped.
     *
     * @return array{settled: int, failed: int}
     */
    public function settle(): array
    {
        $counts = ['settled' => 0, 'failed' => 0];

        foreach (DB::table(self::REWRITES)->orderBy('id')->get() as $rewrite) {
            try {
                DB::transaction(fn () => $this->delete(array_values(array_diff(
                    [(string) $rewrite->old_path, (string) $rewrite->new_path],
                    $this->lockDocument((int) $rewrite->document_id)?->paths() ?? [],
                ))));

                DB::table(self::REWRITES)->where('id', $rewrite->id)->delete();
                $counts['settled']++;
            } catch (Throwable $exception) {
                $this->report('A half-finished identity image rewrite could not be settled; the next run retries it.', (int) $rewrite->document_id, $exception);
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * Re-encode one document's images. Returns the outcome
     * and the number of images switched:
     * - reencoded: every image is re-encoded and the row points at the new files (sanitised_at set);
     * - flagged: an image could not be read or re-encoded and was left as stored (sanitise_issue names why); the other
     *   image was re-encoded (with --retry-flagged it is encoded once more, at the same quality steps);
     * - changed: the row was deleted, given other files or marked done during the run, so nothing was switched;
     * - busy: an upload held the decode lock for kyc.reencode_decode_wait_seconds; nothing was switched;
     * - failed: a storage or database error; logged by document id, its rewrite records are left for settle().
     *
     * Must run outside a transaction: the decode lock lives in the cache store, and on PostgreSQL a contended database
     * lock inside a transaction aborts it (StoreDocument has the same rule).
     *
     * @return array{outcome: 'reencoded'|'flagged'|'changed'|'busy'|'failed', images: int}
     */
    public function reencode(KycDocument $document): array
    {
        try {
            return $this->reencodeSides($document);
        } catch (Throwable $exception) {
            $this->report('An identity document could not be re-encoded; the next run retries it.', $document->id, $exception);

            return ['outcome' => 'failed', 'images' => 0];
        }
    }

    /**
     * @return array{outcome: 'reencoded'|'flagged'|'changed'|'busy', images: int}
     */
    private function reencodeSides(KycDocument $document): array
    {
        $disk = Storage::disk(KycDocument::DISK);
        /** @var array<string, array{old: string, new: string, mime_column: string, mime: string}> $written */
        $written = [];
        /** @var list<int> $records the kyc_image_rewrites rows this call inserted */
        $records = [];
        $read = [];
        $issue = null;

        try {
            foreach (self::SIDES as $field => [$pathColumn, $mimeColumn]) {
                $old = $document->{$pathColumn};
                $read[$pathColumn] = $old;

                if (! is_string($old) || $old === '') {
                    continue;
                }

                $image = $this->clean($old, $field);

                if (is_string($image)) {
                    $issue ??= $image;

                    continue;
                }

                $new = (dirname($old) === '.' ? '' : dirname($old).'/').Str::uuid()->toString();
                $records[] = (int) DB::table(self::REWRITES)->insertGetId(['document_id' => $document->id, 'old_path' => $old, 'new_path' => $new, 'created_at' => now(), 'updated_at' => now()]);
                $disk->put($new, Crypt::encryptString($image['bytes']));
                $written[$pathColumn] = ['old' => $old, 'new' => $new, 'mime_column' => $mimeColumn, 'mime' => $image['mime']];
                unset($image);
            }
        } catch (ImageDecodeBusy) {
            $this->finish($records, array_column($written, 'new'));

            return ['outcome' => 'busy', 'images' => 0];
        }

        $switched = $this->switch($document->id, $read, $written, $issue);

        // After the commit: a crash before this line is settled from kyc_image_rewrites on the next run.
        $this->finish($records, $switched === null ? array_column($written, 'new') : array_column($written, 'old'));

        if ($switched === null) {
            return ['outcome' => 'changed', 'images' => 0];
        }

        return ['outcome' => $issue === null ? 'reencoded' : 'flagged', 'images' => count($written)];
    }

    /**
     * The re-encoded image, or why it is left as stored: unreadable (the file is missing or no key decrypts it) or
     * refused (GD cannot decode it, or it is over the limits). Other storage errors propagate to reencode(), which logs
     * them by document id only.
     *
     * @return array{bytes: string, mime: string}|'unreadable'|'refused'
     *
     * @throws ImageDecodeBusy
     */
    private function clean(string $path, string $field): array|string
    {
        $disk = Storage::disk(KycDocument::DISK);
        $stored = $disk->exists($path) ? $disk->get($path) : null;

        if ($stored === null) {
            return 'unreadable';
        }

        try {
            $bytes = Crypt::decryptString($stored);
        } catch (DecryptException) {
            return 'unreadable';
        } finally {
            unset($stored);
        }

        try {
            return $this->sanitiser->clean($bytes, $field);
        } catch (ImageDecodeBusy $busy) {
            throw $busy;
        } catch (ValidationException|RuntimeException) {
            return 'refused';
        }
    }

    /**
     * Switch the row to the new files under its lock, when it still points at the files that were read. Returns the
     * columns switched, or null when the row is gone or has other files now (nothing is written then).
     *
     * @param  array<string, string|null>  $read  path column => the path that was read
     * @param  array<string, array{old: string, new: string, mime_column: string, mime: string}>  $written
     * @return list<string>|null
     */
    protected function switch(int $documentId, array $read, array $written, ?string $issue): ?array
    {
        return DB::transaction(function () use ($documentId, $read, $written, $issue): ?array {
            $locked = $this->lockDocument($documentId);

            if ($locked === null || $locked->sanitised_at !== null) {
                return null;
            }

            foreach ($read as $column => $path) {
                if ($locked->{$column} !== $path) {
                    return null; // replaced by its owner, or moved by security:reencrypt
                }
            }

            $changes = $issue === null ? ['sanitised_at' => now(), 'sanitise_issue' => null] : ['sanitise_issue' => $issue];

            foreach ($written as $column => $file) {
                // The new file was removed meanwhile (an overlapping run's settle after the run lock expired): keep the old
                // one. This and settle() call the object store under the row locks: one request each, and the check must
                // see the same state the switch writes.
                if (! Storage::disk(KycDocument::DISK)->exists($file['new'])) {
                    return null;
                }

                $changes[$column] = $file['new'];
                $changes[$file['mime_column']] = $file['mime'];
            }

            // The query builder, so updated_at does not move and no model event fires.
            DB::table('kyc_documents')->where('id', $documentId)->update($changes);

            return array_keys($written);
        });
    }

    /**
     * Delete the files no row needs any more, then the rewrite records this call inserted. Only those: an older record
     * of the same document (one settle() could not finish this run) is kept, so its file is still deleted next run.
     *
     * @param  list<int>  $records
     * @param  list<string>  $paths
     */
    private function finish(array $records, array $paths): void
    {
        $this->delete($paths);

        if ($records !== []) {
            DB::table(self::REWRITES)->whereIn('id', $records)->delete();
        }
    }

    /**
     * Delete files, failing loudly also on a disk configured not to throw, so a rewrite record is kept until they are gone.
     *
     * @param  list<string>  $paths
     */
    private function delete(array $paths): void
    {
        foreach ($paths as $path) {
            if (! Storage::disk(KycDocument::DISK)->delete($path)) {
                throw new RuntimeException('An identity image file could not be deleted.');
            }
        }
    }

    /**
     * Only the id and the exception class: storage messages carry the path (spec §5), as in DocumentVault.
     */
    private function report(string $message, int $documentId, Throwable $exception): void
    {
        Log::warning($message, ['document_id' => $documentId, 'error' => $exception::class]);
    }

    /**
     * The document's row under lock, its client's row locked first (ADR 0021 §3); null when the document is gone.
     */
    private function lockDocument(int $documentId): ?KycDocument
    {
        $clientId = KycDocument::query()->whereKey($documentId)->value('client_id');

        if ($clientId !== null) {
            Client::query()->whereKey($clientId)->lockForUpdate()->first(['id']);
        }

        return KycDocument::query()->whereKey($documentId)->lockForUpdate()->first();
    }
}
