<?php

namespace App\Modules\Services\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Support\ServerSettings;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a server; a server marked default clears the flag on others of its driver. Driver settings are
 * merged into the stored ones (a blank secret keeps its value) and their changes audited, secrets as "rotated".
 */
class SaveServer
{
    /** Credentials on the server row that a driver may leave unused (DriverMetadata::$connectionFields). */
    private const CREDENTIALS = ['password', 'api_token', 'access_hash'];

    public function __construct(private ServerSettings $settings, private ActivityLogger $activity, private ProvisioningDriverRegistry $drivers) {}

    /**
     * @param  array<string, mixed>  $data  `settings` holds the submitted driver settings
     */
    public function handle(?Server $server, array $data): Server
    {
        return DB::transaction(function () use ($server, $data): Server {
            // Re-read under a lock: a blank secret keeps the stored value, which must be the latest one.
            $server = $server?->exists ? Server::query()->lockForUpdate()->findOrFail($server->id) : $server;
            $existing = $server !== null && $server->exists;
            $merged = $this->settings->merge((string) ($data['driver'] ?? $server?->driver), $server, (array) ($data['settings'] ?? []));
            $server ??= new Server;
            $server->fill([...$data, 'settings' => $merged['settings'] ?: null]);
            $removed = $this->clearUnusedCredentials($server);
            $server->save();
            $changes = [...$merged['changes'], ...array_fill_keys($removed, 'removed')];

            if ($existing && $changes !== []) {
                $this->activity->log("Server {$server->name} settings changed", $server, properties: ['changes' => $changes]);
            }

            if ($server->is_default) {
                Server::query()->where('driver', $server->driver)->whereKeyNot($server->id)->update(['is_default' => false]);
            }

            return $server;
        });
    }

    /**
     * Clear stored credentials the driver does not read with its current settings (a token after switching to
     * password sign-in, say), so no unused secret lingers. Drivers that declare no connection fields keep everything.
     *
     * @return list<string> the credentials removed
     */
    private function clearUnusedCredentials(Server $server): array
    {
        $metadata = $this->drivers->has($server->driver) ? $this->drivers->metadata($server->driver) : null;
        $fields = $metadata?->connectionFields;

        if ($metadata === null || $fields === null) {
            return [];
        }

        // A setting left unset counts as its default, as the driver reads it.
        $settings = $server->settings ?? [];

        foreach ($metadata->serverFields as $field) {
            if (! array_key_exists($field->key, $settings) && $field->default !== null) {
                $settings[$field->key] = is_bool($field->default) ? ($field->default ? '1' : '0') : (string) $field->default;
            }
        }

        $removed = [];

        foreach (self::CREDENTIALS as $credential) {
            $conditions = $fields[$credential] ?? null;
            $used = $conditions !== null && array_filter($conditions, fn (string $value, string $setting): bool => (string) ($settings[$setting] ?? '') !== $value, ARRAY_FILTER_USE_BOTH) === [];

            if (! $used && (string) $server->getAttribute($credential) !== '') {
                $server->setAttribute($credential, null);
                $removed[] = $credential;
            }
        }

        return $removed;
    }
}
