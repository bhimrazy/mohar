<?php

namespace App\Support\Reports;

use Generator;
use Illuminate\Support\Facades\Log;
use IteratorAggregate;

/**
 * The rows of a report, never more than the export limit (ADR 0025 §3): count() is the report's own claim, so the
 * stream is bounded here too. A source that yields more than the limit is cut off and logged with the claimed and the
 * written row counts. Single use; `written` is the number of rows handed out.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class CappedRows implements IteratorAggregate
{
    public int $written = 0;

    /**
     * @param  iterable<array<string, mixed>>  $source
     */
    public function __construct(private iterable $source, private int $max, private string $report, private int $claimed) {}

    public function getIterator(): Generator
    {
        foreach ($this->source as $row) {
            if ($this->written >= $this->max) {
                Log::warning('A report export was cut off at its row limit.', ['report' => $this->report, 'counted' => $this->claimed, 'written' => $this->written, 'limit' => $this->max]);

                return;
            }

            $this->written++;

            yield $row;
        }
    }
}
