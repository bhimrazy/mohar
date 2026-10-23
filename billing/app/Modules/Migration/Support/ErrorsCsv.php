<?php

namespace App\Modules\Migration\Support;

use App\Modules\Migration\Models\MigrationRun;
use App\Support\Csv\SafeCsv;

/**
 * The rows that were not imported, for fixing the file (client-import spec §5.5, AC 32): row number, column and
 * reason, then the row's original cells. Written straight to the response, never stored. A cell a spreadsheet would
 * run as a formula is neutralised by SafeCsv (ADR 0025).
 */
final class ErrorsCsv
{
    /**
     * @param  resource  $out
     */
    public static function write(MigrationRun $run, $out): void
    {
        $header = $run->header ?? [];
        $columns = $run->mapping['columns'] ?? [];
        $csv = new SafeCsv($out);
        $csv->row([__('Row'), __('Column'), __('Reason'), ...$header]);

        $records = $run->records()->where(fn ($query) => $query->where('status', 'error')->orWhere('outcome', 'failed'))
            ->select(['id', 'row_number', 'raw', 'problems'])->lazyById(500);

        foreach ($records as $record) {
            $cells = array_map(fn (int $index): string => (string) ($record->raw[$index] ?? ''), array_keys($header));

            foreach (array_filter($record->problems ?? [], fn (array $problem): bool => $problem['severity'] === 'error') as $problem) {
                $index = isset($problem['field']) ? array_search($problem['field'], $columns, true) : false;
                $csv->row([(string) $record->row_number, $index === false ? '' : (string) ($header[$index] ?? ''), $problem['message'], ...$cells]);
            }
        }
    }
}
