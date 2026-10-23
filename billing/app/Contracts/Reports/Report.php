<?php

namespace App\Contracts\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilter;
use App\Data\Reports\ReportQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A report, implemented by the module or package that owns the data (ADR 0025, ADR 0010). Register it from a
 * service provider: `app(ReportRegistry::class)->register('vat_register', VatRegisterReport::class)`. Nothing is
 * built until the report is used. The registry adds the permissions `reports.{key}.view` and
 * `reports.{key}.export`; the export pipeline does the rest.
 *
 * Optional capabilities (ADR 0012), detected with `instanceof`: ProvidesTotals, ProvidesHeaderBlock,
 * ProvidesLayouts, ProvidesPdfView, ProvidesCsvOptions, HasSensitiveExport (a marker).
 *
 * @api
 */
interface Report
{
    /** Unique key, 1 to 50 characters of a-z, 0-9 and underscore, e.g. "vat_register". */
    public function key(): string;

    public function label(): string;

    /** Grouping on the library page, e.g. "billing" or "clients". */
    public function category(): string;

    /** @return list<ReportFilter> */
    public function filters(): array;

    /** @return list<ReportColumn> */
    public function columns(ReportQuery $query): array;

    /**
     * Rows keyed by column key, in the order they are written. Without a limit (an export), read lazily with
     * `lazyById()` or `lazy()`: on PostgreSQL `cursor()` holds the whole result set in memory. The pipeline stops reading
     * at its row limit.
     *
     * When `$query->limit` is set (a screen page), return only that page: `orderBy()` in a total order (end with a
     * unique key such as the id, or rows repeat or vanish between pages), then `offset($query->offset)`,
     * `limit($query->limit)` and `get()`. `lazyById()` cannot be used here: it orders by id alone and ignores the
     * offset. tests/Support/Reports/ReportConformance checks this for every report.
     *
     * A `money` cell is a `Money`; a `date` cell is a date value (00:00 UTC on that date, like a `date` cast), so an
     * instant must go through `CompanyClock::localDate()` or use an `instant` column.
     *
     * @return iterable<array<string, scalar|Money|CarbonImmutable|null>>
     */
    public function rows(ReportQuery $query): iterable;

    /**
     * All rows matching the filters, on a query without a page. The viewer calls it on every screen page, uncached,
     * so it must be served by an index on the columns the filters use (a period column, for example).
     */
    public function count(ReportQuery $query): int;
}
