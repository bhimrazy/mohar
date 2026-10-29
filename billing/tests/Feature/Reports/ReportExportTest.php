<?php

use App\Contracts\Reports\Report;
use App\Enums\ColumnType;
use App\Enums\ExportFormat;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Support\CalendarDate;
use App\Support\Csv\SafeCsv;
use App\Support\Csv\Verbatim;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Reports\CellFormatter;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Reports\DemoBomReport;
use Tests\Support\Reports\DemoReport;
use Tests\Support\Reports\DemoSensitiveReport;

/**
 * The report registry and the synchronous export pipeline (ADR 0025).
 */
beforeEach(function () {
    DemoReport::$rows = [DemoReport::row('Asha Rai', 1_000_000), DemoReport::row('सुनिता श्रेष्ठ', -130_000, 2)];
    DemoBomReport::$bom = true;
    app(ReportRegistry::class)->register('demo_sales', DemoReport::class);
});

/**
 * Runs the export the way a controller would and returns [response, body].
 *
 * @return array{0: StreamedResponse, 1: string}
 */
function runExport(ExportFormat $format = ExportFormat::Csv, ?string $layout = null, array $input = ['from' => '2026-10-01', 'to' => '2026-10-31']): array
{
    $exporter = app(ReportExporter::class);
    $query = $exporter->query('demo_sales', $input, auth()->id());
    $response = $exporter->export('demo_sales', $query, $format, $layout);

    ob_start();
    $response->sendContent();

    return [$response, (string) ob_get_clean()];
}

