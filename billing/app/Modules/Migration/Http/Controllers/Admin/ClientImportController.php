<?php

namespace App\Modules\Migration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Actions\UploadClientFile;
use App\Modules\Migration\Jobs\StageCsvRun;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Models\MigrationSource;
use App\Modules\Migration\Support\ClientColumns;
use App\Modules\Migration\Support\CsvFile;
use App\Support\Queues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Clients → Import clients (client-import spec §5.1, §14): upload a file and see the columns it was read with.
 * Pages read counts from the run and decrypt only the rows they show.
 */
class ClientImportController extends Controller
{
    public function index(Settings $settings): Response
    {
        $this->authorize('migration.runs.view');

        return Inertia::render('admin/migration/upload', [
            'sources' => MigrationSource::query()->where('source_system', 'csv')->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'runs' => MigrationRun::query()->with(['source:id,name', 'requester:id,name'])->latest('id')->paginate(25)->withQueryString()
                ->through(fn (MigrationRun $run): array => [...$this->summary($run), 'source' => $run->source->name]),
            'limits' => [
                'megabytes' => intdiv($this->maxBytes($settings), 1024 * 1024),
                'rows' => (int) $settings->get('migration.csv.max_rows'),
                'columns' => (int) $settings->get('migration.csv.max_columns'),
                // PHP refuses larger uploads before the app sees them; the page warns when that is below the setting.
                'server_megabytes' => (int) floor(UploadedFile::getMaxFilesize() / (1024 * 1024)),
            ],
            'presets' => $this->options(ClientColumns::PRESETS),
        ]);
    }

    public function store(Request $request, Settings $settings, UploadClientFile $upload): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt,tsv', 'max:'.intdiv($this->maxBytes($settings), 1024)],
            'source_id' => ['nullable', 'integer', Rule::exists('migration_sources', 'id')->where('source_system', 'csv')],
            'source_name' => ['required_without:source_id', 'nullable', 'string', 'max:100'],
            'delimiter' => ['nullable', Rule::in(array_keys(CsvFile::DELIMITERS))],
            'preset' => ['required', Rule::in(array_keys(ClientColumns::PRESETS))],
        ], [
            'file.max' => __('The file is larger than :size MB, the limit per file.', ['size' => intdiv($this->maxBytes($settings), 1024 * 1024)]),
            'file.extensions' => __('Upload a CSV file. Save spreadsheets as CSV UTF-8 first.'),
            'source_name.required_without' => __('Choose a source or name a new one.'),
        ]);
        /** @var UploadedFile $file */
        $file = $request->file('file');
        /** @var User $user */
        $user = $request->user();
        $run = $upload->handle($file, $data, $user);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Reading :file. The columns appear when it is done.', ['file' => $run->file_name])]);

        return to_route('admin.migration.imports.show', $run);
    }

    public function show(MigrationRun $run): Response
    {
        $this->authorize('migration.runs.view');

        return $this->map($run);
    }

    /**
     * Continue a step that failed or stalled, from where it stopped.
     */
    public function resume(MigrationRun $run): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        if ($run->file_key === null || ! $run->claimStopped(MigrationRun::STAGING)) {
            throw ValidationException::withMessages(['run' => __('This import is not stopped.')]);
        }

        dispatch(new StageCsvRun($run->id));
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Continuing :file where it stopped.', ['file' => $run->file_name])]);

        return to_route('admin.migration.imports.show', $run);
    }

    private function map(MigrationRun $run): Response
    {
        $header = $run->header ?? [];
        $samples = $this->samples($run, count($header));
        $earlier = MigrationRun::query()->with('requester:id,name')->where('migration_source_id', $run->migration_source_id)
            ->where('file_sha256', $run->file_sha256)->where('id', '<', $run->id)->latest('id')->first();

        return Inertia::render('admin/migration/map', [
            'run' => $this->summary($run),
            'columns' => array_map(fn (string $name, int $index): array => [
                'header' => $name,
                'samples' => $samples[$index] ?? [],
                'forbidden' => ClientColumns::isForbidden($name),
            ], $header, array_keys($header)),
            'earlier' => $earlier === null ? null : ['name' => $earlier->file_name, 'uploaded_at' => $earlier->created_at?->toIso8601String(), 'by' => $earlier->requester?->name, 'href' => route('admin.migration.imports.show', $earlier)],
        ]);
    }

    /**
     * What every page shows about a run.
     *
     * @return array<string, mixed>
     */
    private function summary(MigrationRun $run): array
    {
        return [
            'id' => $run->id,
            'name' => $run->label(),
            'status' => $run->status,
            'rows' => $run->row_count,
            'staged' => $run->staged_count,
            'checked' => $run->checked_count,
            'running' => $run->isRunning(),
            'stalled' => $run->isStalled(),
            'error' => $run->error,
            'queue' => Queues::IMPORTS,
            'requested_by' => $run->requester?->name,
            'uploaded_at' => $run->created_at?->toIso8601String(),
            'expires_at' => $run->expires_at->toIso8601String(),
        ];
    }

    /**
     * Up to three values per column from the first rows, for the map step. Values that look like hashes are masked.
     *
     * @return array<int, list<string>>
     */
    private function samples(MigrationRun $run, int $columns): array
    {
        $samples = [];

        foreach ($run->records()->orderBy('row_number')->limit(20)->get(['id', 'raw']) as $record) {
            foreach ($record->raw ?? [] as $index => $value) {
                if (trim($value) !== '' && $index < $columns && count($samples[$index] ?? []) < 3) {
                    $samples[$index][] = preg_match('/^\$2[aby]\$|^[a-f0-9]{32,}$/i', $value) === 1 ? '••••••' : mb_strimwidth(trim($value), 0, 60, '…');
                }
            }
        }

        return $samples;
    }

    private function maxBytes(Settings $settings): int
    {
        return (int) $settings->get('migration.csv.max_bytes');
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }
}
