<?php

use App\Enums\ExportFormat;
use App\Support\Money;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use Illuminate\Support\Facades\View;
use Tests\Support\Reports\DemoReport;
use Tests\Support\Reports\ManyTextReport;

/**
 * The PDF page plan against the real render: the writer plans pages from the glyph widths of the PDF font, and the
 * rendered document must have exactly the pages it planned (a wrong estimate spills rows onto an extra page and
 * leaves a page total apart from its rows).
 */
beforeEach(function () {
    ManyTextReport::$textColumns = 3;
    ManyTextReport::$header = [['PAN', '123456789'], ['Period', '2026-10-01 to 2026-10-31']];
    ManyTextReport::$ownView = false;
    app(ReportRegistry::class)->register('demo_sales', ManyTextReport::class);
    View::addNamespace('report-tests', base_path('tests/Support/Reports/views'));
    actingAsStaff(['reports.demo_sales.export']);
});

/**
 * Exports the rows as a PDF.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return array{planned: int, printed: int, rows: list<int>, data: array<string, mixed>}
 */
function exportPdf(array $rows): array
{
    DemoReport::$rows = $rows;
    $data = [];
    View::composer('reports.pdf', function ($view) use (&$data) {
        $data = $view->getData();
    });
    $exporter = app(ReportExporter::class);
    $response = $exporter->export('demo_sales', $exporter->query('demo_sales', ['from' => '2026-10-01', 'to' => '2026-10-31'], auth()->id()), ExportFormat::Pdf);
    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();
    preg_match_all('/\/Type\s*\/Page[^s]/', $body, $pages);

    return ['planned' => count($data['pages']), 'printed' => count($pages[0]), 'rows' => array_map(fn (array $page): int => count($page['rows']), $data['pages']), 'data' => $data];
}

/** @return list<array<string, mixed>> */
function repeatRows(string $text, int $count = 96): array
{
    return array_map(fn () => ManyTextReport::textRow($text), range(1, $count));
}

test('the PDF has the pages it planned for text of every width', function (string $text, int $columns, int $headerRows, int $headerLength) {
    ManyTextReport::$textColumns = $columns;
    ManyTextReport::$header = array_map(fn (int $i): array => ['Label '.$i, substr(str_repeat('Long header value ', 40), 0, $headerLength)], range(1, $headerRows));

    $result = exportPdf(repeatRows($text, 60));

    expect($result['printed'])->toBe($result['planned'])->and($result['planned'])->toBeGreaterThan(1);
})->with([
    'all capitals in 3 columns' => ['HIMALAYAN CLOUD HOSTING AND SERVICES PVT LTD KATHMANDU NP', 3, 2, 10],
    '150 capital Ms in one column' => [str_repeat('M', 150), 1, 2, 10],
    '5 columns of wide capitals' => [str_repeat('WWWWWWWW ', 60), 5, 2, 10],
    '12 long header rows' => ['Kathmandu Metropolitan City Ward Office', 3, 12, 216],
    '180-character URLs in 5 columns' => ['https://example.com/'.str_repeat('a', 180), 5, 0, 0],
    'mixed case' => ['Himalayan Cloud Hosting Pvt Ltd Kathmandu Metropolitan', 3, 2, 10],
    'narrow letters' => [str_repeat('i l ', 40), 5, 0, 0],
]);

test('a header taller than half a page is cut and the PDF still has the pages it planned (#367)', function () {
    ManyTextReport::$header = array_map(fn (int $i): array => ['Label '.$i, str_repeat('Long header value ', 20)], range(1, 30));

    $result = exportPdf(repeatRows('Kathmandu Metropolitan City Ward Office', 40));
    $header = $result['data']['header'];

    expect($result['printed'])->toBe($result['planned'])
        ->and(count($header))->toBeLessThan(31)
        ->and(end($header)[0])->toBe('…')
        ->and(mb_strlen($header[0][1]))->toBe(360);
});

test('one header value of several thousand characters is cut with an ellipsis', function () {
    ManyTextReport::$header = [['Notes', str_repeat('Long header value ', 200)]];

    $result = exportPdf(repeatRows('Asha Rai', 3));

    expect($result['data']['header'][0][1])->toEndWith('…')->and(mb_strlen($result['data']['header'][0][1]))->toBeLessThan(800)
        ->and($result['printed'])->toBe($result['planned']);
});

test('a short header is printed whole', function () {
    $result = exportPdf(repeatRows('Asha Rai', 3));

    expect($result['data']['header'])->toBe([['PAN', '123456789'], ['Period', '2026-10-01 to 2026-10-31']]);
});

test('the grand totals and the page totals of several currencies fit the page they are planned on', function (int $count) {
    $rows = array_map(fn (int $i): array => [...ManyTextReport::textRow('Buyer '.$i), 'amount' => Money::of(1_000 + $i, ['NPR', 'USD', 'EUR'][$i % 3])], range(1, $count));

    $result = exportPdf($rows);

    expect($result['printed'])->toBe($result['planned']);
})->with([5, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28]);

test('a cut name ends in an ellipsis after a whole character, not inside a conjunct or an emoji sequence', function () {
    ManyTextReport::$textColumns = 1;
    $result = exportPdf([
        ManyTextReport::textRow(str_repeat('सुनिता श्रेष्ठ व्यवस्थापन ', 60)),
        ManyTextReport::textRow(str_repeat('👨‍👩‍👧 ', 300)),
        ManyTextReport::textRow(str_repeat('Extremely long registered name ', 80)),
    ]);

    foreach ($result['data']['pages'][0]['rows'] as $row) {
        expect($row['t0'])->toEndWith('…');
    }

    // The family emoji is one sequence; the cut keeps it whole or drops it, never leaves half of it.
    $emoji = $result['data']['pages'][0]['rows'][1]['t0'];
    preg_match_all('/\X/u', $emoji, $graphemes);
    expect(array_unique(array_slice($graphemes[0], 0, -1)))->toBe(['👨‍👩‍👧', ' ']);
});

test('a report with its own table view still renders, and its text is escaped', function () {
    ManyTextReport::$ownView = true;
    ManyTextReport::$textColumns = 1;

    $result = exportPdf([ManyTextReport::textRow('Asha <b>Rai</b>'), DemoReport::row('Second', 5_000) + ManyTextReport::textRow('x')]);
    $html = view('reports.pdf', $result['data'])->render();

    expect($result['printed'])->toBe($result['planned'])
        ->and($html)->toContain('id="own-table"')
        ->and($html)->not->toContain('<b>Rai</b>');
});
