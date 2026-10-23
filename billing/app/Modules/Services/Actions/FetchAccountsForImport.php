<?php

namespace App\Modules\Services\Actions;

use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Jobs\FetchServerAccounts;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Support\AccountList;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Start reading a server's accounts for Import accounts, on the queue. One fetch per server at a time (under the server
 * row lock); the previous list stays until the new one arrives, then is replaced. Audited, because the list holds client emails.
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

            $previous = self::latest($server);

            if ($previous?->isRunning()) {
                throw ValidationException::withMessages(['import' => __('The accounts on :server are being read already. This page updates when the list is ready.', ['server' => $server->name])]);
            }

            // The last good list (and its import report) stays until the new list arrives (FetchServerAccounts), so a
            // failed fetch hides nothing; earlier failures and fetches that never finished are cleared.
            ServerAccountImport::query()->where('server_id', $server->id)->whereIn('status', [ServerAccountImport::FAILED, ServerAccountImport::FETCHING])->delete();

            return ServerAccountImport::query()->create([
                'server_id' => $server->id,
                'requested_by' => $staff?->id,
                'status' => ServerAccountImport::FETCHING,
                // Staff choices carry over to the new list for the accounts still on the server (AccountImportMatcher::store()).
                'selections' => $previous?->selections,
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
     * The server's current list that has not expired, if any: the newest one that did not fail, or the failed one when
     * there is no other. Its accounts are read through AccountList, never with it. An expired list is deleted here
     * (unless its import is still running), so client emails never outlive it by more than a read (housekeeping
     * deletes the rest daily).
     */
    public static function latest(Server $server): ?ServerAccountImport
    {
        ServerAccountImport::pruneExpired(serverId: $server->id);

        $columns = (new ServerAccountImport)->getFillable();

        // A Match again whose job never ran: the list it left is still the last good one, so it becomes usable again.
        ServerAccountImport::query()->where('server_id', $server->id)->where('status', ServerAccountImport::MATCHING)
            ->where('updated_at', '<=', now()->subMinutes(ServerAccountImport::STALE_MINUTES))
            ->update(['status' => ServerAccountImport::READY]);

        $query = fn () => ServerAccountImport::query()
            ->select(['id', 'created_at', 'updated_at', ...$columns])
            ->with('requester:id,name')
            ->where('server_id', $server->id)
            // An import still running outlives its list's expiry (ServerAccountImport::pruneExpired()).
            ->where(fn ($query) => $query->where('expires_at', '>', now())->orWhere('status', ServerAccountImport::IMPORTING))
            ->latest('id');

        // A failed fetch does not replace the last good list: that list stays current, the failure is shown beside it.
        $import = $query()->where('status', '!=', ServerAccountImport::FAILED)->first() ?? $query()->first();

        if ($import !== null) {
            app(AccountList::class)->adopt($import);
        }

        return $import;
    }

    /**
     * A fetch that failed after the given list was read (shown beside it), if any.
     */
    public static function failureAfter(Server $server, ServerAccountImport $import): ?ServerAccountImport
    {
        return ServerAccountImport::query()->select(['id', 'error', 'created_at', 'requested_by'])->with('requester:id,name')
            ->where('server_id', $server->id)->where('status', ServerAccountImport::FAILED)->where('id', '>', $import->id)->latest('id')->first();
    }
}
