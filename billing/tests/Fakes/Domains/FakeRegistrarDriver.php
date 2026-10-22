<?php

namespace Tests\Fakes\Domains;

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Data\Domains\Registrar\AvailabilityResult;
use App\Data\Domains\Registrar\ContactSet;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\EppCodeResult;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Domains\Registrar\SubmissionLookup;
use App\Data\Domains\Registrar\SyncResult;
use App\Data\Domains\Registrar\TransferSyncResult;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Enums\ReconcileStatus;
use Carbon\CarbonImmutable;

/**
 * Every registrar method, answered by the FakeRegistrar script. It implements only the core interface; the
 * fixture classes in tests/Fakes/Domains/Registrars add the capability interfaces.
 */
class FakeRegistrarDriver implements RegistrarDriver
{
    public function __construct(private FakeRegistrar $script, private string $key) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return 'Fake';
    }

    public function settingsFields(): array
    {
        return $this->script->settingsFields !== [] ? $this->script->settingsFields : [new SettingField('api_key', 'API key', FieldType::Secret)];
    }

    /**
     * @param  list<string>  $tlds
     * @return list<AvailabilityResult>
     */
    public function checkAvailability(RegistrarConfig $config, string $sld, array $tlds): array
    {
        return $this->script->record('availability', $sld, array_map(fn (string $tld): AvailabilityResult => new AvailabilityResult($sld.$tld, 'available'), $tlds));
    }

    /**
     * @param  list<string>  $tlds
     * @return list<AvailabilityResult>
     */
    public function suggest(RegistrarConfig $config, string $term, array $tlds, int $limit): array
    {
        return $this->script->record('suggestions', $term, []);
    }

    public function register(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        return $this->script->record('register', $request->domain->name, new RegistrarResult('completed', today()->toImmutable()->addYears($request->years)));
    }

    public function transfer(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
    {
        return $this->script->record('transfer', $request->domain->name, new RegistrarResult('pending'));
    }

    public function renew(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        return $this->script->record('renew', $domain->name, new RegistrarResult('completed', $domain->expiryDate?->addYears($years)));
    }

    public function restore(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
    {
        return $this->script->record('restore', $domain->name, new RegistrarResult('completed', $domain->expiryDate?->addYears($years)));
    }

    public function info(RegistrarConfig $config, DomainRef $domain): DomainInfo
    {
        return $this->script->record('info', $domain->name, new DomainInfo('not_found'));
    }

    /**
     * @param  list<string>  $nameservers
     */
    public function setNameservers(RegistrarConfig $config, DomainRef $domain, array $nameservers): RegistrarResult
    {
        return $this->script->record('nameservers', $domain->name, new RegistrarResult);
    }

    public function setLock(RegistrarConfig $config, DomainRef $domain, bool $locked): RegistrarResult
    {
        return $this->script->record('lock', $domain->name, new RegistrarResult);
    }

    public function setIdProtection(RegistrarConfig $config, DomainRef $domain, bool $enabled): RegistrarResult
    {
        return $this->script->record('id_protection', $domain->name, new RegistrarResult);
    }

    public function getContacts(RegistrarConfig $config, DomainRef $domain): ContactSet
    {
        return $this->script->record('get_contacts', $domain->name, new ContactSet);
    }

    public function setContacts(RegistrarConfig $config, DomainRef $domain, ContactSet $contacts): RegistrarResult
    {
        return $this->script->record('contacts', $domain->name, new RegistrarResult);
    }

    public function getEppCode(RegistrarConfig $config, DomainRef $domain): EppCodeResult
    {
        return $this->script->record('epp', $domain->name, new EppCodeResult('FAKE-EPP-CODE'));
    }

    public function sync(RegistrarConfig $config, DomainRef $domain): SyncResult
    {
        return $this->script->record('sync', $domain->name, new SyncResult(true, expiryDate: $domain->expiryDate));
    }

    public function transferSync(RegistrarConfig $config, DomainRef $domain): TransferSyncResult
    {
        return $this->script->record('transfer_sync', $domain->name, new TransferSyncResult(false));
    }

    public function findSubmission(RegistrarConfig $config, DomainRef $domain, string $command, CarbonImmutable $submittedAt): SubmissionLookup
    {
        return $this->script->record('reconcile', $domain->name, new SubmissionLookup(ReconcileStatus::Unknown));
    }

    public function testConnection(RegistrarConfig $config): ConnectionCheck
    {
        return $this->script->record('test_connection', null, new ConnectionCheck(true, 'Fake connected.'));
    }
}
