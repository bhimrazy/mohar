<?php

namespace App\Modules\Domains\Actions;

use App\Events\Domains\DomainNameserversUpdated;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\DomainEvents;
use App\Modules\Domains\Services\RegistrarGateway;
use App\Modules\Domains\Support\Nameservers;

/**
 * Change nameservers at the registrar, then cache them locally. Manual domains only update the
 * local record and leave a staff task.
 */
class UpdateNameservers
{
    public function __construct(private RegistrarGateway $gateway, private ActivityLogger $activity) {}

    /**
     * @param  list<string>  $nameservers  validated hostnames
     */
    public function handle(Domain $domain, array $nameservers): Domain
    {
        $nameservers = Nameservers::normalise($nameservers);

        if ($domain->registrar_key !== null) {
            $this->gateway->forDomain($domain, 'nameservers', fn (Registrar $registrar): RegistrarResult => $registrar->setNameservers($domain, $nameservers), ['nameservers' => $nameservers]);
        } else {
            $this->activity->log("Staff task: set nameservers of {$domain->name} at the registrar", $domain, $domain->client_id);
        }

        $domain->forceFill(['nameservers' => $nameservers])->save();
        $this->activity->log('Domain nameservers updated', $domain, $domain->client_id, ['nameservers' => $nameservers]);
        DomainEvents::dispatch(new DomainNameserversUpdated($domain->id, $domain->client_id));

        return $domain;
    }
}
