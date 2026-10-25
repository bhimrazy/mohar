<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Contracts\Core\CompanyClock;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ExportFormat;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Reports\RegisterPeriod;
use App\Modules\Billing\Reports\RegisterReport;
use App\Modules\Billing\Services\NepalVatMode;
use App\Modules\Billing\Support\BikramSambat;
use App\Support\Money;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use App\Support\Reports\ReportViewer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing → VAT register (vat-register §6, §8): the VAT sales register and the note register as two tabs, paged
 * through ReportViewer (which checks the view permission and fills the totals), and the exports through the kernel
 * exporter (export permission, step-up, row cap, `report.exported`). Both routes are rate-limited.
 *
 * Exports offered (vat-register §7, D4): CSV in the `detail` layout, CSV in the `ird` layout (the VAT register only)
 * and a PDF of the `summary` layout, which fits A4 landscape.
 */
class VatRegisterController extends Controller
{
    public const PER_PAGE = 50;

    private const TABS = ['register' => 'vat_register', 'notes' => 'tax_note_register'];

    private const FILTERS = ['month', 'year', 'from', 'to'];

    public function index(Request $request, ReportExporter $exporter, ReportViewer $viewer, ReportRegistry $reports, CompanyClock $clock, NepalVatMode $mode): Response
    {
        $tab = $this->tab($request);
        $key = self::TABS[$tab];
        $this->authorize("reports.{$key}.view");

        $input = $this->filters($request, $clock);
        $submitted = $input;
        $errors = [];

        try {
            $query = $exporter->query($key, $input, $request->user()?->getAuthIdentifier());
        } catch (ValidationException $e) {
            // A bad period is shown beside the picker; the page falls back to the last closed month.
            $errors = array_map(fn (array $messages): string => $messages[0], $e->errors());
            $input = $this->filters(new Request, $clock);
            $query = $exporter->query($key, $input, $request->user()?->getAuthIdentifier());
        }

        $lastPage = intdiv(ReportQuery::MAX_OFFSET, self::PER_PAGE) + 1;
        $current = min(max(1, (int) $request->query('page', '1')), $lastPage);
        $page = $viewer->page($key, $query, self::PER_PAGE, ($current - 1) * self::PER_PAGE);

        // A page past the end shows the last page, not an empty period.
        if ($page->rows === [] && $page->total > 0) {
            $current = min((int) ceil($page->total / self::PER_PAGE), $lastPage);
            $page = $viewer->page($key, $query, self::PER_PAGE, ($current - 1) * self::PER_PAGE);
        }

        /** @var RegisterReport $report */
        $report = $reports->get($key);
        $period = $report->period($query);
        $summary = $report->summary($query);
        $user = $request->user();

        return Inertia::render('admin/billing/vat-register/index', [
            'tab' => $tab,
            'tabs' => array_values(array_filter(array_keys(self::TABS), fn (string $name): bool => (bool) $user?->hasPermission('reports.'.self::TABS[$name].'.view'))),
            'columns' => array_map(fn (ReportColumn $column): array => ['key' => $column->key, 'label' => $column->label, 'type' => $column->type->value], $page->columns),
            // Pages beyond the viewer's offset limit are not offered; the page says to narrow the period instead.
            'rows' => (new LengthAwarePaginator(array_map($this->cells(...), $page->rows), min($page->total, $lastPage * self::PER_PAGE), self::PER_PAGE, $current, [
                'path' => $request->url(),
                'query' => $request->except('page'),
            ]))->toArray(),
            'totals' => array_map($this->cells(...), $page->totals),
            'header' => $report->headerBlock($query),
            'period' => ['kind' => $period->kind, 'label' => $period->label(), 'from' => $period->from, 'to' => $period->to, 'months' => count($period->kind === 'year' ? $period->monthKeys() : [])],
            'documents' => ['total' => $page->total, 'pageable' => $lastPage * self::PER_PAGE, 'hasExport' => $summary->hasExport(), 'currency' => $summary->currency],
            'filters' => (object) $input,
            'filterErrors' => (object) $errors,
            // What was asked for, so a refused year or range stays in the picker beside its error.
            'submitted' => (object) ($errors === [] ? [] : $submitted),
            'options' => $this->options($clock, $mode->periods()[0]['from'] ?? null),
            // The register lists documents from the mode's start day on; with the mode never switched on there is nothing to list.
            'mode' => [
                // Ever on: the register has something to show. `on` is the switch now; `offSince` the day the last stretch ended.
                'enabled' => $mode->periods() !== [],
                'on' => $mode->enabled(),
                'from' => $mode->periods()[0]['from'] ?? null,
                'started' => $mode->inEffect($clock->now()),
                'offSince' => $mode->enabled() ? null : (collect($mode->periods())->last()['to'] ?? null),
            ],
            'warnings' => ['withoutPan' => $summary->withoutPan, 'unconverted' => $summary->unconverted, 'unclassified' => $summary->unclassified],
            'can' => ['export' => (bool) $user?->hasPermission("reports.{$key}.export")],
            'exports' => $user?->hasPermission("reports.{$key}.export") ? $this->exports($report) : [],
        ]);
    }

