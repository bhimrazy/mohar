<?php

namespace App\Modules\Migration\Support;

use Illuminate\Validation\ValidationException;

/**
 * Reading CSV files: encoding and delimiter detection, the upload limits (client-import spec §5.1), and one row at a
 * time from any stream.
 */
final class CsvFile
{
    public const DELIMITERS = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"];

    public const MAX_CELL_BYTES = 32 * 1024;

    /** Longest line read at once (outside quotes); a longer one is refused, which bounds the memory one row can take. */
    public const MAX_LINE_BYTES = 256 * 1024;

    public const BOM = "\xEF\xBB\xBF";

    private const SNIFF_BYTES = 65536;

    /**
     * Check a local file against the limits before anything is stored, and detect its delimiter unless one is given.
     *
     * @return array{delimiter: string, rows: int}
     *
     * @throws ValidationException naming the limit or the encoding problem
     */
    public static function inspect(string $path, ?string $delimiter, int $maxRows, int $maxColumns): array
    {
        $head = (string) file_get_contents($path, length: self::SNIFF_BYTES);

        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF") || str_contains($head, "\0") || ! self::isUtf8($head)) {
            self::refuse(__('This file is not UTF-8 text. Save the file as CSV UTF-8 and upload it again.'));
        }

        $delimiter ??= self::detectDelimiter(self::stripBom($head));
        $handle = fopen($path, 'rb') ?: self::refuse(__('The file could not be read. Upload it again.'));
        fseek($handle, str_starts_with($head, self::BOM) ? strlen(self::BOM) : 0);
        $header = self::read($handle, $delimiter);

        if ($header === null || array_filter($header, fn (string $cell): bool => trim($cell) !== '') === []) {
            self::refuse(__('The file has no header row. Put the column names in the first row.'));
        }

        if (count($header) > $maxColumns) {
            self::refuse(__('The file has :count columns; the limit is :limit.', ['count' => count($header), 'limit' => $maxColumns]));
        }

        $rows = 0;

        while (($row = self::read($handle, $delimiter)) !== null) {
            if (++$rows > $maxRows) {
                self::refuse(__('The file has more than :limit rows. Split it into smaller files.', ['limit' => number_format($maxRows)]));
            }

            if (count($row) > $maxColumns || ! self::endsLine($handle)) {
                self::refuse(__('Row :row has more cells than the :limit columns a file may have.', ['row' => number_format($rows), 'limit' => $maxColumns]));
            }

            if (! mb_check_encoding(implode("\n", $row), 'UTF-8')) {
                self::refuse(__('This file is not UTF-8 text. Save the file as CSV UTF-8 and upload it again.'));
            }

            foreach ($row as $cell) {
                if (strlen($cell) > self::MAX_CELL_BYTES) {
                    self::refuse(__('Row :row has a cell longer than 32 KB, the limit per cell.', ['row' => number_format($rows)]));
                }
            }
        }

        fclose($handle);

        if ($rows === 0) {
            self::refuse(__('The file has a header row but no clients.'));
        }

        return ['delimiter' => $delimiter, 'rows' => $rows];
    }

    /**
     * The next non-blank row, or null at the end.
     *
     * @param  resource  $handle
     * @return list<string>|null
     */
    public static function read($handle, string $delimiter): ?array
    {
        while (($row = fgetcsv($handle, self::MAX_LINE_BYTES, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                return array_map(fn (?string $cell): string => (string) $cell, $row);
            }
        }

        return null;
    }

    /**
     * Whether the row just read ended at a line break (or the end of the file), not at MAX_LINE_BYTES.
     *
     * @param  resource  $handle
     */
    private static function endsLine($handle): bool
    {
        $position = (int) ftell($handle);

        if (feof($handle) || $position === 0) {
            return true;
        }

        fseek($handle, $position - 1);
        $last = fread($handle, 1);

        return $last === "\n" || $last === "\r";
    }

    private static function detectDelimiter(string $head): string
    {
        // Count each candidate in the header line, outside quotes.
        $line = (string) preg_replace('/"[^"]*"/', '', strtok($head, "\r\n") ?: '');
        $counts = array_map(fn (string $delimiter): int => substr_count($line, $delimiter), self::DELIMITERS);
        arsort($counts);

        return self::DELIMITERS[array_key_first($counts)];
    }

    private static function isUtf8(string $head): bool
    {
        // The sample may end inside a multi-byte character.
        for ($cut = 0; $cut <= 3 && $cut < strlen($head); $cut++) {
            if (mb_check_encoding(substr($head, 0, strlen($head) - $cut), 'UTF-8')) {
                return true;
            }
        }

        return $head === '';
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, self::BOM) ? substr($value, strlen(self::BOM)) : $value;
    }

    private static function refuse(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
