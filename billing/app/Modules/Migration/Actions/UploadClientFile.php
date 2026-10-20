<?php

namespace App\Modules\Migration\Actions;

use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Jobs\StageCsvRun;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Models\MigrationSource;
use App\Modules\Migration\Support\CsvFile;
use App\Modules\Migration\Support\EncryptedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Accepts a client file (client-import spec §5.1): checks the limits and encoding before anything is stored, encrypts
 * it onto the objects disk, deletes the request's temporary copy, and queues the staging job.
 */
class UploadClientFile
{
    public function __construct(private Settings $settings, private ActivityLogger $audit) {}

    /**
     * @param  array{source_id?: int|null, source_name?: string|null, delimiter?: string|null, preset: string}  $input
     */
    public function handle(UploadedFile $file, array $input, User $user): MigrationRun
    {
        $path = (string) $file->getRealPath();
        $delimiter = CsvFile::DELIMITERS[$input['delimiter'] ?? ''] ?? null;
        $inspected = CsvFile::inspect($path, $delimiter, (int) $this->settings->get('migration.csv.max_rows'), (int) $this->settings->get('migration.csv.max_columns'));
        $source = isset($input['source_id'])
            ? MigrationSource::query()->where('source_system', 'csv')->findOrFail($input['source_id'])
            : MigrationSource::query()->create(['source_system' => 'csv', 'name' => trim((string) ($input['source_name'] ?? '')), 'created_by' => $user->id]);
        $key = 'migration/uploads/'.Str::uuid()->toString().'.csv.enc';

        EncryptedFile::store($path, $key);

        $run = MigrationRun::query()->create([
            'migration_source_id' => $source->id,
            'source_system' => 'csv',
            'entity' => 'client',
            'status' => MigrationRun::UPLOADED,
            'file_key' => $key,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_sha256' => (string) hash_file('sha256', $path),
            'file_bytes' => (int) $file->getSize(),
            'delimiter' => $inspected['delimiter'],
            'row_count' => $inspected['rows'],
            'options' => ['preset' => $input['preset']],
            'requested_by' => $user->id,
            'heartbeat_at' => now(),
            'expires_at' => now()->addDays((int) $this->settings->get('migration.staging_days')),
        ]);
        @unlink($path);

        $this->audit->log('Client import file uploaded', $run, properties: ['file' => $run->file_name, 'rows' => $run->row_count, 'source' => $source->name], event: 'migration.uploaded');
        dispatch(new StageCsvRun($run->id));

        return $run;
    }
}
