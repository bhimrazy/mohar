<?php

/**
 * Race helper (#258): one document-unlock request, in its own process and transaction.
 *
 *   php kyc-unlock-race-worker.php <label> <database> open|close <staff id> <client id> <generation> <delay ms> <hold ms>
 *
 * open: opens the documents for 10 minutes (Show documents); <generation> is the one the page showed.
 * close: hides them (Hide documents).
 * Both wait <delay ms> first, then keep their transaction (and so the row lock they took) open for <hold ms> before
 * committing. Prints one JSON object: {label, outcome, at} or {label, error}.
 */

use App\Modules\Kyc\Services\ReviewUnlocks;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $database, $mode, $staff, $client, $generation, $delay, $hold] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result, 'at' => microtime(true)]));

try {
    usleep((int) $delay * 1000);
    $unlocks = app(ReviewUnlocks::class);

    $applied = DB::transaction(function () use ($unlocks, $mode, $staff, $client, $generation, $hold): bool {
        $applied = $mode === 'open'
            ? $unlocks->open((int) $staff, (int) $client, CarbonImmutable::now()->addMinutes(10), (int) $generation)
            : $unlocks->close((int) $staff, (int) $client);
        usleep((int) $hold * 1000);

        return $applied;
    });

    $print(['outcome' => $applied ? 'applied' : 'refused']);
} catch (Throwable $e) {
    $print(['error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
