<?php

namespace App\Contracts\Reports;

use App\Data\Reports\ReportQuery;

/**
 * Label and value pairs printed above the table (taxpayer PAN, legal name, period): leading rows in a CSV, the
 * top of the page in a PDF.
 *
 * @api
 */
interface ProvidesHeaderBlock
{
    /** @return list<array{0: string, 1: string}> */
    public function headerBlock(ReportQuery $query): array;
}
