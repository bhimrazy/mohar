<?php

/*
 * ADR 0009 Amendment (#489): only the gateway host stores certificate uploads. A registrar or provisioning driver that
 * declares one fails as soon as its fields are read, and a key starting with "_" is reserved for host entries.
 */

use App\Data\Integrations\SettingField;
use App\Data\Services\Provisioning\DriverMetadata;
use App\Enums\FieldType;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Support\Domains\RegistrarDriverRegistry;
use App\Support\Services\ProvisioningDriverRegistry;
use Tests\Fakes\Domains\FakeRegistrar;
use Tests\Fakes\Domains\FakeRegistrarDriver;

test('a registrar driver declaring a certificate setting is refused when its fields are read', function () {
    app(RegistrarDriverRegistry::class)->register('cert_registrar', fn () => new class(new FakeRegistrar, 'cert_registrar') extends FakeRegistrarDriver
    {
        public function settingsFields(): array
        {
            return [new SettingField('client_cert', 'Client certificate', FieldType::Certificate)];
        }
    });

    expect(fn () => app(RegistrarDriverRegistry::class)->fields('cert_registrar'))
        ->toThrow(LogicException::class, 'The registrar driver [cert_registrar] declares the certificate setting [client_cert]');
});

test('a provisioning driver declaring a certificate setting is refused when its metadata is read', function () {
    app(ProvisioningDriverRegistry::class)->register('cert_panel', fn () => new class extends LogDriver
    {
        public function key(): string
        {
            return 'cert_panel';
        }

        public function metadata(): DriverMetadata
        {
            return new DriverMetadata(requiresServer: true, serverFields: [new SettingField('client_cert', 'Client certificate', FieldType::Certificate)]);
        }
    });

    expect(fn () => app(ProvisioningDriverRegistry::class)->metadata('cert_panel'))
        ->toThrow(LogicException::class, 'The provisioning driver [cert_panel] declares the certificate setting [client_cert]');
});

test('setting keys starting with an underscore are reserved', function () {
    expect(fn () => new SettingField('_certificates', 'Certificates'))->toThrow(InvalidArgumentException::class, 'reserved');
});
