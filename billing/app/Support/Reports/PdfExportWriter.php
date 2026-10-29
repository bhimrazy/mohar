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
 * Long text wraps inside its column (a legal name is never cut mid-word with nothing to show it), and text that would
 * take more than MAX_CELL_LINES lines is cut and ends in an ellipsis. The page split is planned from the height each
 * row will print at: its lines are counted with the real glyph widths of the PDF font (TextMeasure) in the column
 * widths this writer gives the view, and the header block, the page totals and the grand totals count too. The
 * widths are passed to the default table; a report's own `pdfView()` is not bound to them, so its pages are planned
 * as if it used them.
 */
final class PdfExportWriter implements ExportWriter
{
    /** Most lines a text cell takes; longer text is cut and ends in an ellipsis. */
    private const MAX_CELL_LINES = 4;

    /** Printable width of the A4 landscape page in CSS pixels (1,122 less the 24px page margins). */
    private const PAGE_WIDTH = 1074;

    /** Left and right padding of a cell, and a pixel of slack so a line that only just fits is counted as full. */
    private const CELL_PADDING = 7;

    /** Height of a table row beyond its text lines: padding and the rule. A total row adds the rule above it. */
    private const ROW_EXTRA = 7;

    /**
     * Height, in pixels, that the rows and page totals of one page may take. A4 landscape leaves about 730px, less the
     * column headings and the page footer; the rest of it is slack for what the estimate cannot see (kerning).
     */
    private const PAGE_BUDGET = 500;

    /** Most lines a header value takes, and the most height the whole header block may take (half the page budget). */
    private const MAX_HEADER_LINES = 3;

    private const HEADER_BUDGET = 250;

    /** The title block and the gap above the table, on the first page only. */
    private const FIRST_PAGE_FIXED = 60;

    public function __construct(private CellFormatter $cells, private BrandProvider $brand, private TextMeasure $measure) {}

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
        $widths = $this->widths($context->columns);
        $line = $this->measure->lineHeight();
        $totalRow = $line + self::ROW_EXTRA + 1;
        $pages = [];
        $page = [];
        $allSums = new Sums;
        $pageSums = new Sums;
        $currencies = [];
        $used = 0.0;
        $header = $this->header($context, $line);
        $budget = self::PAGE_BUDGET - self::FIRST_PAGE_FIXED - $this->headerHeight($header, $line);

        foreach ($context->rows as $row) {
            $cells = $this->display($context, $row, $widths);
            $height = $this->height($context, $cells, $widths, $line);
            $seen = array_values(array_unique([...$currencies, ...$this->currenciesOf($context, $row)]));

            // A row that does not leave room for its page's totals starts the next page.
            if ($page !== [] && $used + $height + max(1, count($seen)) * $totalRow > $budget) {
                $pages[] = ['rows' => $page, 'totals' => $this->sumLines($context, $pageSums, __('Page total'))];
                $page = [];
                $pageSums = new Sums;
                $budget = self::PAGE_BUDGET;
                $used = 0.0;
                $seen = $this->currenciesOf($context, $row);
            }

            $currencies = $seen;
            $page[] = $cells;
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

        // The grand totals follow the last page's own totals; when they do not fit under it, they take a page.
        if ($page !== [] && $used + (max(1, count($currencies)) + count($totals)) * $totalRow > $budget) {
            $pages[] = ['rows' => [], 'totals' => []];
        }

        fwrite($stream, Pdf::loadView('reports.pdf', [
            'brand' => $this->brand->brand()->name,
            'title' => $context->report->label(),
            'header' => $header,
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
     * The room a header value has beside the widest label.
     *
     * @param  non-empty-list<array{0: string, 1: string}>  $header
     */
    private function headerRoom(array $header): float
    {
        return self::PAGE_WIDTH - max(array_map(fn (array $entry): float => $this->measure->width((string) $entry[0]), $header)) - 12 - 2 * self::CELL_PADDING;
    }

    /**
     * The height of the header block, each value wrapped in the room the label column leaves it.
     *
     * @param  list<array{0: string, 1: string}>  $header
     */
    private function headerHeight(array $header, float $line): float
    {
        if ($header === []) {
            return 0.0;
        }

        $room = $this->headerRoom($header);

        return 8.0 + array_sum(array_map(fn (array $entry): float => $this->measure->lines((string) $entry[1], $room, bold: true) * $line + 2, $header));
    }

    /**
     * The header as printed (#367): a value takes at most MAX_HEADER_LINES lines and ends in an ellipsis when cut, and
     * the block takes at most HEADER_BUDGET, so the table always has room on the first page. Entries that do not fit
     * are replaced by one line that says how many were left out.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function header(ExportContext $context, float $line): array
    {
        if ($context->header === []) {
            return [];
        }

        $room = $this->headerRoom($context->header);
        $kept = [];
        $used = 8.0;

        foreach ($context->header as $index => [$label, $value]) {
            $value = $this->clip((string) $value, $room, self::MAX_HEADER_LINES, bold: true);
            $height = $this->measure->lines($value, $room, bold: true) * $line + 2;
            $more = count($context->header) - $index - 1;

            // Keep one line free for the "left out" note unless this is the last entry.
            if ($used + $height + ($more > 0 ? $line + 2 : 0) > self::HEADER_BUDGET) {
                $left = count($context->header) - $index;
                $kept[] = ['…', trans_choice('{1} :count more header line not shown|[2,*] :count more header lines not shown', $left)];

                return $kept;
            }

            $kept[] = [(string) $label, $value];
            $used += $height;
        }

        return $kept;
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
            $line[$column->key] = $column->type === ColumnType::Text ? $this->clip($text, $widths[$index] - self::CELL_PADDING) : $text;
        }

        return $line;
    }

    /** A text that would take more than `$maxLines` lines is cut, at a whole character, and ends in an ellipsis. */
    private function clip(string $text, float $room, int $maxLines = self::MAX_CELL_LINES, bool $bold = false): string
    {
        if ($this->measure->lines($text, $room, $bold) <= $maxLines) {
            return $text;
        }

        // The longest start of the text that, with the ellipsis, still fits (the lines only grow with the text).
        preg_match_all('/\X/u', $text, $matches);
        $graphemes = $matches[0];
        $low = 0;
        $high = count($graphemes);

        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);

            if ($this->measure->lines(rtrim(implode('', array_slice($graphemes, 0, $middle))).'…', $room, $bold) <= $maxLines) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return rtrim(implode('', array_slice($graphemes, 0, $low))).'…';
    }

    /**
     * The height of a row: its tallest text cell.
     *
     * @param  array<string, string>  $cells
     * @param  list<float>  $widths
     */
    private function height(ExportContext $context, array $cells, array $widths, float $line): float
    {
        $lines = 1;

        foreach ($context->columns as $index => $column) {
            if ($column->type === ColumnType::Text) {
                $lines = max($lines, $this->measure->lines($cells[$column->key], $widths[$index] - self::CELL_PADDING));
            }
        }

        return $lines * $line + self::ROW_EXTRA;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function currenciesOf(ExportContext $context, array $row): array
    {
        $codes = [];

        foreach ($context->columns as $column) {
            $value = $row[$column->key] ?? null;

            if ($column->type === ColumnType::Money && $value instanceof Money) {
                $codes[] = $value->currency;
            }
        }

        return array_values(array_unique($codes));
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
