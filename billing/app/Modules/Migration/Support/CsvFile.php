<?php

namespace App\Modules\Migration\Support;

use Illuminate\Validation\ValidationException;
use LengthException;

/**
 * Reading CSV files: encoding and delimiter detection, the upload limits (client-import spec §5.1), and one row at a
 * time from any stream.
 */
final class CsvFile
{
    public const DELIMITERS = ['comma' => ',', 'semicolon' => ';', 'tab' => "\t"];

    public const MAX_CELL_BYTES = 32 * 1024;

    /**
     * Longest record (one row, including line breaks inside quoted cells). The raw bytes are capped before the record
     * is split into cells, so no row can take more memory than this allows.
     */
    public const MAX_RECORD_BYTES = 256 * 1024;

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

        while (true) {
            try {
                $row = self::read($handle, $delimiter);
            } catch (LengthException) {
                self::refuse(__('Row :row is longer than 256 KB, the limit per row.', ['row' => number_format($rows + 1)]));
            }

            if ($row === null) {
                break;
            }

            if (++$rows > $maxRows) {
                self::refuse(__('The file has more than :limit rows. Split it into smaller files.', ['limit' => number_format($maxRows)]));
            }

            if (count($row) > $maxColumns) {
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
     * The next non-blank row, or null at the end. The raw record is read first, line by line while a quoted cell is
     * open, and split into cells only when it fits MAX_RECORD_BYTES.
     *
     * @param  resource  $handle
     * @return list<string>|null
     *
     * @throws LengthException when the record is longer than MAX_RECORD_BYTES
     */
    public static function read($handle, string $delimiter): ?array
    {
        while (($record = self::record($handle)) !== null) {
            $record = rtrim($record, "\r\n");

            if ($record !== '') {
                return str_getcsv($record, $delimiter, '"', '');
            }
        }

        return null;
    }

    /**
     * One raw record: physical lines joined while the quotes are unbalanced (a doubled quote counts twice, so the
     * parity still holds), never more than MAX_RECORD_BYTES.
     *
     * @param  resource  $handle
     */
    private static function record($handle): ?string
    {
        $record = '';

        while (($line = fgets($handle, max(2, self::MAX_RECORD_BYTES + 2 - strlen($record)))) !== false) {
            $record .= $line;

            if (strlen($record) > self::MAX_RECORD_BYTES) {
                throw new LengthException('Record longer than '.self::MAX_RECORD_BYTES.' bytes.');
            }

            if ((str_ends_with($line, "\n") || feof($handle)) && substr_count($record, '"') % 2 === 0) {
                return $record;
            }
        }

        return $record === '' ? null : $record;
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
