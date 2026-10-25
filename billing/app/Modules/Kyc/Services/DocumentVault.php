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
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Encrypted storage of identity images on the private objects disk. Paths are random and never leave the server;
 * each upload is re-encoded (ImageSanitiser: metadata and appended bytes dropped) and then encrypted with the
 * application key before it is written.
 */
class DocumentVault
{
    public function __construct(private ImageSanitiser $sanitiser) {}

    /**
     * Re-encode, encrypt and store an upload. Returns the path and the MIME type of the re-encoded image.
     *
     * @return array{path: string, mime: string}
     *
     * @throws ValidationException on $field when the upload is not an image GD can decode, or is too large
     */
    public function put(int $clientId, UploadedFile $file, string $field): array
    {
        $image = $this->sanitiser->clean((string) $file->get(), $field);
        $path = 'kyc/'.$clientId.'/'.Str::uuid()->toString();
        Storage::disk(KycDocument::DISK)->put($path, Crypt::encryptString($image['bytes']));

        return ['path' => $path, 'mime' => $image['mime']];
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
