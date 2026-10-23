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
 * @api
 */
interface ProvidesTotals
{
    /** @return list<array<string, scalar|Money|null>> */
    public function totals(ReportQuery $query): array;
}
