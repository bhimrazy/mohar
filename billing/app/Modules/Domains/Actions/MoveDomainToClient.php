<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Models\Domain;
use Illuminate\Support\Facades\DB;

/**
 * Move a domain to another client (spec §4.7). Existing invoices stay with the previous owner; the next
 * renewal invoice is issued to the new one.
 */
class MoveDomainToClient
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(Domain $domain, Client $client): Domain
    {
        if ($domain->client_id === $client->id) {
            return $domain;
        }

        return DB::transaction(function () use ($domain, $client): Domain {
            $previous = $domain->client_id;
            $domain->forceFill(['client_id' => $client->id])->save();

            $this->activity->log("Domain moved to client #{$client->id}", $domain, $previous, ['from_client_id' => $previous, 'to_client_id' => $client->id]);
            $this->activity->log("Domain moved from client #{$previous}", $domain, $client->id, ['from_client_id' => $previous, 'to_client_id' => $client->id]);

            return $domain;
        });
    }
}
