<?php

namespace App\Contracts\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilter;
use App\Data\Reports\ReportQuery;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A report, implemented by the module or package that owns the data (ADR 0025, ADR 0010). Register it from a
 * service provider: `app(ReportRegistry::class)->register(VatRegisterReport::class)`. The registry adds the
 * permissions `reports.{key}.view` and `reports.{key}.export`; the export pipeline does the rest.
 *
 * Optional capabilities (ADR 0012), detected with `instanceof`: ProvidesTotals, ProvidesHeaderBlock,
 * ProvidesLayouts, ProvidesPdfView, ConfiguresExport.
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
     * Rows keyed by column key, produced lazily (a cursor or a generator), in the order they are written.
     *
     * @return iterable<array<string, scalar|Money|CarbonImmutable|null>>
     */
    public function rows(ReportQuery $query): iterable;

    public function count(ReportQuery $query): int;
}
