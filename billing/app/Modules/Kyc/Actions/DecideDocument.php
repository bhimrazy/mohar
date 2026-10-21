<?php

namespace App\Modules\Kyc\Actions;

use App\Events\Kyc\DocumentDecided;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A staff decision on one document. Staff decide on what the page showed them: the form carries version(), and the
 * decision re-reads the row under lock and refuses a document that was replaced, decided or changed since.
 *
 * Lock order (ADR 0021 §3): the client, then the document, as in StoreDocument; the audit row's foreign key to the
 * client then needs no lock the upload could hold.
 */
class DecideDocument
{
    public function __construct(private ActivityLogger $activity) {}

    /**
     * A digest of what staff saw: the row, its status and its files. Keyed with the app key, so it reveals nothing.
     */
    public static function version(KycDocument $document): string
    {
        return hash_hmac('sha256', implode('|', [$document->id, $document->status->value, $document->front_path, (string) $document->back_path, $document->superseded_at?->toIso8601String()]), (string) config('app.key'));
    }

    /**
     * @throws ValidationException
     */
    public function handle(int $documentId, string $version, bool $approve, ?string $reason, User $staff): KycDocument
    {
        $clientId = (int) KycDocument::query()->whereKey($documentId)->value('client_id');

        $document = DB::transaction(function () use ($clientId, $documentId, $version, $approve, $reason, $staff): KycDocument {
            Client::query()->lockForUpdate()->findOrFail($clientId);
            $document = KycDocument::query()->where('client_id', $clientId)->lockForUpdate()->findOrFail($documentId);

            if ($document->superseded_at !== null || $document->status !== DocumentStatus::Pending || ! hash_equals(self::version($document), $version)) {
                throw ValidationException::withMessages(['document' => __('This document changed after you opened the page. Reload it and check the current version.')]);
            }

            $document->forceFill([
                'status' => $approve ? DocumentStatus::Approved : DocumentStatus::Declined,
                'decline_reason' => $approve ? null : $reason,
                'decided_by' => $staff->id,
                'decided_at' => now(),
            ])->save();

            $this->activity->log($approve ? 'Identity document approved' : 'Identity document declined', $document, $document->client_id,
                ['type' => $document->type->value, ...$approve ? [] : ['reason' => $reason]], $approve ? 'kyc.document.approved' : 'kyc.document.declined');

            return $document;
        });

        DocumentDecided::dispatch($document->client_id, $document->id);

        return $document;
    }
}
