<?php

namespace App\Support\Reports;

use App\Contracts\Branding\BrandProvider;
use App\Contracts\Reports\ProvidesPdfView;
use App\Contracts\Reports\ProvidesTotals;
use App\Enums\ColumnType;
use App\Enums\ExportFormat;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The PDF export: the kernel view `reports.pdf`, A4 landscape, with a page total after every page of rows and the
 * grand totals at the end (ADR 0025 §3). The document is built in memory before the response starts, which the PDF
 * row limit (`reports.sync_pdf_max_rows`) keeps within the production memory limit. Totals are kept per currency and
 * never added across currencies: a page or document with several currencies gets one total line for each.
 */
final class PdfExportWriter implements ExportWriter
{
    /** Rows per printed page; the first page holds fewer, by the lines of the header block. */
    private const ROWS_PER_PAGE = 24;

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

    public function buffered(): bool
    {
        return true;
    }

    public function write(ExportContext $context, $stream): void
    {
        $pages = [];
        $page = [];
        $allSums = new Sums;
        $pageSums = new Sums;
        $capacity = max(8, self::ROWS_PER_PAGE - count($context->header));

        foreach ($context->rows as $row) {
            $page[] = $this->display($context, $row);

            foreach ($context->columns as $column) {
                $pageSums->add($column->key, $column->type, $row[$column->key] ?? null);
                $allSums->add($column->key, $column->type, $row[$column->key] ?? null);
            }

            if (count($page) === $capacity) {
                $pages[] = ['rows' => $page, 'totals' => $this->sumLines($context, $pageSums, __('Page total'))];
                $page = [];
                $pageSums = new Sums;
                $capacity = self::ROWS_PER_PAGE;
            }
        }

        if ($page !== [] || $pages === []) {
            $pages[] = ['rows' => $page, 'totals' => $this->sumLines($context, $pageSums, __('Page total'))];
        }

        $totals = $context->report instanceof ProvidesTotals
            ? array_map(fn (array $row): array => $this->display($context, $row), $context->report->totals($context->query))
            : $this->sumLines($context, $allSums, __('Total'));

        fwrite($stream, Pdf::loadView('reports.pdf', [
            'brand' => $this->brand->brand()->name,
            'title' => $context->report->label(),
            'header' => $context->header,
            'columns' => $context->columns,
            'pages' => $pages,
            'totals' => $totals,
            'tableView' => $context->report instanceof ProvidesPdfView ? $context->report->pdfView() : null,
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
     * One line per currency present (a single "Total" line when there is one or none).
     *
     * @return list<array<string, string>>
     */
    private function sumLines(ExportContext $context, Sums $sums, string $label): array
    {
        $currencies = $sums->currencies() ?: [null];
        $lines = [];

        foreach ($currencies as $index => $currency) {
            $line = [];
            $labelled = false;

            foreach ($context->columns as $column) {
                if (! $labelled && $column->type === ColumnType::Text) {
                    $line[$column->key] = $label.(count($currencies) > 1 ? " ({$currency})" : '');
                    $labelled = true;

                    continue;
                }

                $line[$column->key] = match (true) {
                    $column->type === ColumnType::Money && $currency !== null && $sums->money($column->key, $currency) !== null => $this->cells->display($column->type, Money::of((int) $sums->money($column->key, $currency), $currency)),
                    $column->type === ColumnType::Integer && $index === 0 => (string) $sums->integer($column->key),
                    default => '',
                };
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
