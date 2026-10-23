<?php

use App\Contracts\Reports\Report;
use App\Enums\ExportFormat;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Support\Csv\SafeCsv;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Reports\DemoReport;

/**
 * The report registry and the synchronous export pipeline (ADR 0025).
 */
beforeEach(function () {
    DemoReport::$rows = [DemoReport::row('Asha Rai', 1_000_000), DemoReport::row('सुनिता श्रेष्ठ', -130_000, 2)];
    DemoReport::$sensitive = false;
    DemoReport::$bom = null;
    app(ReportRegistry::class)->register(DemoReport::class);
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

test('registering a report adds its two permissions, the export one sensitive when the report says so', function () {
    $permissions = app(PermissionRegistry::class);

    expect($permissions->exists('reports.demo_sales.view'))->toBeTrue()
        ->and($permissions->exists('reports.demo_sales.export'))->toBeTrue()
        ->and($permissions->isSensitive('reports.demo_sales.export'))->toBeFalse();

    DemoReport::$sensitive = true;
    $fresh = new ReportRegistry(app(Container::class), $sensitive = new PermissionRegistry);
    $fresh->register(DemoReport::class);

    expect($sensitive->isSensitive('reports.demo_sales.export'))->toBeTrue()->and($sensitive->isSensitive('reports.demo_sales.view'))->toBeFalse();
});

test('a duplicate or malformed report key fails when it is registered', function () {
    expect(fn () => app(ReportRegistry::class)->register(DemoReport::class))->toThrow(LogicException::class, 'already registered');

    $bad = new class extends DemoReport
    {
        public function key(): string
        {
            return 'Bad Key';
        }
    };

    expect(fn () => app(ReportRegistry::class)->register($bad::class))->toThrow(InvalidArgumentException::class, 'Invalid report key');
    expect(fn () => app(ReportRegistry::class)->register(stdClass::class))->toThrow(InvalidArgumentException::class);
    expect(app(ReportRegistry::class)->get('demo_sales'))->toBeInstanceOf(Report::class);
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
        ->and($lines[5])->toBe(['', 'Net', '', '8700.00']);
});

test('a layout picks the columns; an unknown layout is refused', function () {
    actingAsStaff(['reports.demo_sales.export']);

    $lines = parseCsv(runExport(layout: 'short')[1]);
    expect($lines[2])->toBe(['Buyer', 'Amount (Rs)'])->and($lines[3])->toBe(['Asha Rai', '10000.00']);

    expect(fn () => runExport(layout: 'nope'))->toThrow(InvalidArgumentException::class);
});

test('the BOM follows the report, then the setting', function () {
    actingAsStaff(['reports.demo_sales.export']);

    DemoReport::$bom = true;
    expect(runExport()[1])->toStartWith(SafeCsv::BOM);

    DemoReport::$bom = null;
    expect(runExport()[1])->not->toStartWith(SafeCsv::BOM);

    app(Settings::class)->set('reports.csv_bom', true);
    expect(runExport()[1])->toStartWith(SafeCsv::BOM);

    DemoReport::$bom = false;
    expect(runExport()[1])->not->toStartWith(SafeCsv::BOM);
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

test('export needs the export permission; viewing is not enough and nothing is logged', function () {
    actingAsStaff(['reports.demo_sales.view']);

    expect(fn () => runExport())->toThrow(AuthorizationException::class);
    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);
});

test('a sensitive export needs a recent password confirmation', function () {
    DemoReport::$sensitive = true;
    actingAsStaff(['reports.demo_sales.export']);

    expect(fn () => runExport())->toThrow(HttpException::class, 'Password confirmation required.');
    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);

    // The check reads the request's session, as the step-up middleware does.
    $request = Request::create('/admin/reports/demo');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('auth.password_confirmed_at', time());
    app()->instance('request', $request);

    expect(fn () => runExport())->not->toThrow(HttpException::class);
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

    $query = app(ReportExporter::class)->query('demo_sales', ['from' => '2026-10-01', 'to' => '2026-10-31'], auth()->id());

    // Kathmandu is UTC+05:45, so the company day starts at 18:15 UTC the day before.
    expect($query->startsAt->toIso8601String())->toBe('2026-09-30T18:15:00+00:00')
        ->and($query->endsAt->toIso8601String())->toBe('2026-10-31T18:15:00+00:00');
});
