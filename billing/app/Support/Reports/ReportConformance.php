<?php

namespace App\Support\Reports;

use App\Data\Reports\ReportQuery;
use ArrayObject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * The conformance kit for a report, usable by any package that registers one (ADR 0009 Amendment (#515), ADR 0020 Amendment (#515)). Call both
 * checks from the package's own tests, with a query whose user may view the report and fixture data of more than one
 * page. Each throws ReportConformanceFailure. tests/Arch/ReportsTest requires both for every report in app/.
 *
 * @api
 */
final class ReportConformance
{
    /** Most queries one ReportViewer page may run: its rows, count() and totals(), plus lookups. */
    public const QUERY_BUDGET = 12;

    /**
     * Paging (#403): a report that ignores `limit` and `offset` would show page 1 again as page 2, and the viewer
     * cannot tell. Pages through the report on screen and checks that the pages, in order, are exactly the unpaged
     * rows: none repeated, none missing, `total` equal to them, each page within its limit, the footer the same on
     * every page, and nothing after the last page.
     */
    public static function assertPages(string $key, ReportQuery $query, int $limit = 2): void
    {
        $all = [];

        foreach (app(ReportRegistry::class)->get($key)->rows($query->unpaged()) as $row) {
            $all[] = $row;
        }

        self::that(count($all) > $limit, "The fixture for [{$key}] must fill more than one page of {$limit}.");

        $viewer = app(ReportViewer::class);
        $paged = [];
        $first = $viewer->page($key, $query, $limit);

        for ($offset = 0; $offset < $first->total; $offset += $limit) {
            $page = $viewer->page($key, $query, $limit, $offset);
            self::that(count($page->rows) <= $limit, "Page at offset {$offset} of [{$key}] holds more than {$limit} rows.");
            self::that($first->totals == $page->totals, "The totals of [{$key}] change from page to page.");
            array_push($paged, ...$page->rows);
        }

        self::that(count($all) === $first->total, "count() of [{$key}] differs from the rows it yields.");
        self::that($all == $paged, "The pages of [{$key}] are not its rows in order: it ignores the page or its order is not total.");
        self::that($viewer->page($key, $query, $limit, $first->total)->rows === [], "[{$key}] yields rows after the last page.");
    }

    /**
     * Query budget (#431): one screen page, totals included, runs at most `$budget` queries. count() and totals() run
     * on every page without a cache, so each is a fixed number of index-served aggregates, and rows() loads what it
     * shows in bulk. A page where one statement ran once per row it shows is an N+1 and fails too, which needs a
     * fixture of at least two rows. The page is run once before it is measured, so cold caches do not count.
     */
    public static function assertQueryBudget(string $key, ReportQuery $query, int $budget = self::QUERY_BUDGET): void
    {
        $viewer = app(ReportViewer::class);
        $viewer->page($key, $query, ReportQuery::MAX_LIMIT);

        $page = null;
        $statements = self::record(function () use ($viewer, $key, $query, &$page): void {
            $page = $viewer->page($key, $query, ReportQuery::MAX_LIMIT);
        });

        $shown = count($page->rows);
        $ran = array_sum($statements);

        self::that($shown >= 2, "The fixture for [{$key}] must show at least two rows.");
        self::that($ran <= $budget, "A page of [{$key}] ran {$ran} queries; the budget is {$budget}.");

        foreach ($statements as $sql => $times) {
            self::that($times < $shown, "A page of [{$key}] ran one statement {$times} times for {$shown} rows: it queries per row. ".mb_substr($sql, 0, 120));
        }
    }

    /**
     * @param  callable(): void  $run
     * @return array<string, int> the number of runs of each statement
     */
    private static function record(callable $run): array
    {
        /** @var ArrayObject<string, int> $log */
        $log = new ArrayObject;
        $active = new ArrayObject(['on' => true]);
        DB::listen(function (QueryExecuted $query) use ($log, $active): void {
            if ($active['on']) {
                $log[$query->sql] = ($log[$query->sql] ?? 0) + 1;
            }
        });

        try {
            $run();
        } finally {
            $active['on'] = false;
        }

        return $log->getArrayCopy();
    }

    private static function that(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new ReportConformanceFailure($message);
        }
    }
}
