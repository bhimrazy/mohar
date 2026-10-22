<?php

namespace App\Contracts\Domains\Registrar;

use App\Contracts\Integrations\IntegrationDriver;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Integrations\SettingField;
use App\Support\Domains\RegistrarException;
use App\Support\Domains\RegistrarTimeout;

/**
 * Core contract of a registrar driver (ADR 0009 §3). Provider contract (ADR 0010): never add a method here; add a
 * capability interface in this namespace instead (ADR 0012). Register a driver with RegistrarDriverRegistry::register().
 *
 * Drivers are stateless: every call receives the account as a RegistrarConfig and the domain as a DomainRef
 * snapshot. A driver never reads or writes the host's tables, and its constructor does no HTTP, queries or
 * decryption. The host (Domains' RegistrarGateway) checks capabilities, writes the command log and redacts secrets;
 * drivers never call the Redactor themselves and never copy raw request parameters into messages.
 *
 * Every call maps every answer to exactly one outcome (registrars.md §0):
 * - completed: RegistrarResult('completed');
 * - pending: accepted, finishes asynchronously, RegistrarResult('pending'); sync resolves it;
 * - rejected: nothing executed, do not retry, RegistrarException;
 * - retryable: provably not executed (DNS failure, connection refused), RegistrarException(retryable: true);
 * - unknown: may have executed (read timeout after sending, unparseable answer), RegistrarTimeout. The host never
 *   retries it automatically; staff verify or ReconcilesSubmissions settles it. Any other exception from a mutating
 *   call is treated the same way.
 */
interface RegistrarDriver extends IntegrationDriver
{
    /**
     * The account settings staff configure. Credentials are Secret fields with no default; keep test and live
     * credentials in separate fields so a test-mode flip never sends live keys to a sandbox, or the reverse.
     *
     * @return list<SettingField>
     */
    public function settingsFields(): array;

    /**
     * @throws RegistrarException
     * @throws RegistrarTimeout
     */
    public function register(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult;

    /**
     * @throws RegistrarException
     * @throws RegistrarTimeout
     */
    public function renew(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult;

    /**
     * Read-only: the registrar's view of the domain (status `not_found` when it is not in our account).
     *
     * @throws RegistrarException
     */
    public function info(RegistrarConfig $config, DomainRef $domain): DomainInfo;
}
