<?php

namespace App\Modules\Migration\Actions;

use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Migration\Jobs\CheckRun;
use App\Modules\Migration\Models\MigrationRun;
use Illuminate\Validation\ValidationException;

/**
 * Saves a run's mapping and options and checks every row again (client-import spec §5.2–5.4). Group names found by an
 * earlier check must each have a decision first; a check still running from an earlier mapping stops.
 */
class StartCheck
{
    public function __construct(private ActivityLogger $audit) {}

    /**
     * @param  array{columns: list<string>, date_format?: string|null}  $mapping
     * @param  array<string, mixed>  $options
     */
    public function handle(MigrationRun $run, array $mapping, array $options): void
    {
        if (! in_array($run->status, [MigrationRun::MAPPING, MigrationRun::READY, MigrationRun::CHECKING], true)) {
            throw ValidationException::withMessages(['mapping' => __('This import cannot be checked now.')]);
        }

        // Names found by the last check under these same columns; a new mapping is checked afresh.
        $decided = array_map(fn (int|string $name): string => mb_strtolower((string) $name), array_keys(is_array($options['groups'] ?? null) ? $options['groups'] : []));
        $pending = ($run->mapping['columns'] ?? null) !== $mapping['columns'] ? [] : array_filter(
            array_keys($run->found_values['groups'] ?? []),
            fn (string $name): bool => ! in_array(mb_strtolower($name), $decided, true),
        );

        if ($pending !== []) {
            throw ValidationException::withMessages(['groups' => trans_choice('Choose what to do with the client group that was not found.|Choose what to do with the :count client groups that were not found.', count($pending), ['count' => count($pending)])]);
        }

        $run->forceFill([
            'status' => MigrationRun::CHECKING,
            'generation' => $run->generation + 1,
            'mapping' => $mapping,
            'options' => [...($run->options ?? []), ...$options],
            'checked_count' => 0,
            'heartbeat_at' => now(),
        ])->save();

        $this->audit->log('Client import mapping saved', $run, properties: ['file' => $run->file_name, 'columns' => array_count_values(array_filter($mapping['columns']))], event: 'migration.mapping_saved');
        dispatch(new CheckRun($run->id, $run->generation));
    }
}
