<?php

/**
 * Review probe worker (PR #308): widens the gap between Show's read of the unlock row and its write.
 *
 *   php kyc-unlock-gap-worker.php <label> <database> <staff id> <client id> <hold ms>
 *
 * Takes the unlock row FOR UPDATE, holds it <hold ms>, then runs Hide documents (close()) in the same transaction and
 * commits. A Show that read the row without a lock while this held it, then wrote after, restores the unlock.
 */

use App\Modules\Kyc\Models\KycReviewUnlock;
use App\Modules\Kyc\Services\ReviewUnlocks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $database, $staff, $client, $hold] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result, 'at' => microtime(true)]));

try {
    $applied = DB::transaction(function () use ($staff, $client, $hold): bool {
        KycReviewUnlock::query()->where('user_id', (int) $staff)->where('client_id', (int) $client)->lockForUpdate()->firstOrFail();
        usleep((int) $hold * 1000);

        return app(ReviewUnlocks::class)->close((int) $staff, (int) $client);
    });

    $print(['outcome' => $applied ? 'applied' : 'refused']);
} catch (Throwable $e) {
    $print(['error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
