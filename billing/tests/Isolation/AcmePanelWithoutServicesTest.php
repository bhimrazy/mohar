<?php

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Contracts\Domains\Registrar\TransfersDomains;
use App\Contracts\Services\ProvisioningDrivers;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Domains\Registrar\TransferSyncResult;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Domains\RegistrarException;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelDriver;
use Tests\Fixtures\Extensions\AcmePanel\AcmePanelServiceProvider;
use Tests\Fixtures\Extensions\AcmePanel\AcmeRegistrarDriver;
use Tests\Support\Contracts\RegistrarDriverContract;
use Tests\TestCase;

beforeAll(fn () => TestCase::$onlyModules = 'Core');
afterAll(fn () => TestCase::$onlyModules = null);

beforeEach(fn () => $this->app->register(AcmePanelServiceProvider::class));

test('a package registers a provisioning driver when only Core is loaded', function () {
    $registry = app(ProvisioningDriverRegistry::class);

    expect(class_exists('App\Modules\Services\ServicesServiceProvider') && app()->providerIsLoaded('App\Modules\Services\ServicesServiceProvider'))->toBeFalse()
        ->and($registry->keys())->toBe(['acme_panel'])
        ->and($registry->get('acme_panel'))->toBeInstanceOf(AcmePanelDriver::class)
        ->and($registry->capabilitiesOf('acme_panel'))->toEqualCanonicalizing([ProvisioningDriverRegistry::CHANGE_PASSWORD, ProvisioningDriverRegistry::TEST_CONNECTION])
        ->and(app(ProvisioningDrivers::class)->all())->toBe([]);
});

test('a package registers a registrar driver when only Core is loaded', function () {
    $registry = app(RegistrarDriverRegistry::class);

    expect(app()->providerIsLoaded('App\Modules\Domains\DomainsServiceProvider'))->toBeFalse()
        ->and($registry->keys())->toBe(['acme_registrar'])
        ->and($registry->get('acme_registrar'))->toBeInstanceOf(AcmeRegistrarDriver::class)
        ->and($registry->capabilitiesOf('acme_registrar'))->toEqualCanonicalizing(['register', 'renew', 'info', 'availability', 'nameservers', 'test_connection']);
});

test('the registrar contract has calls for every capability interface the registry knows', function () {
    expect(array_keys(RegistrarDriverContract::calls()))
        ->toEqualCanonicalizing(array_values(array_unique(app(RegistrarDriverRegistry::class)->capabilities())));
});

test('the registrar contract fails a driver that echoes an account key or the transfer code', function (string $leak) {
    $driver = new class($leak) implements RegistrarDriver, TestsRegistrarConnection, TransfersDomains
    {
        public function __construct(private string $leak) {}

        public function key(): string
        {
            return 'leaky';
        }

        public function name(): string
        {
            return 'Leaky';
        }

        public function settingsFields(): array
        {
            return [new SettingField('api_key', 'API key', FieldType::Secret)];
        }

        public function register(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
        {
            return new RegistrarResult;
        }

        public function renew(RegistrarConfig $config, DomainRef $domain, int $years): RegistrarResult
        {
            return new RegistrarResult;
        }

        public function info(RegistrarConfig $config, DomainRef $domain): DomainInfo
        {
            return new DomainInfo('active');
        }

        public function transfer(RegistrarConfig $config, RegistrationRequest $request): RegistrarResult
        {
            return $this->leak === 'epp' ? throw new RegistrarException("Auth code {$request->eppCode} refused.") : new RegistrarResult('pending');
        }

        public function transferSync(RegistrarConfig $config, DomainRef $domain): TransferSyncResult
        {
            return new TransferSyncResult(false);
        }

        public function testConnection(RegistrarConfig $config): ConnectionCheck
        {
            return new ConnectionCheck($this->leak !== 'key', $this->leak === 'key' ? 'Key '.$config->setting('api_key').' refused.' : 'Connected.');
        }
    };

    expect(fn () => RegistrarDriverContract::assertEchoesNoSecret($driver))->toThrow(ExpectationFailedException::class);
})->with(['account key in the connection test' => 'key', 'transfer code in an error' => 'epp']);

RegistrarDriverContract::verify('acme_registrar', arrange: fn () => Http::fake([
    'sandbox.acme-registrar.test/v1/check*' => Http::response(['results' => ['.com' => 'free']]),
    'sandbox.acme-registrar.test/v1/domains/contract-test.com' => Http::response(['id' => 77, 'status' => 'active', 'expires' => '2028-01-31', 'nameservers' => ['ns1.contract.test']]),
    'sandbox.acme-registrar.test/v1/account' => Http::response(['balance' => '120.00']),
    'sandbox.acme-registrar.test/v1/*' => Http::response(['id' => 77, 'state' => 'done', 'expires' => '2028-01-31']),
]));
