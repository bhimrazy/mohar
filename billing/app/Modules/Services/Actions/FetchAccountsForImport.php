<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Jobs\FetchServerAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Start reading a server's accounts for Import accounts, on the queue. One fetch per server at a time (under the server
 * row lock); a new fetch discards the previous list. Audited, because the list holds client emails.
 */
class FetchAccountsForImport
{
    public function __construct(private ProvisioningDriverRegistry $drivers, private ActivityLogger $activity) {}

    /**
     * @throws ValidationException when the server cannot be read or a fetch is running
     */
    public function handle(Server $server, ?User $staff): ServerAccountImport
    {
        if (! $this->drivers->has($server->driver) || ! $this->drivers->get($server->driver) instanceof ListsAccounts) {
            throw ValidationException::withMessages(['import' => __('The driver of :server cannot list its accounts, so they cannot be imported.', ['server' => $server->name])]);
        }

        if (! $server->is_active) {
            throw ValidationException::withMessages(['import' => __(':server is inactive. Activate it before importing its accounts.', ['server' => $server->name])]);
        }

        $import = DB::transaction(function () use ($server, $staff): ServerAccountImport {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();

            if (self::latest($server)?->isRunning()) {
                throw ValidationException::withMessages(['import' => __('The accounts on :server are being read already. This page updates when the list is ready.', ['server' => $server->name])]);
            }

            ServerAccountImport::query()->where('server_id', $server->id)->delete();

            return ServerAccountImport::query()->create([
                'server_id' => $server->id,
                'requested_by' => $staff?->id,
                'status' => ServerAccountImport::FETCHING,
                'expires_at' => now()->addHours(ServerAccountImport::HOURS),
            ]);
        });

        $this->activity->log("Fetch of the accounts on server {$server->name} requested for import", $server);
        FetchServerAccounts::dispatch($import->id);

        return $import;
    }

    /**
     * The server's list that has not expired, if any. An expired list is deleted here, so client emails never outlive
     * it by more than a read (housekeeping deletes the rest daily).
     */
    public static function latest(Server $server): ?ServerAccountImport
    {
        ServerAccountImport::query()->where('server_id', $server->id)->where('expires_at', '<=', now())->delete();

        return ServerAccountImport::query()
            ->with('requester:id,name')
            ->where('server_id', $server->id)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }
}