/** @return list<list<string|null>> */
function parseCsv(string $body): array
{
    return array_map(fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\n", $body), fn (string $line): bool => $line !== '')));
}

test('registering a report adds its two permissions without building it; the export one is sensitive for a marked class', function () {
    $permissions = new PermissionRegistry;
    $registry = new ReportRegistry(app(Container::class), $permissions);
    $built = 0;

    $registry->register('demo_sales', function () use (&$built): Report {
        $built++;

        return new DemoReport;
    });
    $registry->register('demo_secret', DemoSensitiveReport::class);

    expect($built)->toBe(0)->and($registry->keys())->toBe(['demo_sales', 'demo_secret'])
        ->and($permissions->exists('reports.demo_sales.view'))->toBeTrue()
        ->and($permissions->isSensitive('reports.demo_sales.export'))->toBeFalse()
        ->and($permissions->isSensitive('reports.demo_secret.export'))->toBeTrue()
        ->and($permissions->isSensitive('reports.demo_secret.view'))->toBeFalse()
        ->and($permissions->all()['Reports']['reports.demo_sales.export'])->toBe('Export the report: Demo sales')
        ->and($built)->toBe(1);
});

test('permission labels are translated when the list is read', function () {
    $permissions = new PermissionRegistry;
    (new ReportRegistry(app(Container::class), $permissions))->register('demo_sales', DemoReport::class);
    app('translator')->addLines(['*.Export the report: :name' => 'रिपोर्ट निर्यात: :name'], 'ne');
    app()->setLocale('ne');

    expect($permissions->all()['Reports']['reports.demo_sales.export'])->toBe('रिपोर्ट निर्यात: Demo sales');
});

test('a duplicate key needs replace, a malformed key or class is refused, and the key must match on resolve', function () {
    $registry = app(ReportRegistry::class);

    expect(fn () => $registry->register('demo_sales', DemoReport::class))->toThrow(LogicException::class, 'already registered');
    $replacement = new class extends DemoReport {};
    $registry->register('demo_sales', $replacement::class, replace: true);
    expect($registry->get('demo_sales'))->toBeInstanceOf($replacement::class);

    expect(fn () => $registry->register('Bad Key', DemoReport::class))->toThrow(InvalidArgumentException::class, 'Invalid report key');
    expect(fn () => $registry->register('x', stdClass::class))->toThrow(InvalidArgumentException::class);

    $registry->register('wrong_key', DemoReport::class);
    expect(fn () => $registry->get('wrong_key'))->toThrow(LogicException::class, 'reports its key as [demo_sales]');

    $registry->register('demo_secret', fn () => new DemoSensitiveReport);
    expect(fn () => $registry->get('demo_secret'))->toThrow(LogicException::class);
});

test('the CSV has the header block, headings, typed cells and the totals; money stays a number', function () {
    actingAsStaff(['reports.demo_sales.export']);

    [$response, $body] = runExport();
    $lines = parseCsv($body);

    expect($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('content-disposition'))->toContain('demo_sales-detail-')->toContain('.csv')
        ->and($body)->not->toStartWith(SafeCsv::BOM)
        ->and($lines[0])->toBe(['PAN', '123456789'])
        ->and($lines[1])->toBe(['Period', '2026-10-01 to 2026-10-31'])
        ->and($lines[2])->toBe(['Date', 'Buyer', 'Qty', 'Amount (Rs)'])
        ->and($lines[3])->toBe(['2026-10-20', 'Asha Rai', '1', '10000.00'])
        ->and($lines[4])->toBe(['2026-10-20', 'सुनिता श्रेष्ठ', '2', '-1300.00'])
        ->and($lines[5])->toBe(['', 'Net NPR', '', '8700.00']);
});

test('a layout picks the columns; an unknown layout or report is a validation error', function () {
    actingAsStaff(['reports.demo_sales.export']);

    $lines = parseCsv(runExport(layout: 'short')[1]);
    expect($lines[2])->toBe(['Buyer', 'Amount (Rs)'])->and($lines[3])->toBe(['Asha Rai', '10000.00']);

    expect(fn () => runExport(layout: 'nope'))->toThrow(ValidationException::class)
        ->and(fn () => app(ReportExporter::class)->query('nope', [], 1))->toThrow(ValidationException::class);
});

test('the BOM follows the report when it sets one, otherwise the setting', function () {
    actingAsStaff(['reports.demo_sales.export', 'reports.demo_bom.export']);
    app(ReportRegistry::class)->register('demo_bom', DemoBomReport::class);
    $run = fn (string $key): string => (function () use ($key): string {
        $exporter = app(ReportExporter::class);
        $response = $exporter->export($key, $exporter->query($key, ['from' => '2026-10-01', 'to' => '2026-10-31'], auth()->id()), ExportFormat::Csv);
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    })();

    expect($run('demo_bom'))->toStartWith(SafeCsv::BOM)->and($run('demo_sales'))->not->toStartWith(SafeCsv::BOM);

    app(Settings::class)->set('reports.csv_bom', true);
    DemoBomReport::$bom = false;
    expect($run('demo_sales'))->toStartWith(SafeCsv::BOM)->and($run('demo_bom'))->not->toStartWith(SafeCsv::BOM);
});

test('text cells that start like a formula are neutralised, typed cells are not', function () {
    actingAsStaff(['reports.demo_sales.export']);
    $names = ['=SUM(A1)', '+1', '-2', '@x', "\tx", "\rx", '--', 'ok'];
    DemoReport::$rows = array_map(fn (string $name): array => DemoReport::row($name, -500), $names);

    $lines = parseCsv(runExport()[1]);
    $cells = array_map(fn (array $line): string => (string) $line[1], array_slice($lines, 3, count($names)));

    expect($cells)->toBe(["'=SUM(A1)", "'+1", "'-2", "'@x", "'\tx", "'\rx", "'--", 'ok'])
        ->and($lines[3][3])->toBe('-5.00');
});

test('SafeCsv guards strings only, quotes, and writes the BOM once', function () {
    $stream = fopen('php://memory', 'w+b');
    $csv = new SafeCsv($stream, bom: true);
    $csv->row(['=1+1', 3, -4.5, null, 'a,"b"', 'line'."\n".'two']);
    rewind($stream);

    expect(stream_get_contents($stream))->toBe(SafeCsv::BOM."'=1+1,3,-4.5,,\"a,\"\"b\"\"\",\"line\ntwo\"\n");
});

test('the PDF is a single A4 landscape document', function () {
    actingAsStaff(['reports.demo_sales.export']);
    DemoReport::$rows = array_map(fn (int $i): array => DemoReport::row('Buyer '.$i, 1_000), range(1, 60));

    [$response, $body] = runExport(ExportFormat::Pdf);

    expect($response->headers->get('content-type'))->toBe('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('.pdf')
        ->and($body)->toStartWith('%PDF')
        // A4 landscape is 841.89 x 595.28 points.
        ->and($body)->toContain('/MediaBox [0.000 0.000 841.890 595.280]');
});

/** Runs a PDF export and returns the data the `reports.pdf` view was given. */
function pdfViewData(): array
{
    $data = [];
    View::composer('reports.pdf', function ($view) use (&$data) {
        $data = $view->getData();
    });
    runExport(ExportFormat::Pdf);

    return $data;
}

test('the PDF wraps a long name, ends an extreme one with an ellipsis, and splits pages by lines', function () {
    actingAsStaff(['reports.demo_sales.export']);
    $legal = 'Himalayan Hosting and Cloud Infrastructure Services Private Limited '.str_repeat('Kathmandu Metropolitan City ', 8);
    DemoReport::$rows = [
        DemoReport::row(substr($legal, 0, 340), 1_000),
        DemoReport::row(str_repeat('Extremely long registered name ', 80), 1_000),
        ...array_map(fn (int $i): array => DemoReport::row('Tall '.str_repeat('Cloud Services ', 20).$i, 1_000), range(1, 30)),
    ];

    $pages = pdfViewData()['pages'];

    // A 340-character name is kept whole; the 2,480-character one ends in an ellipsis and stays within four lines.
    expect($pages[0]['rows'][0]['name'])->toBe(substr($legal, 0, 340))
        ->and($pages[0]['rows'][1]['name'])->toEndWith('…')->and(mb_strlen($pages[0]['rows'][1]['name']))->toBeLessThan(700)
        // Tall rows take more of a page, so fewer than the 22 single-line rows fit.
        ->and(count($pages[0]['rows']))->toBeLessThan(14)->and(count($pages))->toBeGreaterThan(2)
        ->and(array_sum(array_map(fn (array $page): int => count($page['rows']), $pages)))->toBe(32);
});

test('the PDF aligns numeric headings with their figures and keeps dates left in a narrow column', function () {
    actingAsStaff(['reports.demo_sales.export']);
    $data = pdfViewData();
    $html = view('reports.pdf', $data)->render();

    // date, name, qty, amount
    expect($data['right'])->toBe([false, false, true, true])
        ->and($data['widths'][0])->toBe(round(74 / 1074 * 100, 2).'%')
        ->and($html)->toContain('table.rows th.right, .right { text-align: right; }')
        ->and($html)->toMatch('/<th class="right" style="width: [\d.]+%">Qty<\/th>/')
        ->and($html)->toMatch('/<th class="right" style="width: [\d.]+%">Amount \(Rs\)<\/th>/')
        ->and($html)->toMatch('/<th class="" style="width: [\d.]+%">Date<\/th>/');
});

test('the PDF prints the time in the company zone', function () {
    actingAsStaff(['reports.demo_sales.export']);
    app(Settings::class)->set('system.timezone', 'Asia/Kathmandu');
    Carbon::setTestNow('2026-10-23 06:40:00 UTC');

    // 06:40 UTC is 12:25 in Kathmandu (UTC+05:45).
    expect(pdfViewData()['printedAt'])->toBe('2026-10-23 12:25 (Asia/Kathmandu)');
});

test('export needs the export permission; viewing is not enough and nothing is logged', function () {
    actingAsStaff(['reports.demo_sales.view']);

    expect(fn () => runExport())->toThrow(AuthorizationException::class);
    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);
});

test('a sensitive export needs a recent password confirmation', function () {
    app(ReportRegistry::class)->register('demo_secret', DemoSensitiveReport::class);
    actingAsStaff(['reports.demo_secret.export']);
    $run = fn () => (function () {
        $exporter = app(ReportExporter::class);
        $exporter->export('demo_secret', $exporter->query('demo_secret', ['from' => '2026-10-01', 'to' => '2026-10-31'], auth()->id()), ExportFormat::Csv);
    })();

    expect($run)->toThrow(HttpException::class, 'Password confirmation required.');
    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);

    // The check reads the request's session, as the step-up middleware does.
    $request = Request::create('/admin/reports/demo');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('auth.password_confirmed_at', now()->getTimestamp());
    app()->instance('request', $request);

    expect($run)->not->toThrow(HttpException::class);
});

