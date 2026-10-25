<?php

namespace App\Modules\Services\Jobs;

use App\Models\User;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Support\AccountImportMatcher;
use App\Modules\Services\Support\AccountList;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * One chunk of a confirmed import (ImportServerAccounts): the next CHUNK reviewed accounts, appended to the stored
 * report, then the next chunk is queued; the last one finishes the import. Runs on the provisioning queue with the
 * longest command timeout (ADR 0014). One try: a failed chunk ends the import, and what was imported stays.
 */
class ImportReviewedAccounts implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  string  $digest  the reviewed rows' digest the staff member confirmed; a chunk stops if they changed since
     */
    public function __construct(public int $importId, public ?int $staffId, public int $offset = 0, public string $digest = '')
    {
        $this->onQueue('provisioning');
        $this->timeout = (int) config('services.module.max_job_timeout_seconds', 180);
    }

    public function handle(ImportServerAccounts $importer): void
    {
        $import = ServerAccountImport::query()->with('server')->find($this->importId);

        if ($import === null || $import->status !== ServerAccountImport::IMPORTING) {
            return;
        }

        // An import the previous release started still has its list in the old column.
        app(AccountList::class)->adopt($import);
        $staff = $this->staffId === null ? null : User::query()->find($this->staffId);
        $stop = match (true) {
            ! hash_equals((string) ($import->reviewed['digest'] ?? ''), $this->digest) => AccountImportMatcher::text('The accounts changed after the import was confirmed, so they need a new review.'),
            $staff === null || ! $staff->is_active || ! $staff->hasPermission('services.servers.import') => AccountImportMatcher::text('The staff member who confirmed it can no longer import accounts.'),
            default => null,
        };

        if ($stop !== null) {
            $importer->stop($import->server, $import, $staff, $stop);

            return;
        }

        // Each row's report line is written with its service (ImportServerAccounts::handle()), so a failed chunk keeps them.
        $importer->handle($import->server, $import, $staff, array_slice(array_column($import->reviewed['rows'] ?? [], 'username'), $this->offset, ImportServerAccounts::CHUNK));

        if ($this->offset + ImportServerAccounts::CHUNK < count($import->reviewed['rows'] ?? [])) {
            self::dispatch($this->importId, $this->staffId, $this->offset + ImportServerAccounts::CHUNK, $this->digest);

            return;
        }

        $importer->finish($import->server, $import, $staff);
    }

    /**
     * The worker failed or timed out: the import stops here, says why, and reports every account it did not reach.
     */
    public function failed(?Throwable $exception): void
    {
        $import = ServerAccountImport::query()->with('server')->find($this->importId);

        if ($import !== null && $import->status === ServerAccountImport::IMPORTING) {
            app(AccountList::class)->adopt($import);
            app(ImportServerAccounts::class)->stop($import->server, $import, $this->staffId === null ? null : User::query()->find($this->staffId), $exception instanceof TimeoutExceededException
                ? AccountImportMatcher::text('The background job took too long.')
                : AccountImportMatcher::text('The background job failed.'));
        }
    }
}
