<?php

namespace App\Modules\Migration\Jobs;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Support\ClientColumns;
use App\Modules\Migration\Support\CsvFile;
use App\Modules\Migration\Support\EncryptedFile;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads an uploaded file into encrypted staging rows, 500 per insert, then deletes the file (client-import spec
 * §5.1). A job stages at most ROWS_PER_JOB rows and queues the next; a retry resumes after the last staged row, so no
 * row is staged twice. Password and card columns are dropped here and never stored.
 */
final class StageCsvRun implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const ROWS_PER_JOB = 20_000;

    private const BATCH = 500;

    public int $tries = 3;

    public int $timeout = Queues::IMPORT_JOB_TIMEOUT_SECONDS;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $runId)
    {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(ActivityLogger $audit): void
    {
        $run = MigrationRun::query()->find($this->runId);

        if ($run === null || $run->file_key === null || ! in_array($run->status, [MigrationRun::UPLOADED, MigrationRun::STAGING], true)) {
            return;
        }

        $staged = (int) $run->records()->max('row_number');
        $handle = EncryptedFile::open($run->file_key);
        $header = CsvFile::read($handle, $run->delimiter) ?? [];
        $dropped = array_flip(array_keys(array_filter($header, ClientColumns::isForbidden(...))));
        $run->forceFill(['status' => MigrationRun::STAGING, 'header' => $header, 'heartbeat_at' => now()])->save();
        $row = 0;
        $batch = [];

        while (($cells = CsvFile::read($handle, $run->delimiter)) !== null) {
            if (++$row <= $staged) {
                continue;
            }

            $raw = json_encode(array_diff_key($cells, $dropped), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $batch[] = ['migration_run_id' => $run->id, 'row_number' => $row, 'raw' => Crypt::encryptString($raw)];

            if (count($batch) === self::BATCH) {
                $this->insert($run, $batch, $row);
                $batch = [];
            }

            if ($row - $staged >= self::ROWS_PER_JOB) {
                $this->insert($run, $batch, $row);
                fclose($handle);
                dispatch(new self($run->id));

                return;
            }
        }

        $this->insert($run, $batch, $row);
        fclose($handle);
        Storage::disk('objects')->delete($run->file_key);
        $run->forceFill([
            'status' => MigrationRun::MAPPING,
            'file_key' => null,
            'row_count' => $row,
            'staged_count' => $row,
        ])->save();
        $audit->log('Client import file read and deleted', $run, properties: ['rows' => $row, 'file' => $run->file_name], event: 'migration.file_deleted');
    }

    public function failed(?Throwable $exception): void
    {
        MigrationRun::query()->whereKey($this->runId)->whereIn('status', [MigrationRun::UPLOADED, MigrationRun::STAGING])
            ->update(['status' => MigrationRun::FAILED, 'error' => __('Reading the file stopped before the end. Continue to read the rest.')]);
    }

    /**
     * @param  list<array{migration_run_id: int, row_number: int, raw: string}>  $batch
     */
    private function insert(MigrationRun $run, array $batch, int $row): void
    {
        if ($batch !== []) {
            MigrationRecord::query()->insert($batch);
        }

        $run->forceFill(['staged_count' => $row, 'heartbeat_at' => now()])->save();
    }
}
