<?php

namespace App\Support\Csv;

/**
 * The one CSV writer (ADR 0025 §3): UTF-8, comma separated, double-quote quoting with quotes doubled (no backslash
 * escape), `\n` line ends, and an optional byte order mark so Excel on Windows reads non-English text.
 *
 * A text cell a spreadsheet would run as a formula is prefixed with an apostrophe: one that starts with a tab or a
 * carriage return, or whose first character after any white space (spaces, line breaks, NUL, no-break space,
 * ideographic space) is `=`, `+`, `-` or `@`, or one of their full-width forms. Numbers (int, float) and Verbatim cells are written as they are, so a credit-note
 * amount of -1300.00 stays a number a spreadsheet can sum.
 *
 * @api
 */
final class SafeCsv
{
    public const BOM = "\xEF\xBB\xBF";

    /** A formula after any leading white space: ASCII or full-width = + - @. */
    private const FORMULA_AFTER_SPACE = '/^[\x00\s\x{00A0}\x{3000}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u';

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
        if ($text === '') {
            return $text;
        }

        // Text that is not valid UTF-8 makes the pattern fail (false): treat it as a formula to be safe.
        $formula = in_array($text[0], ["\t", "\r"], true) || preg_match(self::FORMULA_AFTER_SPACE, $text) !== 0;

        return $formula ? "'".$text : $text;
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
