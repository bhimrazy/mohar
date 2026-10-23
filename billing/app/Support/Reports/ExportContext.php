<?php

namespace App\Support\Reports;

use App\Contracts\Reports\Report;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;

/**
 * Everything a writer needs for one export, resolved once by the ReportExporter.
 */
final readonly class ExportContext
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array{0: string, 1: string}>  $header
     */
    public function __construct(
        public Report $report,
        public ReportQuery $query,
        public array $columns,
        public ?string $layout,
        public array $header,
        public bool $csvBom,
        public string $printedBy,
        public string $printedAt,
        public CappedRows $rows,
    ) {}
}
