<?php

/**
 * Review probe helper (#231): one side of the approve-versus-re-upload race, in its own process and transaction.
 *
 *   php kyc-race-worker.php <label> decide <database> <document id> <staff id> <delay ms>
 *   php kyc-race-worker.php <label> upload <database> <client id> <delay ms> <pause ms>
 *
 * decide: reads the document (the page staff loaded), waits <delay ms>, then approves it through DecideDocument.
 * upload: replaces the client's citizenship document through StoreDocument; <pause ms> is spent right after the
 * client row is locked, before the current document is locked (re-review variant, #231).
 *
 * Prints one JSON object: {label, outcome, at} or {label, error}.
 */

use App\Models\User;
use App\Modules\Kyc\Actions\DecideDocument;
use App\Modules\Kyc\Actions\StoreDocument;
use App\Modules\Kyc\Enums\DocumentType;
use App\Modules\Kyc\Models\KycDocument;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

[, $label, $mode, $database] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);
config(['filesystems.disks.objects' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/kyc-race-'.getmypid(), 'throw' => true]]);

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result, 'at' => microtime(true)]));

try {
    if ($mode === 'decide') {
        [, , , , $documentId, $staffId, $delay] = $argv;
        $version = DecideDocument::version(KycDocument::query()->findOrFail((int) $documentId));
        usleep((int) $delay * 1000);

        try {
            app(DecideDocument::class)->handle((int) $documentId, $version, true, null, User::query()->findOrFail((int) $staffId));
            $print(['outcome' => 'approved']);
        } catch (ValidationException|ModelNotFoundException) {
            // Refused: changed since the page loaded, or replaced and already deleted (404 over HTTP).
            $print(['outcome' => 'refused']);
        }

        exit(0);
    }

    [, , , , $clientId, $delay, $pause] = $argv;
    $paused = false;
    DB::listen(function ($query) use ($pause, &$paused): void {
        if (! $paused && str_contains($query->sql, '"clients"') && str_contains($query->sql, 'for update')) {
            $paused = true;
            usleep((int) $pause * 1000);
        }
    });

    $png = function (): UploadedFile {
        $path = (string) tempnam(sys_get_temp_dir(), 'kyc');
        file_put_contents($path, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, 'front.png', null, null, true);
    };

    usleep((int) $delay * 1000);

    try {
        app(StoreDocument::class)->handle((int) $clientId, DocumentType::Citizenship, 'NEW-27-01-74', $png(), $png(), null);
        $print(['outcome' => 'stored']);
    } catch (ValidationException) {
        $print(['outcome' => 'refused']);
    }
} catch (Throwable $e) {
    $print(['error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
