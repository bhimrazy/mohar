<?php

/**
 * Review probe helper for PR #253 (#25): one process against the given (committed) database, started by
 * ReencryptProcessesAndTargetsTest. Prints one JSON object {label, ...} on STDOUT.
 *
 *   php reencrypt-worker.php <label> reencrypt <database> <new key> <old key> <cache store> <chunk>
 *   php reencrypt-worker.php <label> writer    <database> <new key> <old key> <first id> <last id> <writes>
 *
 * reencrypt: `security:reencrypt --only=servers.password` with APP_KEY=<new>, APP_PREVIOUS_KEYS=<old>, cache store
 * <cache store> (its lock lives there). writer: the app after the restart (current key <new>), saving <writes>
 * passwords through Eloquent on random servers in [first, last]; prints the last value written per id.
 */

use App\Modules\Services\Models\Server;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

[, $label, $mode, $database, $new, $old] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);
config(['app.key' => $new, 'app.previous_keys' => [$old]]);
app()->forgetInstance('encrypter');
Facade::clearResolvedInstance('encrypter');

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result]));

try {
    if ($mode === 'reencrypt') {
        config(['cache.default' => $argv[6]]);
        $code = Artisan::call('security:reencrypt', ['--only' => ['servers.password'], '--chunk' => (int) $argv[7]]);
        $print(['exit' => $code, 'output' => Artisan::output()]);
    } elseif ($mode === 'writer') {
        [$first, $last, $writes] = [(int) $argv[6], (int) $argv[7], (int) $argv[8]];
        $written = [];

        for ($i = 0; $i < $writes; $i++) {
            $id = random_int($first, $last);
            $server = Server::query()->findOrFail($id);
            $server->update(['password' => "w-{$i}"]);
            $written[$id] = "w-{$i}";
            usleep(random_int(0, 3000));
        }

        $print(['written' => $written]);
    }
} catch (Throwable $exception) {
    $print(['error' => $exception::class.': '.$exception->getMessage()]);
}
