<?php

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilter;
use App\Data\Reports\ReportQuery;
use App\Enums\ColumnType;
use App\Enums\ExportFormat;
use App\Enums\FilterType;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\Settings;
use App\Support\Csv\SafeCsv;
use App\Support\Money;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\Support\Reports\DemoReport;
use Tests\Support\Reports\DemoSensitiveReport;

/**
 * Review probes for PR #352 (architect 4d58cd9c and review board), adopted as tests. Each fails at a0637242.
 */
class Pr352UndercountedReport extends DemoReport
{
    protected string $key = 'undercounted';

    public static int $claimed = 1;

    public function count(ReportQuery $query): int
    {
        return self::$claimed;
    }
}

class Pr352LooseReport extends DemoReport
{
    protected string $key = 'loose';

    public function filters(): array
    {
        return [new ReportFilter('from', 'From', FilterType::Date), new ReportFilter('client', 'Client', FilterType::Select, ['1' => 'A'])];
    }
}

class Pr352MixedReport extends DemoReport
{
    protected string $key = 'mixed';

    public function columns(ReportQuery $query): array
    {
        return [new ReportColumn('name', 'Buyer'), new ReportColumn('amount', 'Amount', ColumnType::Money)];
    }
}

beforeEach(function () {
    DemoReport::$rows = [DemoReport::row('Asha', 100)];
    app(ReportRegistry::class)->register('demo_sales', DemoReport::class);
});

