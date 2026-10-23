<?php

/**
 * Race helper (#267): RegistrarRefs::remember() for one key in its own process, started by runRaceWorkers().
 *
 *   php refs-race-worker.php <label> remember <database> <remote id> <start at, unix ms>
 *
 * Waits for the shared start time so the inserts collide. Prints {label, remote_id} or {label, error}.
 */

use App\Contracts\Domains\Registrar\RegistrarRefs;
use App\Data\Domains\Registrar\RegistrarRef;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, , $database, $remoteId, $startAt] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);

try {
    app(RegistrarRefs::class)->find('resellerclub', 'customer', 'client', 77);

    while ((int) (microtime(true) * 1000) < (int) $startAt) {
        usleep(200);
    }

    $stored = app(RegistrarRefs::class)->remember(new RegistrarRef('resellerclub', 'customer', 'client', 77, $remoteId));
    fwrite(STDOUT, (string) json_encode(['label' => $label, 'remote_id' => $stored->remoteId, 'database' => DB::connection()->getDatabaseName(), 'transaction_level' => DB::transactionLevel()]));
} catch (Throwable $e) {
    fwrite(STDOUT, (string) json_encode(['label' => $label, 'error' => get_class($e).': '.$e->getMessage()]));
    exit(1);
}