    public function export(Request $request, ReportExporter $exporter, ReportRegistry $reports, CompanyClock $clock): StreamedResponse
    {
        $key = self::TABS[$this->tab($request)];
        $this->authorize("reports.{$key}.export");

        /** @var RegisterReport $report */
        $report = $reports->get($key);
        $format = $request->query('format', 'csv');
        $format = is_string($format) ? ExportFormat::tryFrom($format) : null;
        // A PDF is always the summary: the other layouts have more columns than an A4 landscape page holds.
        $layout = $format === ExportFormat::Pdf ? 'summary' : $request->query('layout', 'detail');

        if ($format === null || ! is_string($layout) || ! array_key_exists($layout, $report->layouts())) {
            throw ValidationException::withMessages(['export' => __('This export does not exist.')]);
        }
        $query = $exporter->query($key, $this->filters($request, $clock), $request->user()?->getAuthIdentifier());
        $response = $exporter->export($key, $query, $format, $layout);
        $name = str_replace('_', '-', $key).($layout === 'ird' ? '-ird' : '').'-'.$report->period($query)->slug().'.'.$format->value;
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $name));

        return $response;
    }

    /**
     * The exports the page offers, each with the query that selects it.
     *
     * @return list<array{label: string, format: string, layout: string}>
     */
    private function exports(RegisterReport $report): array
    {
        $exports = [['label' => __('CSV (detail)'), 'format' => ExportFormat::Csv->value, 'layout' => 'detail']];

        if (array_key_exists('ird', $report->layouts())) {
            $exports[] = ['label' => __('CSV (IRD sales book)'), 'format' => ExportFormat::Csv->value, 'layout' => 'ird'];
        }

        $exports[] = ['label' => __('PDF'), 'format' => ExportFormat::Pdf->value, 'layout' => 'summary'];

        return $exports;
    }

    private function tab(Request $request): string
    {
        $tab = (string) $request->query('tab', 'register');

        return array_key_exists($tab, self::TABS) ? $tab : 'register';
    }

    /**
     * The period filters that were filled in, or the last closed BS month, so an export always records its period.
     *
     * @return array<string, string>
     */
    private function filters(Request $request, CompanyClock $clock): array
    {
        $filters = array_filter(array_map(fn (mixed $value): string => is_string($value) ? trim($value) : '', $request->only(self::FILTERS)), fn (string $value): bool => $value !== '');

        return $filters !== [] ? $filters : ['month' => (string) RegisterPeriod::lastClosedMonth($clock)->firstMonth];
    }

    /**
     * The BS months and fiscal years the picker offers: the last 24 months up to the current one, newest first.
     *
     * @return array{months: list<array{value: string, label: string}>, years: list<array{value: string, label: string}>, lastClosed: string}
     */
    private function options(CompanyClock $clock, ?string $from = null): array
    {
        $today = BikramSambat::fromAd($clock->todayDate());
        [$year, $month] = [$today->year, $today->month];
        $months = [];
        $years = [];

        for ($i = 0; $i < 24 && $year >= BikramSambat::FIRST_YEAR; $i++) {
            // Periods that ended before the mode's start day are not offered (vat-register §6.1).
            if ($from === null || BikramSambat::toAd($year, $month, BikramSambat::daysInMonth($year, $month))->format('Y-m-d') >= $from) {
                $months[] = ['value' => sprintf('%d-%02d', $year, $month), 'label' => BikramSambat::MONTHS[$month - 1].' '.$year];
            }
            $start = $month >= 4 ? $year : $year - 1;
            if ($from === null || BikramSambat::toAd($start + 1, 3, BikramSambat::daysInMonth($start + 1, 3))->format('Y-m-d') >= $from) {
                $years[$start] = ['value' => sprintf('%d.%03d', $start, ($start + 1) % 1000), 'label' => sprintf('%d/%02d', $start, ($start + 1) % 100)];
            }
            [$year, $month] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
        }

        $closed = RegisterPeriod::lastClosedMonth($clock);

        return ['months' => $months, 'years' => array_values($years), 'lastClosed' => (string) $closed->firstMonth];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function cells(array $row): array
    {
        return array_map(fn (mixed $cell): mixed => match (true) {
            $cell instanceof Money => ['amount' => $cell->amount, 'currency' => $cell->currency],
            $cell instanceof CarbonImmutable => $cell->format('Y-m-d'),
            default => $cell,
        }, $row);
    }
}
