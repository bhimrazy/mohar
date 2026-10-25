<?php

namespace App\Modules\Kyc\Services;

use App\Contracts\Core\CompanyClock;
use App\Modules\Core\Models\Client;
use Carbon\CarbonImmutable;

/**
 * When a client's documents are deleted (spec §6): retention_days after the local date the account was closed;
 * null while the account is open.
 */
class RetentionDate
{
    public function __construct(private CompanyClock $calendar) {}

    public function deleteAfter(Client $client): ?string
    {
        if ($client->status !== 'closed' || $client->closed_at === null) {
            return null;
        }

        return CarbonImmutable::parse($this->calendar->localDate($client->closed_at))->addDays((int) config('kyc.retention_days', 90))->toDateString();
    }
}