test('a queued sensitive export is decided for the query user: a stored confirmation time, not another request', function () {
    app(ReportRegistry::class)->register('demo_secret', DemoSensitiveReport::class);
    $owner = actingAsStaff(['reports.demo_secret.export']);
    $exporter = app(ReportExporter::class);
    $query = $exporter->query('demo_secret', ['from' => '2026-10-01', 'to' => '2026-10-31'], $owner->id);
    $export = fn (?int $at) => $exporter->export('demo_secret', $query, ExportFormat::Csv, null, $at);

    // Another user's confirmed session in the current request does not stand in for the query user's.
    $other = User::factory()->create();
    $request = Request::create('/queue');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('auth.password_confirmed_at', now()->getTimestamp());
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $other);

    expect(fn () => $export(null))->toThrow(HttpException::class, 'Password confirmation required.')
        ->and(fn () => $export(time() - RequireRecentPasswordConfirmation::timeout() - 1))->toThrow(HttpException::class)
        ->and($export(time()))->toBeInstanceOf(StreamedResponse::class);

    // The permission is the same user's: a stored time does not give a user without the export permission a file.
    $viewer = User::factory()->create();
    $unpermitted = $exporter->query('demo_secret', ['from' => '2026-10-01', 'to' => '2026-10-31'], $viewer->id);

    expect(fn () => $exporter->export('demo_secret', $unpermitted, ExportFormat::Csv, null, time()))->toThrow(AuthorizationException::class);
});

