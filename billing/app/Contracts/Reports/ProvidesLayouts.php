<?php

namespace App\Contracts\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;

/**
 * Named column sets, such as `detail` and `ird`. The rows stay the same; a layout picks and names the columns.
 * The first layout is the default.
 *
 * @api
 */
interface ProvidesLayouts
{
    /** @return array<string, string> layout key => label */
    public function layouts(): array;

    /** @return list<ReportColumn> */
    public function layoutColumns(string $layout, ReportQuery $query): array;
}
