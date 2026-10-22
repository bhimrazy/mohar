<?php

/**
 * Review probe worker (PR #308 re-check 2): one session's Hide, Show or sign-out, in its own process.
 *
 *   php kyc-unlock-session-worker.php <label> <database> close|open|signout <staff id> <client id> <binding> <generation> <delay ms> <hold ms>
 *
 * The worker waits <delay ms>, takes this session's own row FOR UPDATE (what the first statement of close() or open()
 * takes anyway), holds it <hold ms> to widen the gap between statements, then runs the call in the same transaction.
 */

use App\Modules\Kyc\Models\KycReviewUnlock;
use App\Modules\Kyc\Services\ReviewUnlocks;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $database, $mode, $staff, $client, $binding, $generation, $delay, $hold] = $argv;
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

    $applied = DB::transaction(function () use ($unlocks, $mode, $staff, $client, $binding, $generation, $hold): bool {
        KycReviewUnlock::query()->where('user_id', (int) $staff)->where('session_hash', $binding)
            ->when($mode !== 'signout', fn ($q) => $q->where('client_id', (int) $client))->lockForUpdate()->get();
        usleep((int) $hold * 1000);

        return match ($mode) {
            'open' => $unlocks->open((int) $staff, (int) $client, CarbonImmutable::now()->addMinutes(10), (int) $generation, $binding),
            'close' => $unlocks->close((int) $staff, (int) $client, $binding),
            default => (function () use ($unlocks, $staff, $binding): bool {
                $unlocks->closeAll((int) $staff, $binding);

                return true;
            })(),
        };
    });

    $print(['outcome' => $applied ? 'applied' : 'refused']);
} catch (Throwable $e) {
    $print(['error' => get_class($e).': '.substr($e->getMessage(), 0, 160)]);
    exit(1);
}
