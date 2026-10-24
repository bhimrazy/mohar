<?php

/**
 * Race helper (VAT PR 3b, ADR 0024 §4, §8): one old path in its own process, started at a common instant so two of them meet
 * on the invoice lock. Started by runRaceWorkers().
 *
 *   php old-path-worker.php <label> cancel <database> <invoice id> <start at ms>
 *   php old-path-worker.php <label> pay    <database> <invoice id> <start at ms> <gross>
 *   php old-path-worker.php <label> fee    <database> <invoice id> <start at ms>
 *
 * Prints {label, database, transaction_level, outcome}.
 */

use App\Models\User;
use App\Modules\Billing\Actions\ApplyLateFee;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\CancelInvoice;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Models\Invoice;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $mode, $database, $invoiceId, $startAt] = $argv;
$gross = (int) ($argv[6] ?? 0);
$base = dirname(__DIR__, 4);

putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), ...$result]));
$wait = fn () => usleep((int) max(0, ((int) $startAt - (int) (microtime(true) * 1000)) * 1000));

try {
    $invoice = Invoice::query()->findOrFail((int) $invoiceId);
    $wait();

    match ($mode) {
        'pay' => app(ApplyPayment::class)->handle($invoice, new PaymentData($gross, 'bank_transfer', 'race-'.$label)),
        'fee' => app(ApplyLateFee::class)->handle($invoice),
        default => app(CancelInvoice::class)->handle($invoice, User::query()->where('is_staff', true)->value('id'), 'Race '.$label, strict: false),
    };
    $print(['outcome' => $mode]);
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => $e->getMessage()]);
}
