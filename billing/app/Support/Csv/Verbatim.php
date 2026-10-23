<?php

namespace App\Support\Csv;

/**
 * A CSV cell that is already formatted from a typed value (an amount, a count, a date) and is written as it is.
 * SafeCsv neutralises every plain string, so a value only becomes Verbatim where it was produced from a number or a
 * date, never from text a user typed.
 *
 * @api
 */
final readonly class Verbatim
{
    public function __construct(public string $value) {}
}
