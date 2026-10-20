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
     * "Match again": re-match the server's ready list on the queue (AccountImportMatcher::rematch()), under the same
     * server lock as a fetch, so the two never overlap. Audited.
     *
     * @throws ValidationException when there is no ready list, or a fetch or match is running
     */
    public function rematch(Server $server): void
    {
        $import = DB::transaction(function () use ($server): ServerAccountImport {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();
            $import = self::latest($server);

            if ($import?->status !== ServerAccountImport::READY) {
                throw ValidationException::withMessages(['import' => $import?->isRunning()
                    ? __('The accounts on :server are being read or matched already. This page updates when they are ready.', ['server' => $server->name])
                    : __('Fetch the accounts on :server first.', ['server' => $server->name])]);
            }

            $import->update(['status' => ServerAccountImport::MATCHING]);

            return $import;
        });

        $this->activity->log("Accounts on server {$server->name} matched again for import", $server);
        FetchServerAccounts::dispatch($import->id, rematch: true);
    }

    /**
     * The server's list that has not expired, if any, without the account list unless $withAccounts. An expired list is deleted here, so client emails never outlive
     * it by more than a read (housekeeping deletes the rest daily).
     */
    public static function latest(Server $server, bool $withAccounts = false): ?ServerAccountImport
    {
        ServerAccountImport::query()->where('server_id', $server->id)->where('expires_at', '<=', now())->delete();

        // The encrypted account list can be megabytes: it is read only when asked for.
        $columns = array_values(array_diff((new ServerAccountImport)->getFillable(), $withAccounts ? [] : ['accounts']));

        return ServerAccountImport::query()
            ->select(['id', 'created_at', 'updated_at', ...$columns])
            ->with('requester:id,name')
            ->where('server_id', $server->id)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }
}
