<?php

namespace App\Contracts\Reports;

/**
 * CSV options a report sets for itself. Without it the `reports.csv_bom` setting applies.
 *
 * @api
 */
interface ProvidesCsvOptions
{
    /** Whether the CSV starts with a UTF-8 byte order mark (Excel on Windows needs it for Nepali text). */
    public function csvBom(): bool;
}
