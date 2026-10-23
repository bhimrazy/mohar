<?php

/**
 * Race helper (VAT PR 1b): one note issue or one payment in its own process, started at a common instant so two of them
 * meet on the invoice lock. Started by runRaceWorkers().
 *
 *   php note-worker.php <label> note <database> <invoice id> <gross> <start at ms> <preview first: 0|1>
 *   php note-worker.php <label> pay  <database> <invoice id> <gross> <start at ms> 0
 *
 * A note worker with <preview first> = 1 previews before the start instant and issues that preview after it, the way
 * two staff members who both opened the preview would; with 0 it previews and issues after the start instant.
 * Prints {label, database, transaction_level, outcome, number}.
 */

use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\TaxNoteIssuer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $mode, $database, $invoiceId, $gross, $startAt, $previewFirst] = $argv;
$base = dirname(__DIR__, 4);

putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), ...$result]));
$wait = fn () => usleep((int) max(0, ((int) $startAt - (int) (microtime(true) * 1000)) * 1000));

try {
    if ($mode === 'pay') {
        $wait();
        app(ApplyPayment::class)->handle(Invoice::query()->findOrFail((int) $invoiceId), new PaymentData((int) $gross, 'bank_transfer', 'race-'.$label));
        $print(['outcome' => 'paid', 'number' => null]);

        return;
    }

    $issuer = app(TaxNoteIssuer::class);
    $request = NoteRequest::creditAmount((int) $invoiceId, (int) $gross, 'goodwill', 'Race '.$label);
    $preview = $previewFirst === '1' ? $issuer->preview($request) : null;
    $wait();
    $preview ??= $issuer->preview($request);
    $note = $issuer->issue($request, $preview->version, $preview->digest);
    $print(['outcome' => 'issued', 'number' => $note->number]);
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => $e->getMessage(), 'number' => null]);
}
