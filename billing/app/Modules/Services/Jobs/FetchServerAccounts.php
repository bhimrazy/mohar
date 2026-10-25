<?php

namespace App\Modules\Services\Jobs;

use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Data\Services\Provisioning\RemoteAccount;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Modules\Services\Support\AccountList;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads every account on a server for Import accounts (ListsAccounts), matches them once (AccountImportMatcher) and
 * stores both on the import row; pages only read what is stored. Read-only on the server. Runs on the provisioning
 * queue with the longest command timeout (ADR 0014). One try: staff fetch again from the page. A failure is stored as
 * our message, with credentials masked. The result is audited against the staff member who asked for it.
 */
class FetchServerAccounts implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  bool  $rematch  "Match again": re-match the stored list instead of reading the server
     */
    public function __construct(public int $importId, public bool $rematch = false)
    {
        $this->onQueue('provisioning');
        $this->timeout = (int) config('services.module.max_job_timeout_seconds', 180);
    }

    public function handle(ProvisioningDriverRegistry $drivers, ProvisioningSnapshots $snapshots, Redactor $redactor, AccountImportMatcher $matcher, ActivityLogger $activity): void
    {
        $import = ServerAccountImport::query()->with(['server', 'requester'])->find($this->importId);

        if ($this->rematch && $import?->status === ServerAccountImport::MATCHING) {
            app(AccountList::class)->adopt($import);
            $matcher->rematch($import);

            return;
        }

        if ($import === null || $import->status !== ServerAccountImport::FETCHING) {
            return;
        }

        $server = $import->server;
        $driver = $drivers->has($server->driver) ? $drivers->get($server->driver) : null;

        if (! $driver instanceof ListsAccounts) {
            $this->markFailed($import, __('The :driver driver cannot list the accounts on a server.', ['driver' => $driver?->name() ?? $server->driver]));

            return;
        }

        $connection = $snapshots->connection($server);

        try {
            $accounts = array_map(fn (RemoteAccount $account): array => [
                'username' => $account->username,
                'domain' => $account->domain,
                'plan' => $account->plan,
                'owner' => $account->owner,
                'email' => $account->email,
                'ip' => $account->ip,
                'suspended' => $account->suspended,
                'suspend_reason' => $account->suspendReason,
                'created_at' => $account->createdAt?->toIso8601String(),
                'disk_used_mb' => $account->diskUsedMb,
                'disk_limit_mb' => $account->diskLimitMb,
            ], array_values(array_filter($driver->listAccounts($connection), fn (RemoteAccount $account): bool => $account->username !== '')));
        } catch (Throwable $exception) {
            $message = $redactor->maskValues($exception->getMessage(), $connection->secrets());
            report(new \RuntimeException($message));
            $this->markFailed($import, __('The accounts on :server could not be read: :error', ['server' => $server->name, 'error' => $message]));
            $activity->log("Fetching the accounts on server {$server->name} for import failed", $server, actor: $import->requester);

            return;
        }

        $count = $matcher->store($import, $accounts, AccountImportMatcher::connectionUser($driver, $connection));

        if ($count === null) {
            return; // superseded by a later fetch while it ran
        }

        $activity->log("Accounts on server {$server->name} fetched for import", $server, properties: ['accounts' => $count], actor: $import->requester);
    }

    /**
     * The worker died or timed out: the fetch is over, and staff may start another.
     */
    public function failed(?Throwable $exception): void
    {
        $import = ServerAccountImport::query()->find($this->importId);

        if ($import !== null && $import->status === ServerAccountImport::FETCHING) {
            $this->markFailed($import, __('Reading the accounts took too long or was interrupted. Fetch again.'));
        }

        // A match that died leaves the list as it was.
        if ($import !== null && $import->status === ServerAccountImport::MATCHING) {
            $import->update(['status' => ServerAccountImport::READY]);
        }
    }

    private function markFailed(ServerAccountImport $import, string $error): void
    {
        $import->update(['status' => ServerAccountImport::FAILED, 'error' => mb_substr($error, 0, 500)]);
    }
}
