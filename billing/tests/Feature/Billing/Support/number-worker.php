<?php

/**
 * Review probe helper (#189): takes one document number in its own process and transaction, against the test
 * worker's database, so that real concurrent transactions meet on billing_sequences.
 *
 *   php number-worker.php <database> <sequence> <delay ms> <hold ms> <commit|rollback|cleanup>
 *
 * Prints "<number> <microtime when the number came back> <microtime at commit/rollback>".
 */

use App\Modules\Billing\Models\BillingSequence;
use App\Modules\Billing\Services\DocumentNumbers;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $database, $sequence, $delay, $hold, $mode] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);

if ($mode === 'cleanup') {
    BillingSequence::query()->where('sequence', $sequence)->delete();
    exit(0);
}

usleep((int) $delay * 1000);
DB::beginTransaction();

try {
    $number = app(DocumentNumbers::class)->next($sequence);
    $taken = microtime(true);
    usleep((int) $hold * 1000);
    $mode === 'rollback' ? DB::rollBack() : DB::commit();
    fwrite(STDOUT, ((int) $number).' '.$taken.' '.microtime(true));
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDOUT, 'error '.get_class($e).': '.$e->getMessage());
    exit(1);
}
