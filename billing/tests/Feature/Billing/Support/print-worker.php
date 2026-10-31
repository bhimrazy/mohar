<?php

/**
 * Race helper: one PDF download of a tax note in its own process, started at a common instant so several meet on the
 * note's row lock (DocumentDownloads). Started by runRaceWorkers().
 *
 *   php print-worker.php <label> print <database> <note id> <start at ms>
 *
 * Prints {label, database, transaction_level, outcome, bytes}.
 */

use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Services\DocumentDownloads;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, , $database, $noteId, $startAt] = $argv;
$base = dirname(__DIR__, 4);

putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), ...$result]));

try {
    $note = TaxNote::query()->findOrFail((int) $noteId);
    usleep((int) max(0, ((int) $startAt - (int) (microtime(true) * 1000)) * 1000));
    $response = app(DocumentDownloads::class)->note($note);
    $print(['outcome' => 'printed', 'bytes' => strlen((string) $response->getContent())]);
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => $e->getMessage()]);
}
