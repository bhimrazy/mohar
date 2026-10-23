<?php

/**
 * Race helper (#369): writes one value adjustment the way the previous release does (no cached totals), holding its
 * transaction open before the commit, then optionally runs the fiscal command in the same process. Started by
 * runRaceWorkers().
 *
 *   php adjustment-worker.php <label> <mode> <database> <invoice id> <amount> <delay ms> <hold ms> <run: 0|1>
 *
 * Prints {label, database, transaction_level, id, reconciled}.
 */

use App\Modules\Billing\Services\FiscalBackfill;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, , $database, $invoiceId, $amount, $delay, $hold, $run] = $argv;
$base = dirname(__DIR__, 4);

putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    usleep((int) $delay * 1000);
    DB::beginTransaction();
    $now = now()->format('Y-m-d H:i:s');
    $id = DB::table('invoice_adjustments')->insertGetId(['invoice_id' => (int) $invoiceId, 'kind' => 'debit', 'reason' => 'late_fee', 'amount' => (int) $amount,
        'taxable_amount' => (int) $amount, 'tax_amount' => 0, 'created_at' => $now, 'updated_at' => $now]);
    usleep((int) $hold * 1000);
    DB::commit();
    $reconciled = $run === '1' ? app(FiscalBackfill::class)->run()['reconciled'] : null;
    fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), 'id' => $id, 'reconciled' => $reconciled]));
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDOUT, (string) json_encode(['label' => $label, 'error' => get_class($e).': '.$e->getMessage()]));
    exit(1);
}
