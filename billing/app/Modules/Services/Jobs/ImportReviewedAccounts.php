<?php

namespace App\Modules\Services\Jobs;

use App\Models\User;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Models\ServerAccountImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

        $staff = $this->staffId === null ? null : User::query()->find($this->staffId);
        $stop = match (true) {
            ! hash_equals((string) ($import->reviewed['digest'] ?? ''), $this->digest) => __('The reviewed accounts changed after the import was confirmed; nothing more was imported. Review them again.'),
            $staff === null || ! $staff->is_active || ! $staff->hasPermission('services.servers.import') => __('The staff member who confirmed the import can no longer import accounts; nothing more was imported.'),
            default => null,
        };

        if ($stop !== null) {
            $import->update(['report' => [...($import->report ?? []), ['username' => '', 'imported' => false, 'message' => $stop, 'service_id' => null]]]);
            $importer->finish($import->server, $import, $staff);

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
     * The worker died or timed out: the import ends here, with what was imported so far.
     */
    public function failed(?Throwable $exception): void
    {
        $import = ServerAccountImport::query()->with('server')->find($this->importId);

        if ($import !== null && $import->status === ServerAccountImport::IMPORTING) {
            app(ImportServerAccounts::class)->finish($import->server, $import, $this->staffId === null ? null : User::query()->find($this->staffId));
        }
    }
}
