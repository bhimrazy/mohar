<?php

namespace App\Support\Reports;

use App\Contracts\Branding\BrandProvider;
use App\Contracts\Reports\ProvidesPdfView;
use App\Contracts\Reports\ProvidesTotals;
use App\Enums\ColumnType;
use App\Enums\ExportFormat;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The PDF export: the kernel view `reports.pdf`, A4 landscape, with a page total after every page of rows and the
 * grand totals at the end (ADR 0025 §3). The document is built in memory, which the synchronous row limit allows.
 */
final class PdfExportWriter implements ExportWriter
{
    private const ROWS_PER_PAGE = 28;

    public function __construct(private CellFormatter $cells, private BrandProvider $brand) {}

    public function format(): ExportFormat
    {
        return ExportFormat::Pdf;
    }

    public function contentType(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function write(ExportContext $context, $stream): void
    {
        $sums = array_fill_keys(array_map(fn ($column): string => $column->key, $context->columns), 0);
        $pages = [];
        $page = [];
        $pageSums = $sums;

        foreach ($context->report->rows($context->query) as $row) {
            $page[] = $this->display($context, $row);

            foreach ($context->columns as $column) {
                $amount = CellFormatter::summable($column->type, $row[$column->key] ?? null);
                $pageSums[$column->key] += $amount ?? 0;
                $sums[$column->key] += $amount ?? 0;
            }

            if (count($page) === self::ROWS_PER_PAGE) {
                $pages[] = ['rows' => $page, 'total' => $this->sumLine($context, $pageSums, __('Page total'))];
                $page = [];
                $pageSums = array_fill_keys(array_keys($sums), 0);
            }
        }

        if ($page !== [] || $pages === []) {
            $pages[] = ['rows' => $page, 'total' => $this->sumLine($context, $pageSums, __('Page total'))];
        }

        $totals = $context->report instanceof ProvidesTotals
            ? array_map(fn (array $row): array => $this->display($context, $row), $context->report->totals($context->query))
            : [$this->sumLine($context, $sums, __('Total'))];

        $view = $context->report instanceof ProvidesPdfView ? $context->report->pdfView() : null;

        fwrite($stream, Pdf::loadView('reports.pdf', [
            'brand' => $this->brand->brand()->name,
            'title' => $context->report->label(),
            'header' => $context->header,
            'columns' => $context->columns,
            'pages' => $pages,
            'totals' => $totals,
            'tableView' => $view,
            'report' => $context->report,
            'printedBy' => $context->printedBy,
            'printedAt' => $context->printedAt,
            'right' => array_map(fn ($column): bool => $column->type !== ColumnType::Text, $context->columns),
        ])->setPaper('a4', 'landscape')->output());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function display(ExportContext $context, array $row): array
    {
        $line = [];

        foreach ($context->columns as $column) {
            $line[$column->key] = $this->cells->display($column->type, $row[$column->key] ?? null);
        }

        return $line;
    }

    /**
     * @param  array<string, int>  $sums
     * @return array<string, string>
     */
    private function sumLine(ExportContext $context, array $sums, string $label): array
    {
        $line = [];
        $labelled = false;

        foreach ($context->columns as $column) {
            if (! $labelled && $column->type === ColumnType::Text) {
                $line[$column->key] = $label;
                $labelled = true;

                continue;
            }

            $line[$column->key] = match ($column->type) {
                ColumnType::Money => $this->cells->display($column->type, $sums[$column->key]),
                ColumnType::Integer => (string) $sums[$column->key],
                default => '',
            };
        }

        return $line;
    }
}
