<?php

namespace App\Modules\Billing\Http\Controllers\Admin;

use App\Contracts\Core\CompanyClock;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ExportFormat;
use App\Http\Controllers\Controller;
use App\Modules\Billing\Reports\RegisterPeriod;
use App\Modules\Billing\Reports\RegisterReport;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing → VAT register (vat-register §6, §8): the VAT sales register and the note register as two tabs, paged
 * through ReportViewer (which checks the view permission and fills the totals), and the CSV export through the
 * kernel exporter (export permission, step-up, row cap, `report.exported`). Both routes are rate-limited.
 */
class VatRegisterController extends Controller
{
    public const PER_PAGE = 50;

    private const TABS = ['register' => 'vat_register', 'notes' => 'tax_note_register'];

    private const FILTERS = ['month', 'year', 'from', 'to'];

    public function index(Request $request, ReportExporter $exporter, ReportViewer $viewer, ReportRegistry $reports, CompanyClock $clock): Response
    {
        $tab = $this->tab($request);
        $key = self::TABS[$tab];
        $this->authorize("reports.{$key}.view");

        $input = $this->filters($request);
        $errors = [];

        try {
            $query = $exporter->query($key, $input, $request->user()?->getAuthIdentifier());
        } catch (ValidationException $e) {
            // A bad period is shown beside the picker; the page falls back to the last closed month.
            $errors = array_map(fn (array $messages): string => $messages[0], $e->errors());
            $input = [];
            $query = $exporter->query($key, [], $request->user()?->getAuthIdentifier());
        }

        $current = max(1, (int) $request->query('page', '1'));
        $page = $viewer->page($key, $query, self::PER_PAGE, min(($current - 1) * self::PER_PAGE, ReportQuery::MAX_OFFSET));

        /** @var RegisterReport $report */
        $report = $reports->get($key);
        $period = $report->period($query);
        $summary = $report->summary($query);
        $user = $request->user();

        return Inertia::render('admin/billing/vat-register/index', [
            'tab' => $tab,
            'tabs' => array_values(array_filter(array_keys(self::TABS), fn (string $name): bool => (bool) $user?->hasPermission('reports.'.self::TABS[$name].'.view'))),
            'columns' => array_map(fn (ReportColumn $column): array => ['key' => $column->key, 'label' => $column->label, 'type' => $column->type->value], $page->columns),
            'rows' => (new LengthAwarePaginator(array_map($this->cells(...), $page->rows), $page->total, self::PER_PAGE, $current, [
                'path' => $request->url(),
                'query' => $request->except('page'),
            ]))->toArray(),
            'totals' => array_map($this->cells(...), $page->totals),
            'header' => $report->headerBlock($query),
            'period' => ['kind' => $period->kind, 'label' => $period->label(), 'from' => $period->from, 'to' => $period->to, 'months' => count($period->kind === 'year' ? $period->monthKeys() : [])],
            'filters' => (object) $input,
            'filterErrors' => (object) $errors,
            'options' => $this->options($clock),
            'warnings' => ['withoutPan' => $summary->withoutPan, 'unconverted' => $summary->unconverted],
            'can' => ['export' => (bool) $user?->hasPermission("reports.{$key}.export")],
        ]);
    }

    public function export(Request $request, ReportExporter $exporter): StreamedResponse
    {
        $key = self::TABS[$this->tab($request)];
        $this->authorize("reports.{$key}.export");

        return $exporter->export($key, $exporter->query($key, $this->filters($request), $request->user()?->getAuthIdentifier()), ExportFormat::Csv);
    }

    private function tab(Request $request): string
    {
        $tab = (string) $request->query('tab', 'register');

        return array_key_exists($tab, self::TABS) ? $tab : 'register';
    }

    /**
     * The period filters that were filled in.
     *
     * @return array<string, string>
     */
    private function filters(Request $request): array
    {
        return array_filter(array_map(fn (mixed $value): string => is_string($value) ? trim($value) : '', $request->only(self::FILTERS)), fn (string $value): bool => $value !== '');
    }

    /**
     * The BS months and fiscal years the picker offers: the last 24 months up to the current one, newest first.
     *
     * @return array{months: list<array{value: string, label: string}>, years: list<array{value: string, label: string}>, lastClosed: string}
     */
    private function options(CompanyClock $clock): array
    {
        $today = BikramSambat::fromAd($clock->todayDate());
        [$year, $month] = [$today->year, $today->month];
        $months = [];
        $years = [];

        for ($i = 0; $i < 24 && $year >= BikramSambat::FIRST_YEAR; $i++) {
            $months[] = ['value' => sprintf('%d-%02d', $year, $month), 'label' => BikramSambat::MONTHS[$month - 1].' '.$year];
            $start = $month >= 4 ? $year : $year - 1;
            $years[$start] = ['value' => sprintf('%d.%03d', $start, ($start + 1) % 1000), 'label' => sprintf('%d/%02d', $start, ($start + 1) % 100)];
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
