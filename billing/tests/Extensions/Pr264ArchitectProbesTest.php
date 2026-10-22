<?php

use App\Contracts\Domains\Registrar\RegistrarDriver;
use App\Contracts\Domains\Registrar\TestsRegistrarConnection;
use App\Data\Domains\Registrar\DomainInfo;
use App\Data\Domains\Registrar\DomainRef;
use App\Data\Domains\Registrar\RegistrarConfig;
use App\Data\Domains\Registrar\RegistrarResult;
use App\Data\Domains\Registrar\RegistrationRequest;
use App\Data\Integrations\ConnectionCheck;
use App\Data\Integrations\SettingField;
use App\Enums\FieldType;
use App\Modules\Domains\Models\RegistrarAccount;
use App\Support\Domains\RegistrarDriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

uses(RefreshDatabase::class);
$fresh = function (): void {
    RefreshDatabaseState::$migrated = false;
    RefreshDatabaseState::$inMemoryConnections = [];
};
beforeAll(function () use ($fresh): void {
    $fresh();
    TestCase::$onlyModules = 'Domains,Platform';
});
afterAll(function () use ($fresh): void {
    $fresh();
    TestCase::$onlyModules = null;
});

test('the host masks a package driver connection-test message', function () {
    app(RegistrarDriverRegistry::class)->register('leaky', fn () => new class implements RegistrarDriver, TestsRegistrarConnection
    {
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

        public function register(RegistrarConfig $c, RegistrationRequest $r): RegistrarResult
        {
            return new RegistrarResult;
        }

        public function renew(RegistrarConfig $c, DomainRef $d, int $y): RegistrarResult
        {
            return new RegistrarResult;
        }

        public function info(RegistrarConfig $c, DomainRef $d): DomainInfo
        {
            return new DomainInfo('active');
        }

        public function testConnection(RegistrarConfig $c): ConnectionCheck
        {
            return new ConnectionCheck(false, 'Access denied for key '.$c->setting('api_key'));
        }
    });
    RegistrarAccount::query()->create(['key' => 'leaky', 'is_active' => true, 'test_mode' => false, 'settings' => ['api_key' => 'sk-live-SECRET-123']]);
    actingAsStaff(['domains.registrars.manage']);
    confirmPassword();

    $this->post(route('admin.domains.registrars.test', 'leaky'));
    expect(json_encode(session('inertia.flash_data.toast')))->not->toContain('sk-live-SECRET-123');
});
