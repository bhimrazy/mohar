<?php

declare(strict_types=1);

/*
 * Invoice PDF throughput and memory per render (loadtest/README.md). Renders invoices with the app's own
 * InvoicePdf::render() (dompdf), one after another in one PHP process, without storing them, and prints JSON.
 * Read-only, so it runs in the app container as is:
 *
 *   docker compose ... exec -T app php /loadtest/bench/pdf.php --count=60
 *
 * Options: --app=/app, --count=N (default 60), --warmup=N (default 3, not measured: fonts and views load once).
 */

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\InvoicePdf;
use Illuminate\Contracts\Console\Kernel;

$options = getopt('', ['app:', 'count:', 'warmup:']);
$root = rtrim((string) ($options['app'] ?? '/app'), '/');
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$count = max(1, (int) ($options['count'] ?? 60));
$warmup = max(0, (int) ($options['warmup'] ?? 3));
$pdf = $app->make(InvoicePdf::class);

// A spread of invoices across the table (ids are in issue order), paid and unpaid, all currencies.
$max = (int) Invoice::query()->max('id');
$min = (int) Invoice::query()->min('id');
$ids = [];

for ($n = 0; $n < $count + $warmup; $n++) {
    $ids[] = $min + (int) floor(($max - $min) * ($n + 0.5) / ($count + $warmup));
}

$timings = [];
$memory = [];
$bytes = [];
$baseline = memory_get_usage(true);

foreach ($ids as $index => $id) {
    $invoice = Invoice::query()->where('id', '>=', $id)->orderBy('id')->first();

    if ($invoice === null) {
        continue;
    }

    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);
    $output = $pdf->render($invoice);
    $elapsed = (hrtime(true) - $started) / 1e6;

    if ($index >= $warmup) {
        $timings[] = $elapsed;
        $memory[] = (memory_get_peak_usage() - $before) / 1048576;
        $bytes[] = strlen($output);
    }

    unset($output, $invoice);
}

sort($timings);
$percentile = fn (array $sorted, float $p): float => $sorted === [] ? 0.0 : $sorted[min(count($sorted) - 1, (int) floor($p * (count($sorted) - 1) + 0.5))];
$total = array_sum($timings) / 1000;

echo json_encode([
    'renders' => count($timings),
    'seconds' => round($total, 2),
    'renders_per_second_one_process' => $total > 0 ? round(count($timings) / $total, 2) : null,
    'ms_p50' => round($percentile($timings, 0.5), 1),
    'ms_p95' => round($percentile($timings, 0.95), 1),
    'ms_max' => round((float) end($timings), 1),
    'peak_memory_mb_per_render_max' => round(max($memory ?: [0]), 1),
    'peak_memory_mb_per_render_avg' => round(array_sum($memory) / max(1, count($memory)), 1),
    'process_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    'process_baseline_mb' => round($baseline / 1048576, 1),
    'pdf_kb_avg' => round(array_sum($bytes) / max(1, count($bytes)) / 1024, 1),
], JSON_PRETTY_PRINT).PHP_EOL;
