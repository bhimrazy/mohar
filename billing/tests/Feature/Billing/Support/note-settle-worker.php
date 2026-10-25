<?php

/**
 * Race helper (VAT PR 2): one credit note settled to account credit, or one reversal, in its own process, started at a
 * common instant so two of them meet on the invoice lock. Started by runRaceWorkers().
 *
 *   php note-settle-worker.php <label> credit  <database> <invoice id> <gross>          <start at ms>
 *   php note-settle-worker.php <label> reverse <database> <invoice id> <credit note id> <start at ms>
 *
 * Both preview before the start instant and confirm that preview after it, as two staff members with the dialog open.
 * Prints {label, database, transaction_level, outcome, number}.
 */

use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Services\TaxNoteIssuer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $mode, $database, $invoiceId, $value, $startAt] = $argv;
$base = dirname(__DIR__, 4);

putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), ...$result]));

try {
    $issuer = app(TaxNoteIssuer::class);
    $request = $mode === 'reverse'
        ? NoteRequest::reversal((int) $invoiceId, (int) $value, 'Race '.$label)
        : NoteRequest::creditAmount((int) $invoiceId, (int) $value, 'goodwill', 'Race '.$label, settlement: 'credit');
    $preview = $issuer->preview($request);
    usleep((int) max(0, ((int) $startAt - (int) (microtime(true) * 1000)) * 1000));
    $note = $issuer->issue($request, $preview->version, $preview->digest);
    $print(['outcome' => 'issued', 'number' => $note->number]);
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => $e->getMessage(), 'number' => null]);
}
