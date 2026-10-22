<?php

namespace App\Contracts\Domains\Registrar;

use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\SubmissionLookup;
use Carbon\CarbonImmutable;

/**
 * Capability `reconcile` (registrars.md C2): find out, in our own registrar account, whether a mutating command
 * whose outcome was lost (timeout, killed worker) ran. The same shape as the provisioning ReconcilesCommands, with the
 * shared App\Enums\ReconcileStatus. A "name not available" answer is never proof either way: check ownership, and
 * report a name registered outside our account as Conflict.
 *
 * Read-only; never creates or changes anything. The host keeps the policy: it never re-submits on its own.
 */
interface ReconcilesSubmissions
{
    /**
     * @param  'register'|'transfer'|'renew'  $command
     * @param  CarbonImmutable  $submittedAt  when the lost attempt was claimed; older orders are not evidence
     */
    public function findSubmission(RegistrarConfig $config, DomainRef $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup;
}
