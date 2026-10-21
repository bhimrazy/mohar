<?php

namespace App\Modules\Kyc\Services;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Enums\KycStatus;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A client's verification steps and status (spec §3–4). Everything is derived from the client, its logins and its
 * current documents, so a later change (a new email address) undoes the step it affects.
 */
class KycVerification
{
    /** Different document types a client needs. */
    public const REQUIRED_TYPES = 2;

    public function __construct(private Settings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('kyc.enabled', false);
    }

    public function phoneCountryCode(): string
    {
        return (string) $this->settings->get('kyc.phone_country_code', '+977');
    }

    /**
     * The address is proved when staff or an import set it, or an owner login with that exact address verified it.
     */
    public function emailProved(Client $client): bool
    {
        if ($client->email_source->isVouched()) {
            return true;
        }

        return DB::table('client_user')->join('users', 'users.id', '=', 'client_user.user_id')
            ->where('client_user.client_id', $client->id)->where('client_user.is_owner', true)
            ->where('users.email', mb_strtolower($client->email))->whereNotNull('users.email_verified_at')
            ->exists();
    }

    public function phoneDone(Client $client): bool
    {
        return PhoneNumber::normalise($client->phone, $this->phoneCountryCode()) !== null;
    }

    /**
     * @return Collection<int, KycDocument>
     */
    public function documents(Client $client): Collection
    {
        return KycDocument::query()->where('client_id', $client->id)->current()->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, KycDocument>|null  $documents  the client's current documents, when already loaded
     */
    public function status(Client $client, ?Collection $documents = null): KycStatus
    {
        $documents ??= $this->documents($client);
        $types = fn (DocumentStatus ...$statuses): int => $documents->filter(fn (KycDocument $document): bool => in_array($document->status, $statuses, true))
            ->map(fn (KycDocument $document): DocumentType => $document->type)->unique()->count();

        return match (true) {
            $documents->isEmpty() => KycStatus::NotStarted,
            $documents->contains('status', DocumentStatus::Rejected) => KycStatus::Rejected,
            $types(DocumentStatus::Approved) >= self::REQUIRED_TYPES && $this->emailProved($client) && $this->phoneDone($client) => KycStatus::Verified,
            $types(DocumentStatus::Pending, DocumentStatus::Approved) >= self::REQUIRED_TYPES && $documents->contains('status', DocumentStatus::Pending) => KycStatus::PendingReview,
            default => KycStatus::InProgress,
        };
    }
}
