<?php

namespace App\Modules\Migration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Core\Models\Currency;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Actions\ApplyRun;
use App\Modules\Migration\Actions\DecideRows;
use App\Modules\Migration\Actions\StartCheck;
use App\Modules\Migration\Actions\UploadClientFile;
use App\Modules\Migration\Jobs\CheckRun;
use App\Modules\Migration\Jobs\StageCsvRun;
use App\Modules\Migration\Models\MigrationRecord;
use App\Modules\Migration\Models\MigrationRun;
use App\Modules\Migration\Models\MigrationSource;
use App\Modules\Migration\Support\ClientColumns;
use App\Modules\Migration\Support\CsvFile;
use App\Modules\Migration\Support\ErrorsCsv;
use App\Support\Queues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Clients → Import clients (client-import spec §5, §14): upload a file, map its columns, and check every row.
 * Pages read counts from the run and decrypt only the rows they show.
 */
class ClientImportController extends Controller
{
    private const PER_PAGE = 50;

    public const TABS = ['all', 'create', 'update', 'unchanged', 'skip', 'needs_decision', 'error'];

    /** Tabs once the import ran: by outcome, the rows not imported on their own tab. */
    public const OUTCOME_TABS = ['all', 'created', 'updated', 'unchanged', 'skipped', 'failed'];

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

    public function show(Request $request, MigrationRun $run): Response
    {
        $this->authorize('migration.runs.view');

        $checked = in_array($run->status, [MigrationRun::CHECKING, MigrationRun::READY, MigrationRun::APPLYING, MigrationRun::COMPLETED], true)
            || ($run->status === MigrationRun::FAILED && $run->file_key === null && $run->checked_count > 0);

        return $checked && $request->query('step') !== 'map' ? $this->check($request, $run) : $this->map($run);
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
     * Confirm the reviewed rows (ApplyRun). Tax-exempt rows need their own acknowledgement.
     */
    public function apply(Request $request, MigrationRun $run, ApplyRun $apply): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        $data = $request->validate([
            'digest' => ['required', 'string', 'size:64'],
            'tax_exempt' => [$run->tax_exempt_count > 0 ? 'accepted' : 'nullable'],
        ], [
            'digest.required' => __('Review the import before confirming it.'),
            'tax_exempt.accepted' => __('Confirm that the tax-exempt clients will be invoiced without tax.'),
        ]);
        /** @var User $user */
        $user = $request->user();
        $apply->start($run, (string) $data['digest'], $user);
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Importing :file. This page shows the progress.', ['file' => $run->file_name])]);

        return to_route('admin.migration.imports.show', $run);
    }

    /**
     * Staff choose what one row does (DecideRows): create, update the matched client, or skip.
     */
    public function decide(Request $request, MigrationRun $run, DecideRows $decide): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        $data = $request->validate(['row' => ['required', 'integer', 'min:1'], 'decision' => ['required', Rule::in(['create', 'update', 'skip'])]]);
        $decide->one($run, (int) $data['row'], (string) $data['decision']);

