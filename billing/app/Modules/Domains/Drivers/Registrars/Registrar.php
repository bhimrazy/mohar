<?php

namespace App\Modules\Domains\Drivers\Registrars;

use App\Modules\Domains\Drivers\Registrars\Data\AvailabilityResult;
use App\Modules\Domains\Drivers\Registrars\Data\ContactSet;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\EppCodeResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrationRequest;
use App\Modules\Domains\Drivers\Registrars\Data\SubmissionLookup;
use App\Modules\Domains\Drivers\Registrars\Data\SyncResult;
use App\Modules\Domains\Drivers\Registrars\Data\TransferSyncResult;
use App\Modules\Domains\Exceptions\CapabilityNotSupported;
use App\Modules\Domains\Exceptions\RegistrarException;
use App\Modules\Domains\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * Registrar driver contract (spec §5). Drivers are registered in RegistrarManager under a key and are
 * always called through RegistrarGateway, which checks capabilities and writes registrar_command_log.
 *
 * Methods outside capabilities() throw CapabilityNotSupported; transient failures throw
 * RegistrarException(retryable: true); unknown outcomes throw RegistrarTimeout.
 *
 * @throws RegistrarException
 * @throws CapabilityNotSupported
 */
interface Registrar
{
    public const CAPABILITIES = [
        'availability', 'suggestions', 'register', 'transfer', 'renew', 'restore', 'info', 'nameservers', 'lock',
        'epp', 'contacts', 'id_protection', 'sync', 'transfer_sync', 'reconcile',
    ];

    /**
     * Human name shown in the admin, e.g. "Log (demo)".
     */
    public function name(): string;

    /**
     * @return list<string> subset of self::CAPABILITIES
     */
    public function capabilities(): array;

    /**
     * Settings the admin can configure for this driver, in the array form SettingField::fromArray() reads
     * (`password` is a secret, `integer` a number).
     *
     * @return list<array{key: string, label: string, type: 'string'|'password'|'boolean'|'select'|'integer', options?: array<string, string>, help?: string, required?: bool, default?: string|int|bool|null}>
     */
    public function settingsSchema(): array;

    /**
     * @param  array<string, mixed>  $settings  decrypted registrar_accounts.settings
     */
    public function configure(array $settings, bool $testMode): void;

    /**
     * @param  list<string>  $tlds  extensions with leading dot
     * @return list<AvailabilityResult>
     */
    public function checkAvailability(string $sld, array $tlds): array;

    /**
     * @param  list<string>  $tlds
     * @return list<AvailabilityResult> available names only
     */
    public function suggest(string $term, array $tlds, int $limit): array;

    public function register(RegistrationRequest $request): RegistrarResult;

    public function transfer(RegistrationRequest $request): RegistrarResult;

    public function renew(Domain $domain, int $years, bool $restore = false): RegistrarResult;

    public function info(Domain $domain): DomainInfo;

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult;

    public function setLock(Domain $domain, bool $locked): RegistrarResult;

    public function setIdProtection(Domain $domain, bool $enabled): RegistrarResult;

    public function getContacts(Domain $domain): ContactSet;

    public function setContacts(Domain $domain, ContactSet $contacts): RegistrarResult;

    public function getEppCode(Domain $domain): EppCodeResult;

    public function sync(Domain $domain): SyncResult;

    public function transferSync(Domain $domain): TransferSyncResult;

    /**
     * Capability `reconcile`: look up, in our own registrar account, whether a mutating command whose outcome was
     * lost (timeout, killed worker) ran. A "name not available" answer is never proof either way: check ownership.
     *
     * @param  'register'|'transfer'|'renew'  $command
     * @param  CarbonImmutable  $submittedAt  when the lost attempt was claimed; older orders are not evidence
     */
    public function findSubmission(Domain $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup;

    /**
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array;
}