test('each export writes one report.exported entry with key, layout, format, filters and rows', function () {
    $user = actingAsStaff(['reports.demo_sales.export']);

    runExport(layout: 'short');
    runExport(ExportFormat::Pdf);

    $entries = ActivityLog::query()->where('event', 'report.exported')->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->user_id)->toBe($user->id)
        ->and($entries[0]->properties)->toBe(['report' => 'demo_sales', 'layout' => 'short', 'format' => 'csv', 'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'rows' => 2])
        ->and($entries[1]->properties['format'])->toBe('pdf')->and($entries[1]->properties['layout'])->toBe('detail');
});

test('an export over the row limit is refused with the count, and logs nothing', function () {
    actingAsStaff(['reports.demo_sales.export']);
    app(Settings::class)->set('reports.sync_export_max_rows', 1);

    expect(fn () => runExport())->toThrow(ValidationException::class, 'This report has 2 rows; a file can hold up to 1.');
    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);
});

test('filters are validated and the period is resolved on the company calendar', function () {
    actingAsStaff(['reports.demo_sales.export']);
    app(Settings::class)->set('system.timezone', 'Asia/Kathmandu');

    expect(fn () => app(ReportExporter::class)->query('demo_sales', ['from' => '2026-10-31', 'to' => '2026-10-01'], auth()->id()))->toThrow(ValidationException::class);
    // A year CalendarDate can't hold is a validation error, not a 500.
    expect(fn () => app(ReportExporter::class)->query('demo_sales', ['from' => '0000-01-01'], auth()->id()))->toThrow(ValidationException::class);

    $query = app(ReportExporter::class)->query('demo_sales', ['from' => '2026-10-01', 'to' => '2026-10-31'], auth()->id());

    // Kathmandu is UTC+05:45, so the company day starts at 18:15 UTC the day before.
    expect($query->startsAt->toIso8601String())->toBe('2026-09-30T18:15:00+00:00')
        ->and($query->endsAt->toIso8601String())->toBe('2026-10-31T18:15:00+00:00');
});

test('a Date column takes a CalendarDate as its typed value', function () {
    $cells = app(CellFormatter::class);

    expect($cells->csv(ColumnType::Date, CalendarDate::parse('2026-10-19')))->toEqual(new Verbatim('2026-10-19'))
        ->and($cells->display(ColumnType::Date, CalendarDate::parse('2026-10-19')))->toBe('2026-10-19')
        ->and($cells->display(ColumnType::Text, CalendarDate::parse('2026-10-19')))->toBe('2026-10-19');
});

test('a stored confirmation time allows 60 seconds of clock skew and no more, and follows the Carbon clock', function () {
    $user = actingAsStaff();
    $now = now()->getTimestamp();

    expect(RequireRecentPasswordConfirmation::confirmedFor($user, $now + 60))->toBeTrue()
        ->and(RequireRecentPasswordConfirmation::confirmedFor($user, $now + 61))->toBeFalse()
        ->and(RequireRecentPasswordConfirmation::confirmedFor($user, 0))->toBeFalse();

    $this->travel(16)->minutes();

    expect(RequireRecentPasswordConfirmation::confirmedFor($user, $now))->toBeFalse()
        ->and(RequireRecentPasswordConfirmation::confirmedFor($user, now()->getTimestamp()))->toBeTrue();
});
