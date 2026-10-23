<?php

namespace App\Support\Reports;

use App\Contracts\Reports\ProvidesTotals;
use App\Enums\ExportFormat;
use App\Support\Csv\SafeCsv;

/**
 * The CSV export: header block rows, the column headings, the rows, then the totals rows (ADR 0025 §3).
 */
final class CsvExportWriter implements ExportWriter
{
    public function __construct(private CellFormatter $cells) {}

    public function format(): ExportFormat
    {
        return ExportFormat::Csv;
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function buffered(): bool
    {
        return false;
    }

    public function write(ExportContext $context, $stream): void
    {
        $csv = new SafeCsv($stream, $context->csvBom);

        foreach ($context->header as [$label, $value]) {
            $csv->row([$label, $value]);
        }

        $csv->row(array_map(fn ($column): string => $column->label, $context->columns));

        foreach ($context->rows as $row) {
            $csv->row($this->line($context, $row));
        }

        if ($context->report instanceof ProvidesTotals) {
            foreach ($context->report->totals($context->query) as $row) {
                $csv->row($this->line($context, $row));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function line(ExportContext $context, array $row): array
    {
        return array_map(fn ($column) => $this->cells->csv($column->type, $row[$column->key] ?? null), $context->columns);
    }
}
