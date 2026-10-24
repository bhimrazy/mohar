<?php

/**
 * Race helper (VAT PR 4, ADR 0024 §4, §5): one refund actor in its own process, started at a common instant so two of them
 * meet on the payment, invoice and reservation locks. Started by runRaceWorkers().
 *
 *   php refund-worker.php <label> settle <database> <refund id> <start at ms>      the provider's callback: SUCCESS
 *   php refund-worker.php <label> late-error <database> <refund id> <start at ms>  the request's late ERROR answer
 *   php refund-worker.php <label> refund <database> <payment id> <start at ms> <gross>   a staff refund (sandbox: SUCCESS)
 *   php refund-worker.php <label> note <database> <invoice id> <start at ms> <gross>     a staff note settled to account credit
 *
 * Prints {label, database, transaction_level, outcome, ...}.
 */

use App\Data\Billing\ChargeResult;
use App\Models\User;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Data\NoteRequest;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\TaxNoteIssuer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $mode, $database, $id, $startAt] = $argv;
$gross = (int) ($argv[6] ?? 0);
$base = dirname(__DIR__, 4);

putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), ...$result]));
$wait = fn () => usleep((int) max(0, ((int) $startAt - (int) (microtime(true) * 1000)) * 1000));

try {
    $refunds = app(RefundTransaction::class);

    switch ($mode) {
        case 'settle':
            $row = Transaction::query()->findOrFail((int) $id);
            $wait();
            $refunds->settle($row, 're_race_'.$id); // the provider's refund id, one per refund row
            $print(['outcome' => 'settled']);
            break;
        case 'late-error':
            $row = Transaction::query()->findOrFail((int) $id);
            $wait();
            $late = new ReflectionMethod($refunds, 'unsettled');
            $late->invoke($refunds, $row, new ChargeResult(ChargeResult::ERROR, declineReason: 'late'));
            $print(['outcome' => 'late-error']);
            break;
        case 'refund':
            $payment = Transaction::query()->findOrFail((int) $id);
            $wait();
            $refunds->handle($payment, $gross, RefundMode::Gateway, false, User::query()->where('is_staff', true)->value('id'), reason: 'Race '.$label);
            $print(['outcome' => 'refunded']);
            break;
        default:
            $request = NoteRequest::creditAmount((int) $id, $gross, 'goodwill', 'Race '.$label, settlement: 'credit');
            $issuer = app(TaxNoteIssuer::class);
            $preview = $issuer->preview($request);
            $wait();
            $issuer->issue($request, $preview->version, $preview->digest);
            $print(['outcome' => 'issued']);
    }
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => $e->getMessage()]);
}
