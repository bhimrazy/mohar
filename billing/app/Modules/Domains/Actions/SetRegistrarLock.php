<?php

namespace App\Modules\Domains\Actions;

use App\Contracts\Domains\Registrar\ManagesLock;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Modules\Core\Support\ActivityLogger;
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
        $this->gateway->forDomain($domain, 'lock', ManagesLock::class, fn (ManagesLock $registrar, RegistrarConfig $config): RegistrarResult => $registrar->setLock($config, $domain->toRef(), $locked), ['locked' => $locked]);

        $domain->forceFill(['registrar_lock' => $locked])->save();
        $this->activity->log($locked ? 'Domain locked' : 'Domain unlocked', $domain, $domain->client_id);

        return $domain;
    }
}
