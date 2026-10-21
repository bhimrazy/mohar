<?php

namespace App\Modules\Kyc\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\DocumentVault;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily retention (spec §6): delete documents whose delete_after date has come (replaced documents, and documents of
 * clients closed 90 days ago). Reads kyc_documents_delete_after_index, at most kyc.prune_batch documents per run;
 * each document is deleted on its own and a failure is retried the next run. Idempotent.
 */
class PruneDocuments
{
    public function __construct(private DocumentVault $vault) {}

    public function handle(CarbonImmutable $today): int
    {
        $deleted = 0;

        // A current document is deleted only while its client is still closed: a reopen whose listener failed keeps them.
        $documents = KycDocument::query()->whereNotNull('delete_after')->where('delete_after', '<=', $today->toDateString())
            ->where(fn (Builder $query) => $query->whereNotNull('superseded_at')
                ->orWhereIn('client_id', Client::query()->where('status', 'closed')->select('id')))
            ->orderBy('delete_after')->orderBy('id')->limit(max(1, (int) config('kyc.prune_batch', 500)))->get();

        foreach ($documents as $document) {
            $deleted += $this->vault->delete($document) ? 1 : 0;
        }

        return $deleted;
    }
}
