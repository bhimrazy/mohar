<?php

namespace App\Modules\Kyc\Actions;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A staff decision on one document. Staff decide on what the page showed them: the form carries version(), and the
 * decision re-reads the row under lock and refuses a document that was replaced, decided or changed since.
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
        return DB::transaction(function () use ($documentId, $version, $approve, $reason, $staff): KycDocument {
            $document = KycDocument::query()->lockForUpdate()->findOrFail($documentId);

            if ($document->superseded_at !== null || $document->status !== DocumentStatus::Pending || ! hash_equals(self::version($document), $version)) {
                throw ValidationException::withMessages(['document' => __('This document changed after you opened the page. Reload it and check the current version.')]);
            }

            $document->forceFill([
                'status' => $approve ? DocumentStatus::Approved : DocumentStatus::Rejected,
                'rejection_reason' => $approve ? null : $reason,
                'decided_by' => $staff->id,
                'decided_at' => now(),
            ])->save();

            $this->activity->log($approve ? 'Identity document approved' : 'Identity document rejected', $document, $document->client_id,
                ['type' => $document->type->value, ...$approve ? [] : ['reason' => $reason]], $approve ? 'kyc.document.approved' : 'kyc.document.rejected');

            return $document;
        });
    }
}
