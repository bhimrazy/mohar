<?php

namespace App\Support\Reports;

use App\Contracts\Branding\BrandProvider;
use App\Contracts\Reports\ProvidesPdfView;
use App\Contracts\Reports\ProvidesTotals;
use App\Data\Reports\ReportColumn;
use App\Enums\ColumnType;
use App\Enums\ExportFormat;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The PDF export: the kernel view `reports.pdf`, A4 landscape, with a page total after every page of rows and the
 * grand totals at the end (ADR 0025 §3). The document is built in memory before the response starts, which the PDF
 * row limit (`reports.sync_pdf_max_rows`) keeps within the production memory limit. Totals are kept per currency and
 * never added across currencies: a page or document with several currencies gets one total line for each.
 *
 * Long text wraps inside its column (a legal name is never cut mid-word with nothing to show it) up to
 * MAX_CELL_LINES lines, then ends in an ellipsis. The writer knows each column's width, so it can count the lines a row
 * takes and split pages by lines, not by rows.
 */
final class PdfExportWriter implements ExportWriter
{
    /** Rows per printed page; the first page holds fewer, by the lines of the header block. */
    private const ROWS_PER_PAGE = 24;

    /** A long text cell wraps onto at most this many lines and then ends in an ellipsis, so a row has a known height. */
    private const MAX_CELL_LINES = 4;

    /** Printable width of the A4 landscape page in CSS pixels, and a cautious width of one character at 9px. */
    private const PAGE_WIDTH = 1074;

    private const CHAR_WIDTH = 5.0;

    private const CELL_PADDING = 6;

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
        $used = 0;
        $widths = $this->widths($context->columns);

        foreach ($context->rows as $row) {
            $line = $this->display($context, $row, $widths);
            $height = $this->height($context, $line, $widths);

            // A row that does not fit the rest of the page starts the next one.
            if ($page !== [] && $used + $height > $capacity) {
                $pages[] = ['rows' => $page, 'totals' => $this->sumLines($context, $pageSums, __('Page total'))];
                $page = [];
                $pageSums = new Sums;
                $capacity = self::ROWS_PER_PAGE;
                $used = 0;
            }

            $page[] = $line;
            $used += $height;

            foreach ($context->columns as $column) {
                $pageSums->add($column->key, $column->type, $row[$column->key] ?? null);
                $allSums->add($column->key, $column->type, $row[$column->key] ?? null);
            }
        }

        if ($page !== [] || $pages === []) {
            $pages[] = ['rows' => $page, 'totals' => $this->sumLines($context, $pageSums, __('Page total'))];
        }

        $totals = $context->report instanceof ProvidesTotals
            ? array_map(fn (array $row): array => $this->display($context, $row, $widths), $context->report->totals($context->query))
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
            'right' => array_map(fn ($column): bool => ! in_array($column->type, [ColumnType::Text, ColumnType::Date, ColumnType::Instant], true), $context->columns),
            'widths' => array_map(fn (float $width): string => round($width / self::PAGE_WIDTH * 100, 2).'%', $widths),
        ])->setPaper('a4', 'landscape')->output());
    }

    /**
     * Column widths in pixels: figures and dates take what they need, the text columns share the rest.
     *
     * @param  list<ReportColumn>  $columns
     * @return list<float>
     */
    private function widths(array $columns): array
    {
        $fixed = fn (ColumnType $type): ?int => match ($type) {
            ColumnType::Text => null,
            ColumnType::Date => 74,
            ColumnType::Instant => 112,
            ColumnType::Integer => 60,
            ColumnType::Decimal => 84,
            ColumnType::Money => 118,
        };
        $taken = array_sum(array_map(fn ($column): int => $fixed($column->type) ?? 0, $columns));
        $texts = count(array_filter($columns, fn ($column): bool => $column->type === ColumnType::Text));
        $share = $texts > 0 ? max(90, (self::PAGE_WIDTH - $taken) / $texts) : 0;

        return array_map(fn ($column): float => (float) ($fixed($column->type) ?? $share), $columns);
    }

    /** How many characters of a text cell fit one line of its column. */
    private function perLine(float $width): int
    {
        return max(6, (int) floor(($width - self::CELL_PADDING) / self::CHAR_WIDTH));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<float>  $widths
     * @return array<string, string>
     */
    private function display(ExportContext $context, array $row, array $widths): array
    {
        $line = [];

        foreach ($context->columns as $index => $column) {
            $text = $this->cells->display($column->type, $row[$column->key] ?? null);
            $line[$column->key] = $column->type === ColumnType::Text ? $this->clip($text, $this->perLine($widths[$index])) : $text;
        }

        return $line;
    }

    /** A text that would take more than MAX_CELL_LINES lines is cut to fit and ends in an ellipsis. */
    private function clip(string $text, int $perLine): string
    {
        if (mb_strlen($text) <= $perLine || $this->lines($text, $perLine) <= self::MAX_CELL_LINES) {
            return $text;
        }

        $kept = mb_substr($text, 0, $perLine * self::MAX_CELL_LINES - 1);

        while ($kept !== '' && $this->lines(rtrim($kept).'…', $perLine) > self::MAX_CELL_LINES) {
            $kept = mb_substr($kept, 0, -1);
        }

        return rtrim($kept).'…';
    }

    /** Lines a text takes in a column of `$perLine` characters: words wrap, and a word longer than a line is broken. */
    private function lines(string $text, int $perLine): int
    {
        $lines = 1;
        $used = 0;

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $length = mb_strlen($word);

            if ($length > $perLine) {
                $lines += ($used > 0 ? 1 : 0) + intdiv($length - 1, $perLine);
                $used = $length - intdiv($length - 1, $perLine) * $perLine;
            } elseif ($used === 0) {
                $used = $length;
            } elseif ($used + 1 + $length <= $perLine) {
                $used += 1 + $length;
            } else {
                $lines++;
                $used = $length;
            }
        }

        return $lines;
    }

    /**
     * The height of a row in lines: its tallest text cell.
     *
     * @param  array<string, string>  $line
     * @param  list<float>  $widths
     */
    private function height(ExportContext $context, array $line, array $widths): int
    {
        $height = 1;

        foreach ($context->columns as $index => $column) {
            if ($column->type === ColumnType::Text) {
                $height = max($height, $this->lines($line[$column->key], $this->perLine($widths[$index])));
            }
        }

        return $height;
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
