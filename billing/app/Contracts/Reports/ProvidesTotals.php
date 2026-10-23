<?php

namespace App\Contracts\Reports;

use App\Data\Reports\ReportQuery;
use App\Support\Money;

/**
 * Footer rows written after the data rows: totals, or the "return figures". Each row is keyed by column key; the
 * first text column carries the row's label. Without it the PDF sums its money and integer columns itself.
 *
 * `totals()` always receives the unpaged query (no limit or offset): ReportViewer passes it with each screen page and
 * the exporter with each file, so the footer covers every row that matches the filters.
 *
 * Like `Report::count()`, it runs uncached on every screen page, so it must stay bounded: aggregate queries served by
 * an index on the filtered columns, a fixed number of them, and no walk through the sorted rows or OFFSET pages. A
 * report whose filters allow an unbounded period must cap the period (the VAT registers allow at most one year).
 *
 * @api
 */
interface ProvidesTotals
{
    /** @return list<array<string, scalar|Money|null>> */
    public function totals(ReportQuery $query): array;
}