function pr352Run(string $key, array $input = ['from' => '2026-10-01', 'to' => '2026-10-31'], ExportFormat $format = ExportFormat::Csv): string
{
    $exporter = app(ReportExporter::class);
    $response = $exporter->export($key, $exporter->query($key, $input, (int) auth()->id()), $format);
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

test('probe: an export never writes more rows than the limit even when count() lies', function () {
    actingAsStaff(['reports.undercounted.export']);
    app(Settings::class)->set('reports.sync_export_max_rows', 100);
    DemoReport::$rows = array_map(fn (int $i): array => DemoReport::row('B'.$i, 1_000), range(1, 3000));
    app(ReportRegistry::class)->register('undercounted', Pr352UndercountedReport::class);
    Log::spy();

    // Header block (2) + headings (1) + at most 100 rows + totals (1).
    expect(substr_count(pr352Run('undercounted'), "\n"))->toBeLessThanOrEqual(104);
    Log::shouldHaveReceived('warning')->once();
});

test('probe: a PDF whose count() lies is cut at the limit and its audit entry records the rows written', function () {
    actingAsStaff(['reports.undercounted.export']);
    app(Settings::class)->set('reports.sync_pdf_max_rows', 100);
    DemoReport::$rows = array_map(fn (int $i): array => DemoReport::row('B'.$i, 1_000), range(1, 300));
    app(ReportRegistry::class)->register('undercounted', Pr352UndercountedReport::class);

    pr352Run('undercounted', format: ExportFormat::Pdf);

    expect(ActivityLog::query()->where('event', 'report.exported')->latest('id')->first()->properties['rows'])->toBe(100);
});

test('probe: a PDF over the default PDF limit is refused before anything is built', function () {
    actingAsStaff(['reports.demo_sales.export']);
    DemoReport::$rows = array_map(fn (int $i): array => DemoReport::row('Buyer '.$i, 1_000), range(1, 1001));

    expect(fn () => pr352Run('demo_sales', format: ExportFormat::Pdf))->toThrow(ValidationException::class, 'a PDF can hold up to 1,000');
    expect(ActivityLog::query()->where('event', 'report.exported')->count())->toBe(0);
});

test('probe: a PDF at the PDF limit stays under 128 MiB of extra peak memory', function () {
    actingAsStaff(['reports.demo_sales.export']);
    DemoReport::$rows = array_map(fn (int $i): array => DemoReport::row('Buyer name number '.$i.' Pvt Ltd', 123456 + $i, $i), range(1, 1000));
    gc_collect_cycles();
    memory_reset_peak_usage();
    $base = memory_get_usage();

    $body = pr352Run('demo_sales', format: ExportFormat::Pdf);

    expect($body)->toStartWith('%PDF')->and(memory_get_peak_usage() - $base)->toBeLessThan(128 * 1024 * 1024);
});

test('probe: SafeCsv guards formulas after white space and in full-width form', function (string $cell) {
    $stream = fopen('php://memory', 'w+b');
    (new SafeCsv($stream))->row([$cell]);
    rewind($stream);

    expect(str_getcsv((string) stream_get_contents($stream), ',', '"', '')[0])->toBe("'".$cell);
})->with([' =1+1', "\u{3000}=1+1", '＝1+1', '＋1', '－1', '＠x', "\n=1+1", "\x00=1+1", "\u{00A0}@x"]);

test('probe: SafeCsv leaves a leading quote and ordinary text alone', function () {
    expect(SafeCsv::guard('"=1+1'))->toBe('"=1+1')->and(SafeCsv::guard(' hello'))->toBe(' hello')->and(SafeCsv::guard('a=b'))->toBe('a=b');
});

test('probe: filters are typed; bad dates, arrays, non-options and unknown keys are validation errors', function (array $input) {
    actingAsStaff(['reports.loose.export']);
    app(ReportRegistry::class)->register('loose', Pr352LooseReport::class);

    expect(fn () => app(ReportExporter::class)->query('loose', $input, (int) auth()->id()))->toThrow(ValidationException::class);
})->with([[['from' => 'garbage']], [['from' => ['x']]], [['from' => 'tomorrow']], [['client' => '999']], [['client' => ['a' => 1]]], [['evil' => 'x']]]);

test('probe: a good typed filter is accepted and the end date may not precede the start', function () {
    actingAsStaff(['reports.loose.export', 'reports.demo_sales.export']);
    app(ReportRegistry::class)->register('loose', Pr352LooseReport::class);

    expect(app(ReportExporter::class)->query('loose', ['from' => '2026-10-01', 'client' => '1'], (int) auth()->id())->from)->toBe('2026-10-01');
    expect(fn () => app(ReportExporter::class)->query('demo_sales', ['from' => '2026-10-31', 'to' => '2026-10-01'], (int) auth()->id()))->toThrow(ValidationException::class);
});

test('probe: the audit entry logs filter keys and non-personal values only', function () {
    actingAsStaff(['reports.demo_sales.export']);

    pr352Run('demo_sales', ['from' => '2026-10-01', 'to' => '2026-10-31', 'buyer' => 'asha@example.com', 'status' => 'paid']);

    $filters = ActivityLog::query()->where('event', 'report.exported')->latest('id')->first()->properties['filters'];
    expect($filters)->toBe(['from' => '2026-10-01', 'to' => '2026-10-31', 'buyer' => '[not logged]', 'status' => 'paid']);
});

test('probe: PDF totals are kept per currency and never added across currencies', function () {
    actingAsStaff(['reports.mixed.export']);
    app(ReportRegistry::class)->register('mixed', Pr352MixedReport::class);
    DemoReport::$rows = [['name' => 'a', 'amount' => Money::of(10000, 'NPR')], ['name' => 'b', 'amount' => Money::of(10000, 'USD')]];
    $seen = null;
    View::composer('reports.pdf', function ($view) use (&$seen): void {
        $seen = $view->getData();
    });

    pr352Run('mixed', format: ExportFormat::Pdf);

    expect(array_column($seen['pages'][0]['totals'], 'name'))->toBe(['Page total (NPR)', 'Page total (USD)'])
        ->and(array_column($seen['pages'][0]['totals'], 'amount'))->toBe(['Rs 100.00', '$100.00'])
        ->and(array_column($seen['totals'], 'amount'))->toBe(['Rs 100.00', '$100.00']);
});

test('probe: PDF money uses the currency display, lakh grouping for NPR', function () {
    actingAsStaff(['reports.mixed.export']);
    app(ReportRegistry::class)->register('mixed', Pr352MixedReport::class);
    DemoReport::$rows = [['name' => 'a', 'amount' => Money::of(12345600, 'NPR')]];
    $seen = null;
    View::composer('reports.pdf', function ($view) use (&$seen): void {
        $seen = $view->getData();
    });

    pr352Run('mixed', format: ExportFormat::Pdf);

    expect($seen['pages'][0]['rows'][0]['amount'])->toBe('Rs 1,23,456.00');
});

test('probe: an unknown report or layout is a validation error, not a 500', function () {
    actingAsStaff(['reports.demo_sales.export']);
    $exporter = app(ReportExporter::class);

    expect(fn () => $exporter->query('nope', [], 1))->toThrow(ValidationException::class);
    expect(fn () => $exporter->export('nope', new ReportQuery([], 1), ExportFormat::Csv))->toThrow(ValidationException::class);
    expect(fn () => $exporter->export('demo_sales', $exporter->query('demo_sales', ['from' => '2026-10-01', 'to' => '2026-10-31'], auth()->id()), ExportFormat::Csv, 'nope'))->toThrow(ValidationException::class);
});

test('probe: HTTP statuses and headers of an export through a real route', function () {
    app(ReportRegistry::class)->register('demo_secret', DemoSensitiveReport::class);
    Route::middleware('web')->get('/pr352-export/{key}', function (string $key) {
        $exporter = app(ReportExporter::class);

        return $exporter->export($key, $exporter->query($key, ['from' => '2026-10-01', 'to' => '2026-10-31'], auth()->id()), ExportFormat::Csv);
    });

    actingAsStaff(['reports.demo_sales.export']);
    $ok = $this->get('/pr352-export/demo_sales')->assertOk();
    expect($ok->headers->get('cache-control'))->toContain('no-store')
        ->and($ok->headers->get('content-disposition'))->toContain('filename=demo_sales-detail-')
        ->and($ok->headers->get('content-disposition'))->not->toContain('report.csv');

    actingAsStaff(['reports.demo_sales.view']);
    $this->get('/pr352-export/demo_sales')->assertForbidden();

    actingAsStaff(['reports.demo_secret.export']);
    $this->get('/pr352-export/demo_secret')->assertStatus(423);
    $this->withSession(['auth.password_confirmed_at' => time()])->get('/pr352-export/demo_secret')->assertOk();

    actingAsStaff(['reports.*']);
    $this->withSession(['auth.password_confirmed_at' => time()])->get('/pr352-export/demo_secret')->assertForbidden();

    actingAsStaff(['*']);
    $this->withSession(['auth.password_confirmed_at' => time()])->get('/pr352-export/demo_secret')->assertOk();
});
