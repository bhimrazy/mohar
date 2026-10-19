<?php

namespace App\Contracts\Services\Provisioning;

use App\Data\Services\Provisioning\CommandLookup;
use App\Data\Services\Provisioning\ProvisioningContext;
use Carbon\CarbonImmutable;

/**
 * Capability: find out whether a command whose outcome was unknown ran on the server (ADR 0009 §4; the same shape as
 * the registrar ReconcilesSubmissions capability). The driver knows what each command leaves behind: after
 * `create` the account exists and is ours, after `terminate` it is gone, after `suspend` it is suspended. It may use
 * $context->service->driverData (remote ids) or the username and domain, whichever the remote system supports.
 *
 * Read-only; never creates or changes anything. The host keeps the policy: it never re-runs a command on its own,
 * and offers "run again" only after NotExecuted.
 */
interface ReconcilesCommands
{
    /**
     * @param  string  $command  the module command (`create`, `suspend`, `unsuspend`, `terminate`, `change_package`,
     *                           `change_password`, `renew`)
     * @param  CarbonImmutable  $attemptedAt  when the attempt with the unknown outcome was sent
     */
    public function reconcile(ProvisioningContext $context, string $command, CarbonImmutable $attemptedAt): CommandLookup;
}
