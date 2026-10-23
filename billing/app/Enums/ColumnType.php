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
    /** A `Money` value. The CSV gets the decimal string only; the report declares its own currency column. */
    case Money = 'money';
    case Integer = 'integer';
    case Decimal = 'decimal';
    /** A calendar date (a `CarbonInterface` read by its own Y-m-d, like a `date` cast). An instant is an `Instant`. */
    case Date = 'date';
    case Instant = 'instant';
}
