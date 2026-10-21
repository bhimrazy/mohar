<?php

namespace App\Modules\Kyc\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\BusinessCalendar;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\DocumentVault;
use App\Modules\Kyc\Services\RetentionDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Store a client's identity document, replacing the current one of its type unless staff approved it. The files are
 * written first; the row change runs under the client's row lock; the replaced document's files are deleted after
 * the commit (if that fails it stays superseded and the retention task deletes it).
 */
class StoreDocument
{
    public function __construct(private DocumentVault $vault, private ActivityLogger $activity, private BusinessCalendar $calendar, private RetentionDate $retention) {}

    /**
     * @throws ValidationException
     */
    public function handle(int $clientId, DocumentType $type, string $number, UploadedFile $front, ?UploadedFile $back, ?int $userId): KycDocument
    {
        $stored = [$this->vault->put($clientId, $front)];

        if ($back !== null) {
            $stored[] = $this->vault->put($clientId, $back);
        }

        try {
            [$document, $replaced] = DB::transaction(function () use ($clientId, $type, $number, $stored, $userId): array {
                $client = Client::query()->lockForUpdate()->findOrFail($clientId);
                // Lock order (ADR 0021): the client, then its documents. DecideDocument takes the same order.
                $replaced = KycDocument::query()->where('client_id', $client->id)->current()->where('type', $type->value)->lockForUpdate()->first();

                if ($replaced?->status === DocumentStatus::Approved) {
                    throw ValidationException::withMessages(['type' => __('This document is already approved, so it cannot be replaced.')]);
                }

                $replaced?->forceFill(['superseded_at' => now(), 'delete_after' => $this->calendar->today()->toDateString()])->save();

                $document = KycDocument::query()->create([
                    'client_id' => $client->id,
                    'type' => $type,
                    'number' => $number,
                    'front_path' => $stored[0]['path'],
                    'front_mime' => $stored[0]['mime'],
                    'back_path' => $stored[1]['path'] ?? null,
                    'back_mime' => $stored[1]['mime'] ?? null,
                    'status' => DocumentStatus::Pending,
                    'uploaded_by' => $userId,
                    // An account closed while this upload was on its way: the retention listener has already run.
                    'delete_after' => $client->status === 'closed' ? $this->retention->deleteAfter($client) : null,
                ]);

                $this->activity->log($replaced === null ? 'Identity document uploaded' : 'Identity document replaced', $document, $client->id, ['type' => $type->value], 'kyc.document.uploaded');

                return [$document, $replaced];
            });
        } catch (Throwable $exception) {
            $this->vault->discard(array_column($stored, 'path'));

            throw $exception;
        }

        if ($replaced !== null) {
            $this->vault->delete($replaced);
        }

        return $document;
    }
}
