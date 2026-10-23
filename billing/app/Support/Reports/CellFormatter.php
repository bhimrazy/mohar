<?php

namespace App\Support\Reports;

use App\Contracts\Core\CompanyClock;
use App\Enums\ColumnType;
use App\Support\Csv\Verbatim;
use App\Support\Currencies\MoneyFormatter;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Formats report cells from their typed values (ADR 0025 §3). A cell that does not fit its column type (a text
 * where a number is expected) is written as text, so it still goes through the formula guard.
 */
final class CellFormatter
{
    public function __construct(private CompanyClock $clock, private MoneyFormatter $money) {}

    /**
     * A cell for the CSV: typed values as Verbatim (a money cell is the decimal string only), anything else as a
     * string for SafeCsv to check.
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
     * A cell for the PDF: money in the currency's configured display (Admin → Currencies, "Rs 1,23,456.00").
     */
    public function display(ColumnType $type, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($type === ColumnType::Money && $value instanceof Money) {
            return $this->money->format($value);
        }

        $formatted = $this->typed($type, $value);

        return match (true) {
            $formatted === null => $this->text($value),
            $type === ColumnType::Instant => str_replace('T', ' ', substr($formatted, 0, 16)),
            default => $formatted,
        };
    }

    private function typed(ColumnType $type, mixed $value): ?string
    {
        return match ($type) {
            ColumnType::Money => $value instanceof Money ? $value->toDecimal() : null,
            ColumnType::Integer => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) ? (string) $value : null,
            ColumnType::Decimal => match (true) {
                is_int($value) => (string) $value,
                is_float($value) => is_finite($value) ? rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') : null,
                is_string($value) => preg_match('/^-?\d+(\.\d+)?$/D', $value) === 1 ? $value : null,
                default => null,
            },
            ColumnType::Date => $value instanceof CarbonInterface ? $value->format('Y-m-d') : null,
            ColumnType::Instant => $value instanceof CarbonInterface ? $value->setTimezone($this->clock->timezone())->toIso8601String() : null,
            ColumnType::Text => null,
        };
    }

    private function text(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            $value instanceof Money => $value->toDecimal(),
            $value instanceof CarbonInterface => $value->toIso8601String(),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
