<?php

namespace App\Support\Reports;

use App\Contracts\Core\CompanyClock;
use App\Contracts\Reports\ConfiguresExport;
use App\Contracts\Reports\ProvidesHeaderBlock;
use App\Contracts\Reports\ProvidesLayouts;
use App\Contracts\Reports\Report;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ExportFormat;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The only export path (ADR 0025 §3): every report, whoever owns it, is exported here. It checks the permission
 * `reports.{key}.export` (and a recent password confirmation when the report's export is sensitive), refuses an
 * export over `reports.sync_export_max_rows`, writes one `report.exported` activity entry and streams the file.
 * Synchronous only in this build; queued delivery comes with the Reports module.
 *
 * @api
 */
final class ReportExporter
{
    public function __construct(
        private ReportRegistry $reports,
        private CompanyClock $clock,
        private ActivityLogger $audit,
        private Settings $settings,
    ) {}

    /**
     * Validates the input against the report's filters and resolves the period on the company calendar.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function query(string $key, array $input, ?int $userId): ReportQuery
    {
        $rules = [];

        foreach ($this->reports->get($key)->filters() as $filter) {
            $rules[$filter->key] = $filter->rules;
        }

        $validated = Validator::make($input, $rules)->validate();

        return ReportQuery::resolve($validated, $userId, $this->clock);
    }

    /**
     * @throws AuthorizationException when the acting user may not export the report
     * @throws ValidationException when the export is over the row limit or the layout is unknown
     */
    public function export(string $key, ReportQuery $query, ExportFormat $format, ?string $layout = null): StreamedResponse
    {
        $report = $this->reports->get($key);
        $user = $this->authorize($report, $query);

        $layout = $this->layout($report, $layout);
        $rows = $report->count($query);
        $max = (int) $this->settings->get('reports.sync_export_max_rows', 5000);

        if ($rows > $max) {
            throw ValidationException::withMessages(['export' => __('This report has :rows rows; a file can hold up to :max. Narrow the filters (a shorter period) and export again.', ['rows' => number_format($rows), 'max' => number_format($max)])]);
        }

        $writer = $this->writer($format);
        $context = $this->context($report, $query, $layout, $user);

        $this->audit->log('Report exported', null, properties: [
            'report' => $key,
            'layout' => $layout,
            'format' => $format->value,
            'filters' => $query->filters,
            'rows' => $rows,
        ], event: 'report.exported', actor: $user);

        $name = $key.($layout !== null ? '-'.$layout : '').'-'.$this->clock->now()->format('Y-m-d').'.'.$writer->extension();

        return new StreamedResponse(function () use ($writer, $context): void {
            $out = fopen('php://output', 'wb');

            if ($out !== false) {
                $writer->write($context, $out);
                fclose($out);
            }
        }, 200, [
            'Content-Type' => $writer->contentType(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, 'report.'.$writer->extension()),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function authorize(Report $report, ReportQuery $query): User
    {
        $user = $query->userId === null ? null : User::query()->find($query->userId);

        if (! $user?->hasPermission("reports.{$report->key()}.export")) {
            throw new AuthorizationException(__('You do not have permission to export this report.'));
        }

        if ($report instanceof ConfiguresExport && $report->sensitiveExport() && ! RequireRecentPasswordConfirmation::recentlyConfirmed(request())) {
            throw new HttpException(423, __('Password confirmation required.'));
        }

        return $user;
    }

    private function layout(Report $report, ?string $layout): ?string
    {
        if (! $report instanceof ProvidesLayouts) {
            return null;
        }

        $layouts = array_keys($report->layouts());
        $layout ??= $layouts[0] ?? null;

        if (! in_array($layout, $layouts, true)) {
            throw new InvalidArgumentException('Unknown layout ['.$layout.'] for report ['.$report->key().'].');
        }

        return $layout;
    }

    private function writer(ExportFormat $format): ExportWriter
    {
        return match ($format) {
            ExportFormat::Csv => app(CsvExportWriter::class),
            ExportFormat::Pdf => app(PdfExportWriter::class),
        };
    }

    private function context(Report $report, ReportQuery $query, ?string $layout, User $user): ExportContext
    {
        /** @var list<ReportColumn> $columns */
        $columns = $report instanceof ProvidesLayouts && $layout !== null ? $report->layoutColumns($layout, $query) : $report->columns($query);
        $bom = $report instanceof ConfiguresExport ? $report->csvBom() : null;

        return new ExportContext(
            $report,
            $query,
            $columns,
            $layout,
            $report instanceof ProvidesHeaderBlock ? $report->headerBlock($query) : [],
            $bom ?? (bool) $this->settings->get('reports.csv_bom', false),
            $user->name,
            $this->clock->now()->format('Y-m-d H:i').' ('.$this->clock->timezone().')',
        );
    }
}
