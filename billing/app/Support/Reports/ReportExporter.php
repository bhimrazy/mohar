<?php

namespace App\Support\Reports;

use App\Contracts\Core\CompanyClock;
use App\Contracts\Reports\HasSensitiveExport;
use App\Contracts\Reports\ProvidesCsvOptions;
use App\Contracts\Reports\ProvidesHeaderBlock;
use App\Contracts\Reports\ProvidesLayouts;
use App\Contracts\Reports\Report;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ExportFormat;
use App\Enums\FilterType;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The only export path (ADR 0025 §3): every report, whoever owns it, is exported here. It checks the permission
 * `reports.{key}.export` (and a recent password confirmation when the report's export is sensitive), refuses an
 * export over the row limit (`reports.sync_export_max_rows` for CSV, `reports.sync_pdf_max_rows` for PDF), never
 * writes more rows than that limit even when the report's count() is wrong, writes one `report.exported` activity
 * entry and returns the file. Synchronous only in this build; a queued export will pass the user in the query and their stored confirmation time.
 *
 * Audit timing: a CSV is streamed, so its entry is written before the first byte (an attempted export of tax data
 * is on record even if the connection drops) and records the most rows that can be written, min(count, limit); the
 * activity log is append-only, so it cannot be corrected afterwards. A PDF is built completely first, so its entry is
 * written after the build and records the rows actually written, and a failed build leaves no entry and returns an
 * error response, not a broken 200.
 *
 * @api
 */
final class ReportExporter
{
    /** @var array<string, ExportWriter> */
    private array $writers = [];

    public function __construct(
        private ReportRegistry $reports,
        private CompanyClock $clock,
        private ActivityLogger $audit,
        private Settings $settings,
        CsvExportWriter $csv,
        PdfExportWriter $pdf,
    ) {
        foreach ([$csv, $pdf] as $writer) {
            $this->writers[$writer->format()->value] = $writer;
        }
    }

    /**
     * Validates the input against the report's filters and resolves the period on the company calendar.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException for an unknown report, an unknown filter or a value that does not fit its filter
     */
    public function query(string $key, array $input, ?int $userId): ReportQuery
    {
        $filters = $this->report($key)->filters();
        $names = array_map(fn ($filter): string => $filter->key, $filters);
        $rules = [];

        foreach ($filters as $filter) {
            $rules[$filter->key] = $filter->validationRules();

            if ($filter->key === 'to' && $filter->type === FilterType::Date && in_array('from', $names, true)) {
                $rules['to'][] = 'after_or_equal:from';
            }
        }

        $unknown = array_diff(array_keys($input), $names);

        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_map('strval', $unknown), __('This filter does not exist.')));
        }

        return ReportQuery::resolve(Validator::make($input, $rules)->validate(), $userId, $this->clock);
    }

    /**
     * The permission and the step-up are decided for the user in the query.
     *
     * `$passwordConfirmedAt` (unix time) is for an export that runs with no request. It must be a stored confirmation:
     * the session's `auth.password_confirmed_at`, read when the export was queued. A synchronous caller must not pass
     * `time()`, which would confirm nothing. Without it, the current request must be that user's and recently confirmed.
     *
     * @throws AuthorizationException when the acting user may not export the report
     * @throws ValidationException when the report or layout is unknown or the export is over the row limit
     * @throws HttpException 423 when the export is sensitive and the query's user did not confirm the password recently
     */
    public function export(string $key, ReportQuery $query, ExportFormat $format, ?string $layout = null, ?int $passwordConfirmedAt = null): StreamedResponse
    {
        $report = $this->report($key);
        $query = $query->unpaged();
        $user = $this->authorize($report, $query, $passwordConfirmedAt);
        $layout = $this->layout($report, $layout);
        $writer = $this->writers[$format->value];

        $limit = $this->limit($format);
        $counted = $report->count($query);

        if ($counted > $limit) {
            throw ValidationException::withMessages(['export' => $format === ExportFormat::Pdf
                ? __('This report has :rows rows; a PDF can hold up to :max. Narrow the filters (a shorter period), or export the CSV.', ['rows' => number_format($counted), 'max' => number_format($limit)])
                : __('This report has :rows rows; a file can hold up to :max. Narrow the filters (a shorter period) and export again.', ['rows' => number_format($counted), 'max' => number_format($limit)])]);
        }

        $rows = new CappedRows($report->rows($query), $limit, $key, $counted);
        $context = $this->context($report, $query, $layout, $user, $rows);
        $name = $key.($layout !== null ? '-'.$layout : '').'-'.$this->clock->now()->format('Y-m-d').'.'.$writer->extension();

        if ($writer->buffered()) {
            $buffer = fopen('php://temp', 'w+b') ?: throw new RuntimeException('Could not open a temporary stream.');
            $writer->write($context, $buffer);
            rewind($buffer);
            $content = (string) stream_get_contents($buffer);
            fclose($buffer);

            $this->log($key, $layout, $format, $query, $rows->written, $user);

            return $this->response($name, $writer, function () use ($content): void {
                echo $content;
            });
        }

        $this->log($key, $layout, $format, $query, $counted, $user);

        return $this->response($name, $writer, function () use ($writer, $context): void {
            $out = fopen('php://output', 'wb');

            if ($out !== false) {
                $writer->write($context, $out);
                fclose($out);
            }
        });
    }

    private function report(string $key): Report
    {
        if (! $this->reports->has($key)) {
            throw ValidationException::withMessages(['report' => __('This report does not exist.')]);
        }

        return $this->reports->get($key);
    }

    private function limit(ExportFormat $format): int
    {
        return match ($format) {
            ExportFormat::Pdf => (int) $this->settings->get('reports.sync_pdf_max_rows', 1000),
            default => (int) $this->settings->get('reports.sync_export_max_rows', 5000),
        };
    }

    private function response(string $name, ExportWriter $writer, callable $body): StreamedResponse
    {
        return new StreamedResponse($body, 200, [
            'Content-Type' => $writer->contentType(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $name),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function log(string $key, ?string $layout, ExportFormat $format, ReportQuery $query, int $rows, User $user): void
    {
        $loggable = [];

        foreach ($this->reports->get($key)->filters() as $filter) {
            $loggable[$filter->key] = $filter->loggable;
        }

        $filters = [];

        foreach ($query->filters as $name => $value) {
            $filters[$name] = ($loggable[$name] ?? false) ? $value : '[not logged]';
        }

        $this->audit->log('Report exported', null, properties: [
            'report' => $key,
            'layout' => $layout,
            'format' => $format->value,
            'filters' => $filters,
            'rows' => $rows,
        ], event: 'report.exported', actor: $user);
    }

    /**
     * The permission and the step-up are both decided for the query's user (#354). The step-up reads the timestamp
     * the caller passes (a queued export captures it when it is queued), else the current request's session, and
     * only when that request belongs to the same user; anything else is not a confirmation.
     */
    private function authorize(Report $report, ReportQuery $query, ?int $passwordConfirmedAt): User
    {
        $user = ReportAccess::authorize($report->key(), $query, 'export', __('You do not have permission to export this report.'));

        if ($report instanceof HasSensitiveExport && ! $this->confirmed($user, $passwordConfirmedAt)) {
            throw new HttpException(423, __('Password confirmation required.'));
        }

        return $user;
    }

    private function confirmed(User $user, ?int $passwordConfirmedAt): bool
    {
        if ($passwordConfirmedAt !== null) {
            return RequireRecentPasswordConfirmation::confirmedFor($user, $passwordConfirmedAt);
        }

        $request = request();

        return $request->user()?->is($user) === true && RequireRecentPasswordConfirmation::recentlyConfirmed($request);
    }

    private function layout(Report $report, ?string $layout): ?string
    {
        if (! $report instanceof ProvidesLayouts) {
            return null;
        }

        $layouts = array_keys($report->layouts());
        $layout ??= $layouts[0] ?? null;

        if (! in_array($layout, $layouts, true)) {
            throw ValidationException::withMessages(['layout' => __('This layout does not exist.')]);
        }

        return $layout;
    }

    private function context(Report $report, ReportQuery $query, ?string $layout, User $user, CappedRows $rows): ExportContext
    {
        /** @var list<ReportColumn> $columns */
        $columns = $report instanceof ProvidesLayouts && $layout !== null ? $report->layoutColumns($layout, $query) : $report->columns($query);

        return new ExportContext(
            $report,
            $query,
            $columns,
            $layout,
            $report instanceof ProvidesHeaderBlock ? $report->headerBlock($query) : [],
            $report instanceof ProvidesCsvOptions ? $report->csvBom() : (bool) $this->settings->get('reports.csv_bom', false),
            $user->name,
            $this->clock->now()->format('Y-m-d H:i').' ('.$this->clock->timezone().')',
            $rows,
        );
    }
}
