<?php

namespace App\Modules\Domains\Actions;

use App\Events\Domains\DomainEppCodeRetrieved;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Drivers\Registrars\Data\EppCodeResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\RegistrarGateway;

/**
 * Fetch the EPP (transfer) code for an outbound transfer (spec §4.7). Every retrieval is logged with
 * the actor; the code itself is never stored or logged.
 */
class RetrieveEppCode
{
    public function __construct(private RegistrarGateway $gateway, private ActivityLogger $activity) {}

    /**
     * @param  'client'|'staff'  $actor
     */
    public function handle(Domain $domain, string $actor, ?User $user): EppCodeResult
    {
        $result = $this->gateway->forDomain($domain, 'epp', fn (Registrar $registrar): EppCodeResult => $registrar->getEppCode($domain));

        $this->activity->log("EPP code retrieved by {$actor}", $domain, $domain->client_id, [
            'actor' => $actor,
            'user_id' => $user?->id,
            'domain' => $domain->name,
            'emailed' => $result->emailed,
        ]);
        DomainEvents::dispatch(new DomainEppCodeRetrieved($domain->id, $domain->client_id, $actor, $user?->id, $result->emailed));

        return $result;
    }
}
