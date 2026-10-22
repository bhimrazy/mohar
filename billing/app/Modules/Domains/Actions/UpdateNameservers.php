<?php

namespace App\Modules\Domains\Actions;

use App\Contracts\Domains\Registrar\ManagesNameservers;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Events\Domains\DomainNameserversUpdated;
use App\Modules\Core\Support\ActivityLogger;
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
            $this->gateway->forDomain($domain, 'nameservers', ManagesNameservers::class, fn (ManagesNameservers $registrar, RegistrarConfig $config): RegistrarResult => $registrar->setNameservers($config, $domain->toRef(), $nameservers), ['nameservers' => $nameservers]);
        } else {
            $this->activity->log("Staff task: set nameservers of {$domain->name} at the registrar", $domain, $domain->client_id);
        }

        $domain->forceFill(['nameservers' => $nameservers])->save();
        $this->activity->log('Domain nameservers updated', $domain, $domain->client_id, ['nameservers' => $nameservers]);
        DomainEvents::dispatch(new DomainNameserversUpdated($domain->id, $domain->client_id));

        return $domain;
    }
}
