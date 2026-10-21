<?php

namespace App\Modules\Kyc\Listeners;

use App\Events\Core\ClientClosed;
use App\Events\Core\ClientMerged;
use App\Events\Core\ClientReopened;
use App\Modules\Core\Models\Client;
use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\RetentionDate;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * Sets or clears the deletion date of a client's current documents from the client's state, read under the client's
 * row lock (ADR 0021 order: client, then documents). Concurrent close and reopen jobs serialise on that lock, and the
 * last one reads the final state.
 */
class ScheduleDocumentRetention implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public function __construct(private RetentionDate $retention) {}

    public function handle(ClientClosed|ClientReopened|ClientMerged $event): void
    {
        $clientId = $event instanceof ClientMerged ? $event->sourceClientId : $event->clientId;

        DB::transaction(function () use ($clientId): void {
            $client = Client::query()->lockForUpdate()->find($clientId);

            if ($client !== null) {
                KycDocument::query()->where('client_id', $client->id)->current()->update(['delete_after' => $this->retention->deleteAfter($client)]);
            }
        });
    }
}
