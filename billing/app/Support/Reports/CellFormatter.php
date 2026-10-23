<?php

namespace App\Support\Reports;

use App\Contracts\Core\CompanyClock;
use App\Enums\ColumnType;
use App\Support\Csv\Verbatim;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Formats report cells from their typed values (ADR 0025 §3). A cell that does not fit its column type (a text
 * where a number is expected) is written as text, so it still goes through the formula guard.
 */
final class CellFormatter
{
    public function __construct(private CompanyClock $clock) {}

    /**
     * A cell for the CSV: typed values as Verbatim, anything else as a string for SafeCsv to check.
     */
    public function csv(ColumnType $type, mixed $value): string|Verbatim|null
    {
        if ($value === null) {
            return null;
        }

        $formatted = $this->typed($type, $value);

        return $formatted !== null ? new Verbatim($formatted) : $this->text($value);
    }

    /**
     * A cell for the PDF: amounts and counts with thousands separators.
     */
    public function display(ColumnType $type, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $formatted = $this->typed($type, $value);

        if ($formatted === null) {
            return $this->text($value);
        }

        return match ($type) {
            ColumnType::Money, ColumnType::Integer, ColumnType::Decimal => $this->group($formatted),
            ColumnType::Instant => str_replace('T', ' ', substr($formatted, 0, 16)),
            default => $formatted,
        };
    }

    /**
     * An amount in minor units as a decimal string with two places, "-1300.00".
     */
    public static function minorToDecimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '').intdiv(abs($minor), 100).'.'.str_pad((string) (abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The minor units a money or integer cell contributes to a total, or null when it is not a number.
     */
    public static function summable(ColumnType $type, mixed $value): ?int
    {
        return match (true) {
            $type === ColumnType::Money && $value instanceof Money => $value->amount,
            in_array($type, [ColumnType::Money, ColumnType::Integer], true) && is_int($value) => $value,
            default => null,
        };
    }

    private function typed(ColumnType $type, mixed $value): ?string
    {
        return match ($type) {
            ColumnType::Money => match (true) {
                $value instanceof Money => self::minorToDecimal($value->amount),
                is_int($value) => self::minorToDecimal($value),
                default => null,
            },
            ColumnType::Integer => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (string) $value : null,
            ColumnType::Decimal => (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1)) ? (string) $value : null,
            ColumnType::Date => $value instanceof CarbonInterface ? $value->format('Y-m-d') : null,
            ColumnType::Instant => $value instanceof CarbonInterface ? $value->setTimezone($this->clock->timezone())->toIso8601String() : null,
            ColumnType::Text => null,
        };
    }

    private function text(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            $value instanceof Money => self::minorToDecimal($value->amount),
            $value instanceof CarbonInterface => $value->toIso8601String(),
            default => (string) $value,
        };
    }

    private function group(string $number): string
    {
        [$whole, $fraction] = array_pad(explode('.', $number, 2), 2, null);
        $sign = str_starts_with($whole, '-') ? '-' : '';
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', ltrim($whole, '-'));

        return $sign.$grouped.($fraction !== null ? '.'.$fraction : '');
    }
}
