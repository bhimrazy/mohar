<?php

use App\Contracts\Reports\Report;
use App\Data\Reports\ReportQuery;
use App\Enums\ExportFormat;
use App\Modules\Core\Support\Settings;
use App\Support\Reports\ReportExporter;
use App\Support\Reports\ReportRegistry;
use Illuminate\Validation\ValidationException;
use Tests\Support\Reports\DemoReport;

/**
 * Architect review probes for PR #352 (ADR 0025). Each asserts the behaviour the review asks for, so it fails on
 * a0637242 and passes once the finding is fixed.
 */
beforeEach(function () {
    DemoReport::$rows = [];
    DemoReport::$sensitive = false;
    DemoReport::$bom = null;
});

function pr352Export(string $key, ExportFormat $format): string
{
    $exporter = app(ReportExporter::class);
    $response = $exporter->export($key, $exporter->query($key, ['from' => '2026-10-01', 'to' => '2026-10-31'], (int) auth()->id()), $format);
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

// Finding 2: the row limit is checked against count() only; rows() past it are all written.
test('probe: an export never writes more data rows than the sync limit', function () {
    actingAsStaff(['reports.undercounted.export']);
    app(Settings::class)->set('reports.sync_export_max_rows', 100);
    DemoReport::$rows = array_map(fn (int $i): array => DemoReport::row('B'.$i, 1_000), range(1, 300));
    app(ReportRegistry::class)->register(Pr352UndercountedReport::class);

    // Header block (2) + headings (1) + at most 100 rows + totals (1).
    expect(substr_count(pr352Export('undercounted', ExportFormat::Csv), "\n"))->toBeLessThanOrEqual(104);
});

// Finding 2: a PDF at the default limit (5,000 rows) must be refused or fit the 256M production memory_limit.
test('probe: a PDF at the default sync limit is refused or stays under 256 MiB', function () {
    actingAsStaff(['reports.demo_sales.export']);
    app(ReportRegistry::class)->register(DemoReport::class);
    DemoReport::$rows = array_map(fn (int $i): array => DemoReport::row('Buyer '.$i, 1_000), range(1, 5000));
    memory_reset_peak_usage();

    try {
        pr352Export('demo_sales', ExportFormat::Pdf);
    } catch (ValidationException) {
        expect(true)->toBeTrue();

        return;
    }

    expect(memory_get_peak_usage(true))->toBeLessThan(256 * 1024 * 1024);
});

final class Pr352UndercountedReport extends DemoReport implements Report
{
    public function key(): string
    {
        return 'undercounted';
    }

    public function count(ReportQuery $query): int
    {
        return 1;
    }
}
