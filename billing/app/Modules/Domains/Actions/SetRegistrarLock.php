<?php

namespace App\Modules\Domains\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Services\RegistrarGateway;

/**
 * Lock or unlock the domain at the registrar (throws CapabilityNotSupported when unsupported).
 */
class SetRegistrarLock
{
    public function __construct(private RegistrarGateway $gateway, private ActivityLogger $activity) {}

    public function handle(Domain $domain, bool $locked): Domain
    {
        $this->gateway->forDomain($domain, 'lock', fn (Registrar $registrar): RegistrarResult => $registrar->setLock($domain, $locked), ['locked' => $locked]);

        $domain->forceFill(['registrar_lock' => $locked])->save();
        $this->activity->log($locked ? 'Domain locked' : 'Domain unlocked', $domain, $domain->client_id);

        return $domain;
    }
}
