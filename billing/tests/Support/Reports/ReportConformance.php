<?php

namespace Tests\Support\Reports;

use App\Data\Reports\ReportQuery;
use App\Support\Reports\ReportRegistry;
use App\Support\Reports\ReportViewer;
use PHPUnit\Framework\Assert;

/**
 * Paging conformance for a report (#403): a report that ignores `limit` and `offset` would show page 1 again as
 * page 2, and the viewer cannot tell. Every report registered in app/ is checked with this (tests/Arch/ReportsTest).
 * Call it with a query whose user may view the report and fixture data of more than one page.
 */
final class ReportConformance
{
    /**
     * Pages through the report on screen and checks that the pages, in order, are exactly the unpaged rows: none
     * repeated, none missing, `total` equal to them, each page within its limit, the footer the same on every page,
     * and nothing after the last page.
     */
    public static function assertPages(string $key, ReportQuery $query, int $limit = 2): void
    {
        $all = [];

        foreach (app(ReportRegistry::class)->get($key)->rows($query->unpaged()) as $row) {
            $all[] = $row;
        }

        Assert::assertGreaterThan($limit, count($all), "The fixture for [{$key}] must fill more than one page of {$limit}.");

        $viewer = app(ReportViewer::class);
        $paged = [];
        $first = $viewer->page($key, $query, $limit);

        for ($offset = 0; $offset < $first->total; $offset += $limit) {
            $page = $viewer->page($key, $query, $limit, $offset);
            Assert::assertLessThanOrEqual($limit, count($page->rows), "Page at offset {$offset} of [{$key}] holds more than {$limit} rows.");
            Assert::assertEquals($first->totals, $page->totals, "The totals of [{$key}] change from page to page.");
            array_push($paged, ...$page->rows);
        }

        Assert::assertSame(count($all), $first->total, "count() of [{$key}] differs from the rows it yields.");
        Assert::assertEquals($all, $paged, "The pages of [{$key}] are not its rows in order: it ignores the page or its order is not total.");
        Assert::assertSame([], $viewer->page($key, $query, $limit, $first->total)->rows, "[{$key}] yields rows after the last page.");
    }
}
