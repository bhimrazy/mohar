<?php

use App\Data\Reports\ReportQuery;
use App\Enums\ExportFormat;
use App\Models\User;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use App\Support\Reports\ReportViewer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Reports\DemoReport;
use Tests\Support\Reports\UserListReport;

/**
 * Paging a report on screen (#355): bounds, the view permission, stable order, and exports unchanged.
 */
beforeEach(function () {
    DemoReport::$rows = array_map(fn (int $i): array => DemoReport::row('Buyer '.$i, $i * 100), range(1, 25));
    app(ReportRegistry::class)->register('demo_sales', DemoReport::class);
});

function viewQuery(): ReportQuery
{
    return app(ReportExporter::class)->query('demo_sales', ['from' => '2026-10-01', 'to' => '2026-10-31'], auth()->id());
}

test('pages are consecutive, carry the total, and the last one is short', function () {
    actingAsStaff(['reports.demo_sales.view']);
    $viewer = app(ReportViewer::class);

    $names = [];

    foreach ([0, 10, 20] as $offset) {
        $page = $viewer->page('demo_sales', viewQuery(), 10, $offset);
        expect($page->total)->toBe(25)->and($page->limit)->toBe(10)->and($page->offset)->toBe($offset);
        array_push($names, ...array_column($page->rows, 'name'));
    }

    expect($names)->toBe(array_column(DemoReport::$rows, 'name'))
        ->and($viewer->page('demo_sales', viewQuery(), 10, 30)->rows)->toBe([]);
});

test('a report that ignores the page cannot make the viewer return more than the limit', function () {
    actingAsStaff(['reports.greedy.view']);

    $report = new class extends DemoReport
    {
        protected string $key = 'greedy';

        public function rows(ReportQuery $query): iterable
        {
            yield from DemoReport::$rows;
        }
    };
    app(ReportRegistry::class)->register('greedy', fn () => $report);

    $page = app(ReportViewer::class)->page('greedy', new ReportQuery([], auth()->id()), 5);

    expect($page->rows)->toHaveCount(5)->and($page->total)->toBe(25);
});

test('limit and offset outside the bounds are refused with a message per field', function (int $limit, int $offset, string $field) {
    actingAsStaff(['reports.demo_sales.view']);

    try {
        app(ReportViewer::class)->page('demo_sales', viewQuery(), $limit, $offset);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe([$field]);
    }
})->with([
    'zero limit' => [0, 0, 'limit'],
    'over the max' => [ReportQuery::MAX_LIMIT + 1, 0, 'limit'],
    'negative offset' => [10, -1, 'offset'],
    'offset too deep' => [10, ReportQuery::MAX_OFFSET + 1, 'offset'],
]);

test('the largest page is accepted and the query itself rejects bad values', function () {
    actingAsStaff(['reports.demo_sales.view']);

    expect(app(ReportViewer::class)->page('demo_sales', viewQuery(), ReportQuery::MAX_LIMIT)->rows)->toHaveCount(25)
        ->and(fn () => new ReportQuery([], 1, limit: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ReportQuery([], 1, offset: 5))->toThrow(InvalidArgumentException::class);
});

test('page() refuses without the view permission, even with the export permission, and for an unknown report', function () {
    actingAsStaff(['reports.demo_sales.export']);
    $viewer = app(ReportViewer::class);

    expect(fn () => $viewer->page('demo_sales', viewQuery(), 10))->toThrow(AuthorizationException::class)
        ->and(fn () => $viewer->page('demo_sales', new ReportQuery([], null), 10))->toThrow(AuthorizationException::class)
        ->and(fn () => $viewer->page('nope', viewQuery(), 10))->toThrow(ValidationException::class);
});

test('an export of a paged query still writes every row', function () {
    actingAsStaff(['reports.demo_sales.export']);
    $exporter = app(ReportExporter::class);

    $response = $exporter->export('demo_sales', viewQuery()->page(5, 0), ExportFormat::Csv);
    ob_start();
    $response->sendContent();
    $lines = array_filter(explode("\n", (string) ob_get_clean()), fn (string $l): bool => str_contains($l, 'Buyer '));

    expect($lines)->toHaveCount(25);
});

test('a database-backed report pages in a stable order, including equal names', function () {
    app(ReportRegistry::class)->register('user_list', UserListReport::class);
    actingAsStaff(['reports.user_list.view']);
    User::factory()->count(6)->create(['name' => 'Same Name']);
    User::factory()->count(3)->create();

    $query = new ReportQuery([], auth()->id());
    $viewer = app(ReportViewer::class);
    $total = $viewer->page('user_list', $query, 200)->total;
    $all = array_column($viewer->page('user_list', $query, 200)->rows, 'name');
    $paged = [];

    for ($offset = 0; $offset < $total; $offset += 4) {
        array_push($paged, ...array_column($viewer->page('user_list', $query, 4, $offset)->rows, 'name'));
    }

    expect($total)->toBeGreaterThanOrEqual(10)->and($paged)->toBe($all);
});
