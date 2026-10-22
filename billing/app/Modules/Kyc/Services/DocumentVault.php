<?php

namespace App\Modules\Kyc\Services;

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Encrypted storage of identity images on the private objects disk. Paths are random and never leave the server;
 * the bytes are encrypted with the application key before they are written.
 */
class DocumentVault
{
    /**
     * Encrypt and store an upload. Returns the path and the MIME type read from the file's content.
     *
     * @return array{path: string, mime: string}
     */
    public function put(int $clientId, UploadedFile $file): array
    {
        $path = 'kyc/'.$clientId.'/'.Str::uuid()->toString();
        Storage::disk(KycDocument::DISK)->put($path, Crypt::encryptString((string) $file->get()));

        return ['path' => $path, 'mime' => (string) $file->getMimeType()];
    }

    /**
     * Delete stored files that no row refers to (a failed upload). Never throws.
     *
     * @param  list<string>  $paths
     */
    public function discard(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk(KycDocument::DISK)->delete($path);
            } catch (Throwable $exception) {
                Log::warning('An identity image of a failed upload could not be deleted.', ['error' => $exception::class]);
            }
        }
    }

    /**
     * Delete a document's files, then its row. Returns false (and keeps the row, so the retention task retries) when
     * the storage fails; one document's failure never stops the caller's loop. The row is re-read under lock (client,
     * then document, ADR 0021 §3), because `security:reencrypt` may have moved it to new files since $document was
     * loaded.
     */
    public function delete(KycDocument $document): bool
    {
        try {
            DB::transaction(function () use ($document): void {
                Client::query()->whereKey($document->client_id)->lockForUpdate()->first(['id']);
                $locked = KycDocument::query()->whereKey($document->id)->lockForUpdate()->first();

                foreach ($locked?->paths() ?? [] as $path) {
                    Storage::disk(KycDocument::DISK)->delete($path);
                }

                $locked?->delete();
            });

            return true;
        } catch (Throwable $exception) {
            // Only the id and the exception class: storage messages carry the path (spec §5).
            Log::warning('An identity document could not be deleted; the retention task retries it.', ['document_id' => $document->id, 'error' => $exception::class]);

            return false;
        }
    }
}
