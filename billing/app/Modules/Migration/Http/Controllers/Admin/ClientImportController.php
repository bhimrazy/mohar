<?php

namespace App\Modules\Migration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Actions\StartCheck;
use App\Modules\Migration\Actions\UploadClientFile;
use App\Modules\Migration\Jobs\CheckRun;
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
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Clients → Import clients (client-import spec §5, §14): upload a file, map its columns, and check every row.
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

    public function mapping(Request $request, MigrationRun $run, StartCheck $start): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        $header = $run->header ?? [];
        $currencies = Currency::query()->pluck('code')->all();
        $validator = validator($request->all(), [
            'columns' => ['required', 'array', 'size:'.count($header)],
            'columns.*' => ['required', Rule::in(array_keys(ClientColumns::TARGETS))],
            'date_format' => ['nullable', Rule::in(array_keys(ClientColumns::DATE_FORMATS))],
            'options.on_match' => ['required', Rule::in(['skip', 'update'])],
            'options.blank_cells' => ['required', Rule::in(['keep', 'clear'])],
            'options.closed_clients' => ['required', Rule::in(['import', 'skip'])],
            'options.match_company' => ['boolean'],
            'options.match_tax_id' => ['boolean'],
            'options.create_logins' => ['boolean'],
            'options.trust_verified' => ['boolean'],
            'options.groups' => ['array', 'max:50'],
            'options.groups.*' => ['required', Rule::in(['create', 'none', ...ClientGroup::query()->pluck('id')->map(fn (mixed $id): string => (string) $id)->all()])],
            'options.currencies' => ['array', 'max:50'],
            'options.currencies.*' => ['required', Rule::in(['error', ...$currencies])],
        ], ['columns.*.required' => __('Choose a target for every column, or Ignore.')]);
        $validator->after(fn (Validator $validator) => $this->checkColumns($validator, $header));
        $data = $validator->validate();

        $options = [...$data['options'], 'groups' => $data['options']['groups'] ?? [], 'currencies' => array_filter($data['options']['currencies'] ?? [], fn (string $code): bool => $code !== 'error')];
        $start->handle($run, ['columns' => array_values($data['columns']), 'date_format' => $data['date_format'] ?? null], $options);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Checking :count rows. This page shows the progress.', ['count' => number_format($run->row_count)])]);

        return to_route('admin.migration.imports.show', $run);
    }

    /**
     * Continue a step that failed or stalled, from where it stopped.
     */
    public function resume(MigrationRun $run): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        if (! ($run->status === MigrationRun::FAILED || $run->isStalled())) {
            throw ValidationException::withMessages(['run' => __('This import is not stopped.')]);
        }

        $staging = $run->file_key !== null;
        $run->forceFill(['status' => $staging ? MigrationRun::STAGING : MigrationRun::CHECKING, 'error' => null, 'heartbeat_at' => now()])->save();
        dispatch($staging ? new StageCsvRun($run->id) : new CheckRun($run->id, $run->generation, $run->checked_count));
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Continuing :file where it stopped.', ['file' => $run->file_name])]);

        return to_route('admin.migration.imports.show', $run);
    }

    private function map(MigrationRun $run): Response
    {
        $header = $run->header ?? [];
        $samples = $this->samples($run, count($header));
        $earlier = MigrationRun::query()->with('requester:id,name')->where('migration_source_id', $run->migration_source_id)
            ->where('file_sha256', $run->file_sha256)->where('id', '<', $run->id)->latest('id')->first();
        $found = $run->found_values ?? [];

        return Inertia::render('admin/migration/map', [
            'run' => $this->summary($run),
            'columns' => array_map(fn (string $name, int $index): array => [
                'header' => $name,
                'samples' => $samples[$index] ?? [],
                'forbidden' => ClientColumns::isForbidden($name),
            ], $header, array_keys($header)),
            'mapping' => ['columns' => $run->mapping['columns'] ?? [], 'date_format' => $run->mapping['date_format'] ?? $this->guessDateFormat($run->mapping['columns'] ?? [], $samples)],
            'options' => [
                'on_match' => 'skip', 'blank_cells' => 'keep', 'closed_clients' => 'import', 'match_company' => false, 'match_tax_id' => false,
                'create_logins' => true, 'trust_verified' => false, ...array_diff_key($run->options ?? [], ['preset' => true]),
            ],
            'suggestions' => array_map(fn (string $preset): array => ClientColumns::suggest($header, $preset), array_combine(array_keys(ClientColumns::PRESETS), array_keys(ClientColumns::PRESETS))),
            'targets' => $this->options(ClientColumns::TARGETS),
            'required' => ClientColumns::REQUIRED,
            'dateTargets' => ClientColumns::DATES,
            'dateFormats' => $this->options(ClientColumns::DATE_FORMATS),
            'presets' => $this->options(ClientColumns::PRESETS),
            'found' => array_map(fn (array $values): array => array_map(fn (string $value, int $count): array => ['value' => $value, 'count' => $count], array_keys($values), $values), $found),
            'groupOptions' => ClientGroup::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'currencyOptions' => Currency::query()->orderBy('code')->pluck('code'),
            'result' => $run->status === MigrationRun::READY ? ['ready' => $run->create_count, 'errors' => $run->error_count, 'warnings' => $run->warning_count] : null,
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

    /**
     * Year-first dates are unambiguous, so their format is chosen for staff; day and month orders are left to them.
     *
     * @param  list<string|null>  $columns
     * @param  array<int, list<string>>  $samples
     */
    private function guessDateFormat(array $columns, array $samples): ?string
    {
        $dates = array_merge(...array_values(array_intersect_key($samples, array_filter($columns, fn (?string $target): bool => in_array($target, ClientColumns::DATES, true)))));

        return $dates !== [] && array_filter($dates, fn (string $date): bool => preg_match('/^\d{4}-\d{1,2}-\d{1,2}/', $date) !== 1) === [] ? 'Y-m-d' : null;
    }

    /**
     * Forbidden columns stay ignored, each single field is mapped once, and the required fields are mapped.
     *
     * @param  list<string>  $header
     */
    private function checkColumns(Validator $validator, array $header): void
    {
        $columns = (array) $validator->getData()['columns'];
        $counts = array_count_values(array_filter($columns, 'is_string'));

        foreach ($header as $index => $name) {
            if (ClientColumns::isForbidden($name) && ($columns[$index] ?? null) !== ClientColumns::IGNORE) {
                $validator->errors()->add("columns.{$index}", __('Passwords and card data cannot be imported. Clients set a password from their invitation.'));
            }
        }

        foreach ($counts as $target => $count) {
            if ($count > 1 && ! in_array($target, [ClientColumns::IGNORE, ClientColumns::IMPORTED_FIELD], true)) {
                $validator->errors()->add('columns', __(':target is chosen for more than one column.', ['target' => ClientColumns::TARGETS[$target] ?? $target]));
            }
        }

        if (($counts[ClientColumns::IMPORTED_FIELD] ?? 0) > ClientColumns::MAX_IMPORTED_FIELDS) {
            $validator->errors()->add('columns', __('Keep at most :max columns as imported fields.', ['max' => ClientColumns::MAX_IMPORTED_FIELDS]));
        }

        $missing = array_diff(ClientColumns::REQUIRED, array_keys($counts));

        if ($missing !== []) {
            $validator->errors()->add('columns', __('Map a column to each of: :fields.', ['fields' => implode(', ', array_map(fn (string $field): string => mb_strtolower(ClientColumns::TARGETS[$field]), $missing))]));
        }

        if (array_intersect(ClientColumns::DATES, array_keys($counts)) !== [] && ($validator->getData()['date_format'] ?? null) === null) {
            $validator->errors()->add('date_format', __('Choose how the dates in the file are written.'));
        }
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
