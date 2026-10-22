<?php

/**
 * Race helper (PR #256 review): one verified dispute callback applied in its own process, as the Stripe driver hands it
 * to Billing after retrieving the dispute. Started by runRaceWorkers().
 *
 *   php dispute-worker.php <label> <chargeback|won> <database> <dispute id> <parent txn id> <amount> <delay ms> <pause ms>
 *
 * chargeback: the CHARGEBACK the driver built from a dispute retrieved before the win (status needs_response, funds
 * withdrawn); <pause ms> is spent inside the booking transaction, right after the chargeback row is inserted.
 * won: the DISPUTE_WON with fundsReinstated the driver builds from the won close (#232).
 * <delay ms> is spent before the event is applied. Prints {label, status, result, ended} or {label, error}.
 */

use App\Data\Billing\CallbackEvent;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use Illuminate\Contracts\Console\Kernel;

[, $label, $mode, $database, $disputeId, $parentTxnId, $amount, $delay, $pause] = $argv;
$base = dirname(__DIR__, 4);

// The database is chosen before the app boots. In a manual run, swapping it with config() + DB::purge() after boot (as
// credit-worker.php does) reported the nested commit at level 0 and lost the chargeback rows while the log line
// committed; not investigated further.
putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result]));

try {
    if ($mode === 'chargeback') {
        Transaction::created(function (Transaction $row) use ($pause): void {
            if ($row->type === TransactionType::Chargeback) {
                usleep((int) $pause * 1000);
            }
        });
    }

    usleep((int) $delay * 1000);

    $event = new CallbackEvent(
        type: $mode === 'won' ? CallbackEvent::DISPUTE_WON : CallbackEvent::CHARGEBACK,
        invoiceId: null,
        txnId: $disputeId,
        amount: (int) $amount,
        currency: 'USD',
        parentTxnId: $parentTxnId,
        reason: 'dispute: fraudulent',
        raw: ['dispute' => $disputeId],
        fundsReinstated: $mode === 'won',
    );
    $outcome = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    $print(['status' => $outcome['status'], 'result' => $outcome['result'], 'ended' => microtime(true)]);
} catch (Throwable $e) {
    $print(['error' => get_class($e).': '.$e->getMessage().' @ '.substr($e->getTraceAsString(), 0, 1500)]);
}
