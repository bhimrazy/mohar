<?php

/**
 * Race helper (#365): LinkRegistrarOrder for one domain against the fake registrar, in its own process and
 * transaction, started by runRaceWorkers() from LinkOrderRaceTest.
 *
 *   php link-race-worker.php <label> link <database> <domain id> <order id> <pause ms>
 *
 * <pause ms> is spent once, in the link transaction, right after the "does another row hold this order" check and before the
 * record is written. The cache locks are
 * per process here (array store), so only the row locks can keep the two workers apart.
 *
 * Prints one JSON object: {label, outcome: linked|refused, message, database, transaction_level} or {label, error}.
 */

use App\Modules\Domains\Actions\LinkRegistrarOrder;
use App\Modules\Domains\Models\Domain;
use App\Support\Domains\RegistrarException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\Domains\FakeRegistrar;

[, $label, $mode, $database, $domainId, $orderId, $pause] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result,
    'database' => DB::connection()->getDatabaseName(), 'transaction_level' => DB::transactionLevel()]));

try {
    $registrar = FakeRegistrar::install();
    $registrar->capabilities[] = 'link_order';
    $paused = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$paused, $pause): void {
        if (! $paused && DB::transactionLevel() > 0 && str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, '"registrar_meta"')) {
            $paused = true;
            usleep((int) $pause * 1000);
        }
    });

    try {
        app(LinkRegistrarOrder::class)->handle(Domain::query()->findOrFail((int) $domainId), $orderId);
        $print(['outcome' => 'linked']);
    } catch (RegistrarException $refused) {
        $print(['outcome' => 'refused', 'message' => $refused->getMessage()]);
    }
} catch (Throwable $e) {
    $print(['error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
