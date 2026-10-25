<?php

namespace App\Data\Reports;

use App\Enums\ColumnType;

/**
 * One column of a report: the key rows are indexed by, the heading and how its cells are formatted.
 *
 * @api
 */
final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ColumnType $type = ColumnType::Text,
    ) {}
}
