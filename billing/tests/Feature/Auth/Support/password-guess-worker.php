<?php

/**
 * Race helper (#523): one wrong step-up guess, in its own process, on the database cache store.
 *
 *   php password-guess-worker.php <label> <database> <user id> <ip> <start at>
 *
 * Waits until <start at> (a Unix time with microseconds), so all workers count at once, then makes a guess whose
 * compare takes 500 ms and is wrong. Prints one JSON object: {label, outcome, compared, count} or {label, error}.
 * outcome: wrong (compared and refused as wrong), ended (compared, used the budget up and ended the session),
 * refused (over the budget, never compared).
 */

use App\Actions\Fortify\PasswordGuesses;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

[, $label, $database, $userId, $ip, $startAt] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database, 'cache.default' => 'database', 'cache.stores.database.connection' => $connection]);
DB::purge($connection);

$compared = false;
$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result, 'compared' => $compared]));

try {
    $user = User::query()->findOrFail((int) $userId);
    $request = Request::create('/user/confirm-password', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json']);
    usleep(max(0, (int) (((float) $startAt - microtime(true)) * 1_000_000)));

    try {
        PasswordGuesses::check($user, $request, 'password', function () use (&$compared): bool {
            $compared = true;
            usleep(500_000);

            return false;
        });
        $outcome = 'wrong';
    } catch (HttpResponseException) {
        $outcome = $compared ? 'ended' : 'refused';
    }

    $print(['outcome' => $outcome, 'count' => (int) RateLimiter::attempts('password-guess|'.$user->id)]);
} catch (Throwable $e) {
    $print(['error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
