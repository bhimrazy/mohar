<?php

namespace App\Contracts\Reports;

/**
 * Replaces the table part of the kernel PDF page with a Blade view. The view receives `$report`, `$columns`,
 * `$rows` (arrays of formatted strings keyed by column key) and `$totals`; the header, brand and "printed by" line
 * stay the kernel's.
 *
 * @api
 */
interface ProvidesPdfView
{
    public function pdfView(): string;
}
