<?php

namespace App\Data\Reports;

/**
 * One page of a report for an on-screen view: the columns, at most `limit` rows keyed by column key, and `total`, the
 * number of rows matching the filters (what `Report::count()` returned). `totals` holds the report's footer rows
 * (`ProvidesTotals::totals()` on the unpaged query, so they cover every matching row, not this page), or is empty when
 * the report has none.
 *
 * @api
 */
final readonly class ReportPage
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $totals
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public int $total,
        public int $limit,
        public int $offset,
        public array $totals = [],
    ) {}
}
