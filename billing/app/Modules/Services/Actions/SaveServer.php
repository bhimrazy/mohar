<?php

namespace App\Modules\Services\Actions;

use App\Modules\Services\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * Create or update a server; a server marked default clears the flag on others of its driver.
 */
class SaveServer
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?Server $server, array $data): Server
    {
        return DB::transaction(function () use ($server, $data): Server {
            $server ??= new Server;
            $server->fill($data)->save();

            if ($server->is_default) {
                Server::query()->where('driver', $server->driver)->whereKeyNot($server->id)->update(['is_default' => false]);
            }

            return $server;
        });
    }
}
