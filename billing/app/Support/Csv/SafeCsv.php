<?php

namespace App\Support\Csv;

/**
 * The one CSV writer (ADR 0025 §3): UTF-8, comma separated, double-quote quoting with quotes doubled (no backslash
 * escape), `\n` line ends, and an optional byte order mark so Excel on Windows reads non-English text.
 *
 * A text cell a spreadsheet would run as a formula (first character `=`, `+`, `-`, `@`, tab or carriage return) is
 * prefixed with an apostrophe. Numbers (int, float) and Verbatim cells are written as they are, so a credit-note
 * amount of -1300.00 stays a number a spreadsheet can sum.
 *
 * @api
 */
final class SafeCsv
{
    public const BOM = "\xEF\xBB\xBF";

    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  resource  $stream
     */
    public function __construct(private $stream, bool $bom = false)
    {
        if ($bom) {
            fwrite($this->stream, self::BOM);
        }
    }

    /**
     * @param  array<array-key, string|int|float|Verbatim|null>  $cells
     */
    public function row(array $cells): void
    {
        fputcsv($this->stream, array_map(self::cell(...), array_values($cells)), ',', '"', '', "\n");
    }

    /**
     * Neutralises a text cell.
     */
    public static function guard(string $text): string
    {
        return $text !== '' && in_array($text[0], self::FORMULA_STARTS, true) ? "'".$text : $text;
    }

    private static function cell(string|int|float|Verbatim|null $cell): string
    {
        return match (true) {
            $cell === null => '',
            $cell instanceof Verbatim => $cell->value,
            is_string($cell) => self::guard($cell),
            default => (string) $cell,
        };
    }
}