        return back();
    }

    /**
     * Accept every safe proposal; the toast says how many flagged rows were left for staff.
     */
    public function accept(MigrationRun $run, DecideRows $decide): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        ['accepted' => $accepted, 'left' => $left] = $decide->acceptProposed($run);
        Inertia::flash('toast', ['type' => $left > 0 ? 'info' : 'success', 'message' => trim(trans_choice('{0} No proposal to accept.|{1} 1 proposed row accepted.|[2,*] :count proposed rows accepted.', $accepted, ['count' => number_format($accepted)])
            .' '.trans_choice('{0}|{1} 1 row is left for you: its match is closed, shared by several clients or changed email recently.|[2,*] :count rows are left for you: their matches are closed, shared by several clients or changed email recently.', $left, ['count' => number_format($left)]))]);

        return back();
    }

    public function skipUndecided(MigrationRun $run, DecideRows $decide): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        $count = $decide->skipUndecided($run);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('1 row will be skipped.|:count rows will be skipped.', $count, ['count' => number_format($count)])]);

        return back();
    }

    /**
     * The rows that were not imported, as CSV, generated now and never stored (ErrorsCsv).
     */
    public function errors(MigrationRun $run, ActivityLogger $audit): StreamedResponse
    {
        $this->authorize('migration.runs.execute');

        $audit->log('Client import errors downloaded', $run, properties: ['file' => $run->file_name], event: 'migration.errors_downloaded');

        return response()->streamDownload(function () use ($run): void {
            $out = fopen('php://output', 'wb');

            if ($out !== false) {
                ErrorsCsv::write($run, $out);
                fclose($out);
            }
        }, pathinfo($run->file_name, PATHINFO_FILENAME).'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Continue a step that failed or stalled, from where it stopped.
     */
    public function resume(MigrationRun $run, ApplyRun $apply): RedirectResponse
    {
        $this->authorize('migration.runs.execute');

        if ($run->applied_at !== null) {
            // Applying goes through the one place that keeps imports one at a time.
            $apply->resume($run);
        } else {
            $staging = $run->file_key !== null;

            if ($run->purged_at !== null || ! $run->claimStopped($staging ? MigrationRun::STAGING : MigrationRun::CHECKING)) {
                throw ValidationException::withMessages(['run' => __('This import is not stopped.')]);
            }

            dispatch($staging ? new StageCsvRun($run->id) : new CheckRun($run->id, $run->generation, $run->checked_count));
        }

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
            'earlier' => $earlier === null ? null : ['name' => $earlier->file_name, 'uploaded_at' => $earlier->created_at?->toIso8601String(), 'by' => $earlier->requester?->name, 'href' => route('admin.migration.imports.show', $earlier)],
        ]);
    }

    private function check(Request $request, MigrationRun $run): Response
    {
        $applied = $run->applied_at !== null;
        $tabs = $applied ? self::OUTCOME_TABS : self::TABS;
        $tab = in_array($tab = $request->string('tab')->toString(), $tabs, true) ? $tab : 'all';
        $search = trim(mb_substr($request->string('search')->toString(), 0, 255));
        $rows = $run->records()
            ->when($applied && $tab !== 'all', fn ($query) => $query->where('outcome', $tab))
            ->when(! $applied && $tab === 'error', fn ($query) => $query->where('status', 'error'))
            ->when(! $applied && ! in_array($tab, ['all', 'error'], true), fn ($query) => $query->where('decision', $tab))
            ->when($search !== '', fn ($query) => ctype_digit(str_replace(',', '', $search))
                ? $query->where('row_number', (int) str_replace(',', '', $search))
                : $query->where('email_hash', MigrationRecord::emailHash($search)))
            ->with('matchedClient:id,first_name,last_name,company_name')
            ->orderBy('row_number')
            ->paginate(self::PER_PAGE, ['id', 'migration_run_id', 'row_number', 'normalized', 'status', 'decision', 'match_client_id', 'problems', 'changes', 'outcome', 'target_id'])
            ->withQueryString()
            ->through(fn (MigrationRecord $record): array => [
                'row' => $record->row_number,
                'name' => trim(($record->normalized['client']['first_name'] ?? '').' '.($record->normalized['client']['last_name'] ?? '')),
                'company' => $record->normalized['client']['company_name'] ?? null,
                'email' => $record->normalized['client']['email'] ?? null,
                'status' => $record->status,
                'decision' => $record->decision,
                'problems' => array_map(fn (array $problem): array => ['severity' => $problem['severity'], 'message' => $problem['message']], $record->problems ?? []),
                'changes' => array_map(fn (string $field): string => ClientColumns::TARGETS[$field === 'client_group_id' ? 'client_group' : $field] ?? $field, $record->changes ?? []),
                'match' => $record->matchedClient === null ? null : ['name' => $record->matchedClient->display_name, 'href' => route('admin.clients.show', $record->matchedClient)],
                'choices' => $run->status === MigrationRun::READY ? DecideRows::choices($record) : [],
                'outcome' => $record->outcome,
                'client_href' => in_array($record->outcome, ['created', 'updated'], true) && $record->target_id !== null ? route('admin.clients.show', $record->target_id) : null,
            ]);

        return Inertia::render('admin/migration/check', [
            'run' => $this->summary($run),
            'errorsUrl' => $run->error_count + $run->failed_count > 0 && $request->user()?->hasPermission('migration.runs.execute') ? route('admin.migration.imports.errors', $run) : null,
            'counts' => [
                'all' => $run->row_count, 'create' => $run->create_count, 'update' => $run->update_count, 'unchanged' => $run->unchanged_count,
                'skip' => $run->skip_count, 'needs_decision' => $run->decision_count, 'error' => $run->error_count, 'tax_exempt' => $run->tax_exempt_count,
            ],
            'rows' => $rows,
            'filters' => ['tab' => $tab, 'search' => $search],
            'matchesByEmail' => ! in_array(ClientColumns::EXTERNAL_ID, $run->mapping['columns'] ?? [], true),
            'digest' => $run->status === MigrationRun::READY ? $run->review_digest : null,
            'report' => $run->applied_at === null ? null : [
                'created' => $run->created_count, 'updated' => $run->updated_count, 'skipped' => $run->skipped_count, 'failed' => $run->failed_count,
                'unchanged' => $run->records()->where('outcome', 'unchanged')->count(),
                'applied_by' => $run->applier?->name, 'applied_at' => $run->applied_at->toIso8601String(), 'undo_until' => $run->undo_until?->toIso8601String(),
            ],
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
            'applied' => $run->status === MigrationRun::APPLYING ? $run->records()->whereNotNull('outcome')->count() : 0,
            'running' => $run->isRunning(),
            'stalled' => $run->isStalled(),
            'error' => $run->error,
            'purged' => $run->purged_at !== null,
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
