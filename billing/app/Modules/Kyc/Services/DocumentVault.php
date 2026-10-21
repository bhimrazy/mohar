<?php

namespace App\Modules\Kyc\Services;

use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
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
                Log::warning('An identity image of a failed upload could not be deleted.', ['error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * Delete a document's files, then its row. Returns false (and keeps the row, so the retention task retries) when
     * the storage fails; one document's failure never stops the caller's loop.
     */
    public function delete(KycDocument $document): bool
    {
        try {
            foreach ($document->paths() as $path) {
                Storage::disk(KycDocument::DISK)->delete($path);
            }

            $document->delete();

            return true;
        } catch (Throwable $exception) {
            Log::warning('An identity document could not be deleted; the retention task retries it.', ['document_id' => $document->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }
}
