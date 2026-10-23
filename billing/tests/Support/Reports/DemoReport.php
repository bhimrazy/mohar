<?php

namespace Tests\Support\Reports;

use App\Contracts\Reports\ConfiguresExport;
use App\Contracts\Reports\ProvidesHeaderBlock;
use App\Contracts\Reports\ProvidesLayouts;
use App\Contracts\Reports\ProvidesTotals;
use App\Contracts\Reports\Report;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilter;
use App\Data\Reports\ReportQuery;
use App\Enums\ColumnType;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A small report for the export pipeline tests: text, money, integer and date columns, a header block, totals and
 * two layouts.
 */
class DemoReport implements ConfiguresExport, ProvidesHeaderBlock, ProvidesLayouts, ProvidesTotals, Report
{
    /** @var list<array<string, mixed>> */
    public static array $rows = [];

    public static bool $sensitive = false;

    public static ?bool $bom = null;

    public function key(): string
    {
        return 'demo_sales';
    }

    public function label(): string
    {
        return 'Demo sales';
    }

    public function category(): string
    {
        return 'billing';
    }

    public function filters(): array
    {
        return [
            new ReportFilter('from', 'From', 'date', rules: ['required', 'date_format:Y-m-d']),
            new ReportFilter('to', 'To', 'date', rules: ['required', 'date_format:Y-m-d', 'after_or_equal:from']),
        ];
    }

    public function columns(ReportQuery $query): array
    {
        return [
            new ReportColumn('date', 'Date', ColumnType::Date),
            new ReportColumn('name', 'Buyer', ColumnType::Text),
            new ReportColumn('qty', 'Qty', ColumnType::Integer),
            new ReportColumn('amount', 'Amount (Rs)', ColumnType::Money),
        ];
    }

    public function rows(ReportQuery $query): iterable
    {
        yield from self::$rows;
    }

    public function count(ReportQuery $query): int
    {
        return count(self::$rows);
    }

    public function totals(ReportQuery $query): array
    {
        return [['name' => 'Net', 'amount' => Money::of(array_sum(array_map(fn (array $row): int => $row['amount']->amount, self::$rows)), 'NPR')]];
    }

    public function headerBlock(ReportQuery $query): array
    {
        return [['PAN', '123456789'], ['Period', ($query->from ?? '').' to '.($query->to ?? '')]];
    }

    public function layouts(): array
    {
        return ['detail' => 'Detail', 'short' => 'Short'];
    }

    public function layoutColumns(string $layout, ReportQuery $query): array
    {
        $columns = $this->columns($query);

        return $layout === 'short' ? [$columns[1], $columns[3]] : $columns;
    }

    public function sensitiveExport(): bool
    {
        return self::$sensitive;
    }

    public function csvBom(): ?bool
    {
        return self::$bom;
    }

    public static function row(string $name, int $minor, int $qty = 1): array
    {
        return ['date' => CarbonImmutable::parse('2026-10-20'), 'name' => $name, 'qty' => $qty, 'amount' => Money::of($minor, 'NPR')];
    }
}
