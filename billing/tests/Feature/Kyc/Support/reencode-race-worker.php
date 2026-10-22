<?php

/**
 * Race helper (#287): one side of `kyc:reencode-images` against a client's replacement upload, in its own process and
 * transaction, started by runRaceWorkers() from ReencodeStoredImagesRaceTest.
 *
 *   php reencode-race-worker.php <label> run     <database> <disk root> <hold|wait> <ms>
 *   php reencode-race-worker.php <label> replace <database> <disk root> <client id> <delay ms>
 *
 * run: the command; hold spends <ms> inside the switch transaction, after the document row is locked; wait spends it
 * just before that transaction begins (holding no lock). replace: waits <delay ms>, then the client replaces the
 * citizenship document through StoreDocument. Both use the local objects disk at <disk root>.
 *
 * Prints one JSON object: {label, outcome, database, transaction_level} or {label, error}.
 */

use App\Modules\Kyc\Actions\StoreDocument;
use App\Modules\Kyc\Enums\DocumentType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

[, $label, $mode, $database, $root] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);
config(['filesystems.disks.objects' => ['driver' => 'local', 'root' => $root, 'throw' => true]]);

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result,
    'database' => DB::connection()->getDatabaseName(), 'transaction_level' => DB::transactionLevel()]));

try {
    if ($mode === 'run') {
        [, , , , , $how, $ms] = $argv;
        $paused = false;
        $pause = function () use (&$paused, $ms): void {
            if (! $paused) {
                $paused = true;
                usleep((int) $ms * 1000);
            }
        };

        $how === 'hold'
            ? Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($pause): void {
                if (str_contains($query->sql, 'kyc_documents') && str_contains(strtolower($query->sql), 'for update')) {
                    $pause();
                }
            })
            : Event::listen(TransactionBeginning::class, $pause);

        $code = Artisan::call('kyc:reencode-images', ['--pause' => 0]);
        $print(['outcome' => $code, 'output' => Artisan::output()]);

        exit(0);
    }

    [, , , , , $clientId, $delay] = $argv;
    $upload = function (): UploadedFile {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);
        $path = (string) tempnam(sys_get_temp_dir(), 'kyc287');
        file_put_contents($path, (string) ob_get_clean());

        return new UploadedFile($path, 'photo.png', null, null, true);
    };
    usleep((int) $delay * 1000);
    $document = app(StoreDocument::class)->handle((int) $clientId, DocumentType::Citizenship, 'NEW-27-01-74', $upload(), $upload(), null);
    $print(['outcome' => 'stored', 'document' => $document->id]);
} catch (Throwable $e) {
    $print(['error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
