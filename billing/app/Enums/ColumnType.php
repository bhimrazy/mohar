<?php

namespace App\Enums;

/**
 * How a report column's cells are formatted by the export pipeline (ADR 0025 §1). Only Text cells are checked for
 * spreadsheet formulas; every other type is written from its typed value, so a negative amount stays a number.
 *
 * Closed enum (ADR 0010): a new case is a major change, because every export writer handles each case.
 */
enum ColumnType: string
{
    case Text = 'text';
    case Money = 'money';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Date = 'date';
    case Instant = 'instant';
}
