<?php

namespace App\Modules\Services\Actions;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Support\AccountList;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Import accounts (cPanel spec §10, #140): staff mark accounts they will never import (a test account, a customer
 * who left) as ignored, or bring them back. An ignored account leaves the work list and "Select all proposed", cannot
 * be ticked, and stays ignored in later fetches: the choice is kept per server and username (server_import_ignores).
 * An account a service already holds is never ignored.
 */
class IgnoreImportAccounts
{
    public function __construct(private AccountList $list, private ActivityLogger $activity) {}

    /**
     * @param  list<string>  $usernames
     * @return int accounts changed
     *
     * @throws ValidationException when the list is not ready
     */
    public function handle(Server $server, array $usernames, bool $ignored, ?User $staff): int
    {
        $changed = DB::transaction(function () use ($server, $usernames, $ignored, $staff): int {
            Server::query()->whereKey($server->id)->lockForUpdate()->first();
            $import = FetchAccountsForImport::latest($server);

            if ($import?->status !== ServerAccountImport::READY) {
                throw ValidationException::withMessages(['import' => __('The accounts on :server cannot be changed now: fetch them again, or wait for the running step to finish.', ['server' => $server->name])]);
            }

            $usernames = $this->list->existing($import, $usernames);
            $before = count($this->list->ignoredAmong($import, $usernames));
            $this->list->setIgnored($import, $usernames, $ignored);
            $after = $this->list->ignoredAmong($import, $usernames);
            $names = $ignored ? $after : $usernames;

            if ($ignored) {
                // An ignored account is no longer ticked.
                $selections = array_diff_key($import->selections ?? [], array_flip($after));
                $import->update(['selections' => $selections ?: null]);
                DB::table('server_import_ignores')->insertOrIgnore(array_map(fn (string $username): array => [
                    'server_id' => $server->id, 'username' => $username, 'created_by' => $staff?->id, 'created_at' => now(),
                ], $after));
            } else {
                foreach (array_chunk($names, 500) as $chunk) {
                    DB::table('server_import_ignores')->where('server_id', $server->id)->whereIn('username', $chunk)->delete();
                }
            }

            $this->list->refreshCounts($import);

            return $ignored ? count($after) - $before : $before - count($this->list->ignoredAmong($import, $usernames));
        });

        $verb = $ignored ? 'ignored' : 'restored';
        $this->activity->log("Accounts on server {$server->name} {$verb} for import", $server, properties: ['accounts' => count($usernames), 'changed' => $changed], actor: $staff);

        return $changed;
    }
}
