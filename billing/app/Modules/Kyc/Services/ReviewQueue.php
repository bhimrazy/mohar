<?php

namespace App\Modules\Kyc\Services;

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Enums\DocumentStatus;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The staff queue's filters and summary counts, in SQL over current documents (kyc_documents_queue_index and
 * kyc_documents_client_current_index). "Verified" here means two approved document types; the row status shows any
 * step the client still has to do.
 */
class ReviewQueue
{
    public const FILTERS = ['pending_review', 'declined', 'verified', 'not_started', 'all'];

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'pending_review' => $this->current(DocumentStatus::Pending)->distinct()->count('client_id'),
            'declined' => $this->current(DocumentStatus::Declined)->distinct()->count('client_id'),
            'verified' => DB::query()->fromSub($this->approvedTwice(), 'verified')->count(),
            'not_started' => $this->clients('not_started')->count(),
        ];
    }

    /**
     * Clients matching a filter (merged accounts are left out).
     *
     * @return Builder<Client>
     */
    public function clients(string $filter): Builder
    {
        $query = Client::query()->whereNull('merged_into_client_id');

        return match ($filter) {
            'pending_review' => $query->whereIn('id', $this->current(DocumentStatus::Pending)->select('client_id')),
            'declined' => $query->whereIn('id', $this->current(DocumentStatus::Declined)->select('client_id')),
            'verified' => $query->whereIn('id', $this->approvedTwice()),
            'not_started' => $query->where('status', '!=', 'closed')
                ->whereNotExists(fn (QueryBuilder $sub) => $sub->from('kyc_documents')->whereColumn('kyc_documents.client_id', 'clients.id')->whereNull('kyc_documents.superseded_at')),
            default => $query,
        };
    }

    /**
     * @return Builder<KycDocument>
     */
    private function current(DocumentStatus $status): Builder
    {
        return KycDocument::query()->current()->where('status', $status->value);
    }

    /**
     * @return Builder<KycDocument>
     */
    private function approvedTwice(): Builder
    {
        return $this->current(DocumentStatus::Approved)->select('client_id')->groupBy('client_id')
            ->havingRaw('count(distinct type) >= ?', [KycVerification::REQUIRED_TYPES]);
    }
}
