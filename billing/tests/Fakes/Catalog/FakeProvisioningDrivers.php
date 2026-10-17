<?php

namespace Tests\Fakes\Catalog;

use App\Contracts\Services\ProvisioningDrivers;

/**
 * Installed drivers as Services would report them.
 */
final class FakeProvisioningDrivers implements ProvisioningDrivers
{
    /**
     * @param  list<array{key: string, label: string, config_fields: list<array{key: string, label: string, type: string, required: bool, secret: bool, options?: list<string>}>}>  $drivers
     */
    public function __construct(public array $drivers = []) {}

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
                ['key' => 'api_token', 'label' => 'API token', 'type' => 'text', 'required' => false, 'secret' => true],
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
}
