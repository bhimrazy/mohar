<?php

namespace App\Modules\Services\Services;

use App\Contracts\Services\Provisioning\ProvidesFieldOptions;
use App\Contracts\Services\ProvisioningDrivers;
use App\Data\Integrations\SettingField;
use App\Data\Services\RemoteOptions;
use App\Modules\Services\Models\Server;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Exposes the installed provisioning drivers (the kernel registry) and their product config fields to other modules.
 */
class RegistryProvisioningDrivers implements ProvisioningDrivers
{
    /** Seconds a server's answer for a remote-filled field is reused. */
    private const OPTIONS_TTL = 600;

    public function __construct(
        private ProvisioningDriverRegistry $drivers,
        private ProvisioningSnapshots $snapshots,
        private Redactor $redactor,
    ) {}

    public function all(): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->drivers->get($key)->name(),
            'config_fields' => array_map(fn (SettingField $field): array => $field->toArray(), $this->drivers->metadata($key)->productConfigFields),
        ], $this->drivers->keys());
    }

    public function exists(string $key): bool
    {
        return $this->drivers->has($key);
    }

    public function remoteOptions(string $driverKey, string $fieldKey, array $serverIds, bool $refresh = false): RemoteOptions
    {
        if (! $this->drivers->has($driverKey) || $serverIds === []) {
            return new RemoteOptions;
        }

        $driver = $this->drivers->get($driverKey);
        $field = collect($driver->metadata()->productConfigFields)->first(fn (SettingField $field): bool => $field->key === $fieldKey);

        if (! $driver instanceof ProvidesFieldOptions || $field?->optionsSource !== SettingField::OPTIONS_REMOTE) {
            return new RemoteOptions;
        }

        $answers = [];
        $unreachable = [];

        foreach (Server::query()->whereKey($serverIds)->where('driver', $driverKey)->orderBy('id')->get() as $server) {
            $cacheKey = "services:field-options:{$driverKey}:{$fieldKey}:{$server->id}";

            if ($refresh) {
                Cache::forget($cacheKey);
            }

            $connection = $this->snapshots->connection($server);

            try {
                $answers[$server->id] = Cache::remember($cacheKey, self::OPTIONS_TTL, fn (): array => $driver->fieldOptions($connection, $fieldKey));
            } catch (Throwable $exception) {
                report(new \RuntimeException($this->redactor->maskValues($exception->getMessage(), $connection->secrets())));
                $unreachable[$server->id] = $server->name;
            }
        }

        $options = array_replace(...array_values($answers ?: [[]]));
        $names = Server::query()->whereKey(array_keys($answers))->pluck('name', 'id')->all();
        $missingOn = [];

        foreach (array_keys($options) as $value) {
            foreach ($answers as $serverId => $offered) {
                if (! array_key_exists($value, $offered)) {
                    $missingOn[(string) $value][$serverId] = (string) $names[$serverId];
                }
            }
        }

        return new RemoteOptions($options, $missingOn, $unreachable);
    }
}
