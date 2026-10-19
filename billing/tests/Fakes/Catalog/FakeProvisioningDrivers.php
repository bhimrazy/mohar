<?php

namespace Tests\Fakes\Catalog;

use App\Contracts\Services\ProvisioningDrivers;
use App\Data\Integrations\SettingField;
use App\Data\Services\RemoteOptions;
use App\Enums\FieldType;

/**
 * Installed drivers as Services would report them.
 */
final class FakeProvisioningDrivers implements ProvisioningDrivers
{
    /**
     * @param  list<array{key: string, label: string, fields: list<array<string, mixed>>, config_fields: list<array<string, mixed>>}>  $drivers
     */
    public function __construct(public array $drivers = [], public RemoteOptions $remoteOptions = new RemoteOptions) {}

    public static function bind(self $fake): self
    {
        app()->instance(ProvisioningDrivers::class, $fake);

        return $fake;
    }

    public static function withLogDriver(): self
    {
        return self::bind(new self([
            ['key' => 'log', 'label' => 'Log only', 'config_fields' => [
                ['key' => 'plan', 'label' => 'Plan name', 'type' => 'text', 'required' => true, 'secret' => false],
                ['key' => 'api_token', 'label' => 'API token', 'type' => 'password', 'required' => false, 'secret' => true],
            ], 'fields' => [
                (new SettingField('plan', 'Plan name', required: true))->toArray(),
                (new SettingField('api_token', 'API token', FieldType::Secret))->toArray(),
            ]],
        ]));
    }

    public function all(): array
    {
        return $this->drivers;
    }

    public function exists(string $key): bool
    {
        return in_array($key, array_column($this->drivers, 'key'), true);
    }

    public function remoteOptions(string $driverKey, string $fieldKey, array $serverIds, bool $refresh = false): RemoteOptions
    {
        return $this->remoteOptions;
    }
}
