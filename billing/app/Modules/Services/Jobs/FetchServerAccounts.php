<?php

namespace App\Modules\Services\Jobs;

use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Data\Services\Provisioning\RemoteAccount;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads every account on a server for Import accounts (ListsAccounts) and stores the list on the import row. Read-only
 * on the server. One try: staff fetch again from the page. A failure is stored as our message, with credentials masked.
 */
class FetchServerAccounts implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Above the driver's own budget for background reads (two minutes for cPanel). */
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public int $importId) {}

    public function handle(ProvisioningDriverRegistry $drivers, ProvisioningSnapshots $snapshots, Redactor $redactor): void
    {
        $import = ServerAccountImport::query()->with('server')->find($this->importId);

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
                'email' => $account->email === null ? null : strtolower($account->email),
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

            return;
        }

        $import->update([
            'status' => ServerAccountImport::READY,
            'accounts' => $accounts,
            'account_count' => count($accounts),
            'fetched_at' => now(),
        ]);
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
    }

    private function markFailed(ServerAccountImport $import, string $error): void
    {
        $import->update(['status' => ServerAccountImport::FAILED, 'error' => mb_substr($error, 0, 500)]);
    }
}
