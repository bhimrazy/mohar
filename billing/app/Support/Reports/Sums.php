<?php

namespace App\Support\Reports;

use App\Enums\ColumnType;
use App\Support\Money;

/**
 * Running totals for the PDF: money per column and currency (never across currencies), integers per column.
 */
final class Sums
{
    /** @var array<string, array<string, int>> */
    private array $money = [];

    /** @var array<string, int> */
    private array $integers = [];

    public function add(string $column, ColumnType $type, mixed $value): void
    {
        if ($type === ColumnType::Money && $value instanceof Money) {
            $this->money[$column][$value->currency] = ($this->money[$column][$value->currency] ?? 0) + $value->amount;
        } elseif ($type === ColumnType::Integer && is_int($value)) {
            $this->integers[$column] = ($this->integers[$column] ?? 0) + $value;
        }
    }

    /**
     * @return list<string>
     */
    public function currencies(): array
    {
        $codes = array_unique(array_merge(...array_map('array_keys', array_values($this->money))));
        sort($codes);

        return $codes;
    }

    public function money(string $column, string $currency): ?int
    {
        return $this->money[$column][$currency] ?? null;
    }

    public function integer(string $column): int
    {
        return $this->integers[$column] ?? 0;
    }
}
