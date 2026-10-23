<?php

/**
 * Review race worker for #385: php rb385-worker.php <label> <mode> <database> <invoice id> <gross> <start ms> <preview first>
 * modes: credit (by amount), debit, latefee, reconcile, edit (holds the invoice lock 600 ms while changing due_date),
 * finalise (locks the client, waits 400 ms, then takes a credit note number, as the PR 4 refund finalise will)
 */

use App\Contracts\Core\CompanyClock;
use App\Modules\Billing\Actions\ApplyLateFee;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\DocumentNumbers;
use App\Modules\Billing\Services\FiscalBackfill;
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
    if ($mode === 'latefee') {
        $wait();
        $print(['outcome' => app(ApplyLateFee::class)->handle(Invoice::query()->findOrFail((int) $invoiceId)) ? 'fee' : 'nofee', 'number' => null]);

        return;
    }
    if ($mode === 'reconcile') {
        $wait();
        $print(['outcome' => 'reconciled', 'changed' => app(FiscalBackfill::class)->reconcile(), 'number' => null]);

        return;
    }
    if ($mode === 'finalise') {
        $wait();
        $number = DB::transaction(function () use ($invoiceId) {
            DB::table('clients')->where('id', Invoice::query()->findOrFail((int) $invoiceId)->client_id)->lockForUpdate()->first();
            usleep(400_000);

            return app(DocumentNumbers::class)->next(DocumentNumbers::CREDIT_NOTE, app(CompanyClock::class)->calendarDate());
        });
        $print(['outcome' => 'finalised', 'number' => $number]);

        return;
    }
    if ($mode === 'edit') {
        $wait();
        DB::transaction(function () use ($invoiceId) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail((int) $invoiceId);
            $invoice->due_date = $invoice->due_date->addDay();
            $invoice->save();
            usleep(600_000);
        });
        $print(['outcome' => 'edited', 'number' => null]);

        return;
    }

    $issuer = app(TaxNoteIssuer::class);
    $request = $mode === 'debit'
        ? NoteRequest::debit((int) $invoiceId, (int) $gross, true, 'other', 'Race '.$label)
        : NoteRequest::creditAmount((int) $invoiceId, (int) $gross, 'goodwill', 'Race '.$label);
    $preview = $previewFirst === '1' ? $issuer->preview($request) : null;
    $wait();
    if ($previewFirst === '1') {
        usleep(150_000); // let the other side take the lock first
    }
    $preview ??= $issuer->preview($request);
    $note = $issuer->issue($request, $preview->version, $preview->digest);
    $print(['outcome' => 'issued', 'number' => $note->number]);
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => substr($e->getMessage(), 0, 300), 'number' => null]);
}
