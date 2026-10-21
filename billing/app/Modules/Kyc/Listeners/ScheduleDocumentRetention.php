<?php

namespace App\Modules\Kyc\Listeners;

use App\Events\Core\ClientClosed;
use App\Events\Core\ClientMerged;
use App\Events\Core\ClientReopened;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\BusinessCalendar;
use App\Modules\Kyc\Models\KycDocument;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Sets or clears the deletion date of a client's current documents from the client's state when the job runs, not
 * from the event, so a close and a quick reopen end in the right state whatever order the jobs run in. A closed
 * client's documents are deleted retention_days after the local closing date; a reopened client keeps them.
 */
class ScheduleDocumentRetention implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    public function __construct(private BusinessCalendar $calendar) {}

    public function handle(ClientClosed|ClientReopened|ClientMerged $event): void
    {
        $client = Client::query()->find($event instanceof ClientMerged ? $event->sourceClientId : $event->clientId);

        if ($client === null) {
            return;
        }

        $deleteAfter = $client->status === 'closed' && $client->closed_at !== null
            ? CarbonImmutable::parse((string) $this->calendar->localDate($client->closed_at))->addDays((int) config('kyc.retention_days', 90))->toDateString()
            : null;

        KycDocument::query()->where('client_id', $client->id)->current()->update(['delete_after' => $deleteAfter]);
    }
}
