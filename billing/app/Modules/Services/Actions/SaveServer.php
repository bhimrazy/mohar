<?php

namespace App\Modules\Services\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Support\ServerSettings;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a server; a server marked default clears the flag on others of its driver. Driver settings are
 * merged into the stored ones (a blank secret keeps its value) and their changes audited, secrets as "rotated".
 */
class SaveServer
{
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
            $merged = $this->settings->merge((string) ($data['driver'] ?? $server?->driver), $server, (array) ($data['settings'] ?? []));
            $server ??= new Server;
            $server->fill([...$data, 'settings' => $merged['settings'] ?: null])->save();

            if ($existing && $merged['changes'] !== []) {
                $this->activity->log("Server {$server->name} settings changed", $server, properties: ['changes' => $merged['changes']]);
            }

            if ($server->is_default) {
                Server::query()->where('driver', $server->driver)->whereKeyNot($server->id)->update(['is_default' => false]);
            }

            return $server;
        });
    }
}
