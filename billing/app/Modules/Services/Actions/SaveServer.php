<?php

namespace App\Modules\Services\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Support\ServerSettings;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a server; a server marked default clears the flag on others of its driver. Driver settings are
 * merged into the stored ones (a blank secret keeps its value) and their changes audited, secrets as "rotated".
 *
 * A new destination (Server::DESTINATION) clears every stored credential, secret setting and trusted certificate the
 * same save does not supply again, and the move is audited with the old and new address (security review
 * 2026-10-19, M1): what was saved for one host is never sent to another.
 */
class SaveServer
{
    /** Credentials on the server row that a driver may leave unused (DriverMetadata::$connectionFields). */
    private const CREDENTIALS = Server::CREDENTIALS;

    public function __construct(private ServerSettings $settings, private ActivityLogger $activity) {}

    /**
     * @param  array<string, mixed>  $data  `settings` holds the submitted driver settings
     */
    public function handle(?Server $server, array $data): Server
    {
        return DB::transaction(function () use ($server, $data): Server {
            // Re-read under a lock: a blank secret keeps the stored value, which must be the latest one.
            $server = $server?->exists ? Server::query()->lockForUpdate()->findOrFail($server->id) : $server;
            $existing = $server !== null && $server->exists;
            $retargeted = $existing && $server->retargetedBy($data);
            $from = $server?->destination();
            $driver = (string) ($data['driver'] ?? $server?->driver);
            $merged = $this->settings->merge($driver, $server, (array) ($data['settings'] ?? []));
            $cleared = $retargeted ? $this->withoutStoredSecrets($driver, $merged, (array) ($data['settings'] ?? [])) : [];
            $server ??= new Server;
            $server->fill([...$data, 'settings' => $merged['settings'] ?: null]);

            if ($retargeted) {
                $cleared = [...$this->clearStoredCredentials($server, $data), ...$cleared];
            }

            $removed = $this->clearUnusedCredentials($server);
            $server->save();
            $changes = [...$merged['changes'], ...array_fill_keys($removed, 'removed'), ...array_fill_keys($cleared, 'removed')];

            if ($retargeted) {
                $this->activity->log('Server connection address changed', $server, properties: ['server' => $server->name, 'from' => $from, 'to' => $server->destination(), 'cleared' => $cleared], event: 'server.retargeted');
            }

            if ($existing && $changes !== []) {
                $this->activity->log('Server settings changed', $server, properties: ['server' => $server->name, 'changes' => $changes]);
            }

            if ($server->is_default) {
                Server::query()->where('driver', $server->driver)->whereKeyNot($server->id)->update(['is_default' => false]);
            }

            return $server;
        });
    }

    /**
     * The server-row credentials still holding the value saved for the old destination (none was entered with this
     * save) are cleared.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function clearStoredCredentials(Server $server, array $data): array
    {
        $cleared = [];

        foreach (self::CREDENTIALS as $credential) {
            if (! array_key_exists($credential, $data) && (string) $server->getOriginal($credential) !== '') {
                $server->setAttribute($credential, null);
                $cleared[] = $credential;
            }
        }

        return $cleared;
    }

    /**
     * Drop secret settings kept from the old destination (not entered again) and the trusted certificate, which
     * belongs to the old host's key.
     *
     * @param  array{settings: array<string, mixed>, changes: array<string, mixed>}  $merged
     * @param  array<string, mixed>  $submitted
     * @return list<string> the settings removed, as "settings.<key>"
     */
    private function withoutStoredSecrets(string $driver, array &$merged, array $submitted): array
    {
        $cleared = [];

        foreach ([...$this->settings->secretKeys($driver), ServerSettings::CERTIFICATE_PIN] as $key) {
            $entered = $key !== ServerSettings::CERTIFICATE_PIN && (string) ($submitted[$key] ?? '') !== '';

            if (! $entered && array_key_exists($key, $merged['settings'])) {
                unset($merged['settings'][$key], $merged['changes']["settings.{$key}"]);
                $cleared[] = "settings.{$key}";
            }
        }

        return $cleared;
    }

    /**
     * Clear stored credentials the driver does not read with its current settings (a token after switching to
     * password sign-in, say), so no unused secret lingers. Drivers that declare no connection fields keep everything.
     *
     * @return list<string> the credentials removed
     */
    private function clearUnusedCredentials(Server $server): array
    {
        $used = $this->settings->usedCredentials($server->driver, $server->settings ?? []);

        if ($used === null) {
            return [];
        }

        $removed = [];

        foreach (self::CREDENTIALS as $credential) {
            if (! in_array($credential, $used, true) && (string) $server->getAttribute($credential) !== '') {
                $server->setAttribute($credential, null);
                $removed[] = $credential;
            }
        }

        return $removed;
    }
}
