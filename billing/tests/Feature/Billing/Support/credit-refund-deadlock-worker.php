<?php

/**
 * Race helper (VAT PR 4c, ADR 0024 §5a): one credit-refund actor in its own process, started at a common instant. Started by runRaceWorkers().
 *
 *   php credit-refund-worker.php <label> settle <database> <refund id> <start at ms>        the provider's callback (the real handler)
 *   php credit-refund-worker.php <label> release <database> <refund id> <start at ms>       the request's DECLINED (or Check refund)
 *   php credit-refund-worker.php <label> recorded <database> <client id> <start at ms> <amount>   a refund made outside
 *   php credit-refund-worker.php <label> gateway <database> <payment id> <start at ms> <amount>   a refund through the gateway (sandbox: SUCCESS)
 *
 * Prints {label, database, transaction_level, outcome, ...}.
 */

use App\Data\Billing\CallbackEvent;
use App\Modules\Billing\Actions\RefundAccountCredit;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayAmounts;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use App\Modules\Core\Models\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $mode, $database, $id, $startAt] = $argv;
$amount = (int) ($argv[6] ?? 0);
$base = dirname(__DIR__, 4);

putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), ...$result]));
$wait = fn () => usleep((int) max(0, ((int) $startAt - (int) (microtime(true) * 1000)) * 1000));

try {
    switch ($mode) {
        case 'settle':
            $row = Transaction::query()->findOrFail((int) $id);
            $parent = Transaction::query()->findOrFail($row->parent_transaction_id);
            $amounts = app(GatewayAmounts::class);
            $sent = $amounts->toGateway($parent, $row->amount_out);
            $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_cr_'.$id, $sent->amount, $sent->currency, $amounts->charge($parent)['txnId'], raw: ['id' => 're_cr_'.$id], reference: (string) $row->id);
            $wait();
            usleep(300_000);
            $answer = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);
            $print(['outcome' => 'settled', 'result' => $answer['result'], 'status' => $answer['status']]);
            break;
        case 'release':
            $row = Transaction::query()->findOrFail((int) $id);
            $wait();
            // release() as written: the refund row first, then (inside releaseLocked) the payment. A pause between them widens the window.
            DB::transaction(function () use ($row): void {
                Transaction::query()->lockForUpdate()->findOrFail($row->id);
                usleep(800_000);
                app(RefundTransaction::class)->release($row);
            });
            $print(['outcome' => 'released']);
            break;
        case 'recorded':
            $client = Client::query()->findOrFail((int) $id);
            $wait();
            app(RefundAccountCredit::class)->recorded($client, $amount, 'sandbox_card', 'REF-'.$label, null, null);
            $print(['outcome' => 'refunded']);
            break;
        default:
            $payment = Transaction::query()->findOrFail((int) $id);
            $wait();
            app(RefundAccountCredit::class)->throughGateway(Client::query()->findOrFail($payment->client_id), $payment, $amount, null, null);
            $print(['outcome' => 'refunded']);
    }
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => $e->getMessage()]);
}
