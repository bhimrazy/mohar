<?php

namespace App\Data\Reports;

/**
 * One page of a report for an on-screen view: the columns, at most `limit` rows keyed by column key, and `total`, the
 * number of rows matching the filters (what `Report::count()` returned).
 *
 * @api
 */
final readonly class ReportPage
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public int $total,
        public int $limit,
        public int $offset,
    ) {}
}
