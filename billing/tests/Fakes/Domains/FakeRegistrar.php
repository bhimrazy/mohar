<?php

namespace Tests\Fakes\Domains;

use App\Modules\Domains\Drivers\Registrars\Data\AvailabilityResult;
use App\Modules\Domains\Drivers\Registrars\Data\ContactSet;
use App\Modules\Domains\Drivers\Registrars\Data\DomainInfo;
use App\Modules\Domains\Drivers\Registrars\Data\EppCodeResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrarResult;
use App\Modules\Domains\Drivers\Registrars\Data\RegistrationRequest;
use App\Modules\Domains\Drivers\Registrars\Data\SyncResult;
use App\Modules\Domains\Drivers\Registrars\Data\TransferSyncResult;
use App\Modules\Domains\Drivers\Registrars\Registrar;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Models\Domain;
use Throwable;

/**
 * Scriptable registrar driver: set the next result (or exception) per command and inspect calls.
 */
class FakeRegistrar implements Registrar
{
    /** @var list<string> */
    public array $capabilities = Registrar::CAPABILITIES;

    /** @var list<array{command: string, domain: string|null}> */
    public array $calls = [];

    /** @var array<string, mixed> command => result object or Throwable */
    public array $results = [];

    public static function install(string $key = 'fake'): self
    {
        $fake = new self;
        app(RegistrarManager::class)->extend($key, fn (): Registrar => $fake);

        return $fake;
    }

    public function will(string $command, mixed $result): self
    {
        $this->results[$command] = $result;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return array_column($this->calls, 'command');
    }

    public function name(): string
    {
        return 'Fake';
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function settingsSchema(): array
    {
        return [['key' => 'api_key', 'label' => 'API key', 'type' => 'password']];
    }

    public function configure(array $settings, bool $testMode): void {}

    public function checkAvailability(string $sld, array $tlds): array
    {
        $this->calls[] = ['command' => 'availability', 'domain' => $sld];

        return $this->resolve('availability', array_map(fn (string $tld): AvailabilityResult => new AvailabilityResult($sld.$tld, 'available'), $tlds));
    }

    public function suggest(string $term, array $tlds, int $limit): array
    {
        $this->calls[] = ['command' => 'suggestions', 'domain' => $term];

        return $this->resolve('suggestions', []);
    }

    public function register(RegistrationRequest $request): RegistrarResult
    {
        return $this->record('register', $request->domain, new RegistrarResult('completed', today()->toImmutable()->addYears($request->years)));
    }

    public function transfer(RegistrationRequest $request): RegistrarResult
    {
        return $this->record('transfer', $request->domain, new RegistrarResult('pending'));
    }

    public function renew(Domain $domain, int $years, bool $restore = false): RegistrarResult
    {
        return $this->record($restore ? 'restore' : 'renew', $domain, new RegistrarResult('completed', $domain->expiry_date?->addYears($years)));
    }

    public function info(Domain $domain): DomainInfo
    {
        return $this->record('info', $domain, new DomainInfo('not_found'));
    }

    public function setNameservers(Domain $domain, array $nameservers): RegistrarResult
    {
        return $this->record('nameservers', $domain, new RegistrarResult);
    }

    public function setLock(Domain $domain, bool $locked): RegistrarResult
    {
        return $this->record('lock', $domain, new RegistrarResult);
    }

    public function setIdProtection(Domain $domain, bool $enabled): RegistrarResult
    {
        return $this->record('id_protection', $domain, new RegistrarResult);
    }

    public function getContacts(Domain $domain): ContactSet
    {
        return $this->record('get_contacts', $domain, new ContactSet);
    }

    public function setContacts(Domain $domain, ContactSet $contacts): RegistrarResult
    {
        return $this->record('contacts', $domain, new RegistrarResult);
    }

    public function getEppCode(Domain $domain): EppCodeResult
    {
        return $this->record('epp', $domain, new EppCodeResult('FAKE-EPP-CODE'));
    }

    public function sync(Domain $domain): SyncResult
    {
        return $this->record('sync', $domain, new SyncResult(true, expiryDate: $domain->expiry_date));
    }

    public function transferSync(Domain $domain): TransferSyncResult
    {
        return $this->record('transfer_sync', $domain, new TransferSyncResult(false));
    }

    public function testConnection(): array
    {
        return ['ok' => true, 'message' => 'Fake connected.'];
    }

    /**
     * @template T
     *
     * @param  T  $default
     * @return T
     */
    private function record(string $command, Domain $domain, mixed $default): mixed
    {
        $this->calls[] = ['command' => $command, 'domain' => $domain->name];

        return $this->resolve($command, $default);
    }

    /**
     * @template T
     *
     * @param  T  $default
     * @return T
     */
    private function resolve(string $command, mixed $default): mixed
    {
        $result = $this->results[$command] ?? $default;

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}
