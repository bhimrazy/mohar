<?php

namespace Tests\Support\Reports;

use App\Contracts\Reports\ProvidesPdfView;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportQuery;
use App\Enums\ColumnType;

/** A date, a number of text columns (`t0`, `t1`, ...) and an amount, with a header block of any size. */
class ManyTextReport extends DemoReport implements ProvidesPdfView
{
    public static int $textColumns = 3;

    /** @var list<array{0: string, 1: string}> */
    public static array $header = [];

    public static bool $ownView = false;

    public function columns(ReportQuery $query): array
    {
        $columns = [new ReportColumn('date', 'Date', ColumnType::Date)];

        for ($i = 0; $i < self::$textColumns; $i++) {
            $columns[] = new ReportColumn('t'.$i, 'Text '.$i, ColumnType::Text);
        }

        $columns[] = new ReportColumn('amount', 'Amount', ColumnType::Money);

        return $columns;
    }

    public function headerBlock(ReportQuery $query): array
    {
        return self::$header;
    }

    public function pdfView(): string
    {
        return self::$ownView ? 'report-tests::own-table' : '';
    }

    /** A row with the same text in every text column. */
    public static function textRow(string $text, int $minor = 1_000): array
    {
        $row = DemoReport::row('x', $minor);

        for ($i = 0; $i < self::$textColumns; $i++) {
            $row['t'.$i] = $text;
        }

        return $row;
    }
}
