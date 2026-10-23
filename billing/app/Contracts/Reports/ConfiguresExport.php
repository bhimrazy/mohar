<?php

namespace App\Contracts\Reports;

/**
 * Export policy a report sets for itself (ADR 0025 §3, §4).
 *
 * @api
 */
interface ConfiguresExport
{
    /** True when the export carries personal or tax identifiers in bulk: it then needs a recent password confirmation. */
    public function sensitiveExport(): bool;

    /** Whether the CSV starts with a UTF-8 byte order mark (Excel on Windows needs it for Nepali text); null = the `reports.csv_bom` setting. */
    public function csvBom(): ?bool;
}
