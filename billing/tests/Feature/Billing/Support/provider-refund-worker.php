<?php

// Race helper (VAT PR 4): one provider refund callback in its own process; <pause ms> is spent inside the booking
// transaction right after the refund row is inserted, so a second delivery of the same refund id meets it in flight.
//   php pr458-callback-worker.php <label> <database> <refund id> <parent txn id> <amount> <start at ms> <pause ms>
use App\Data\Billing\CallbackEvent;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $label, $mode, $database, $refundId, $parent, $amount, $startAt, $pause] = $argv;
$base = dirname(__DIR__, 4);
putenv("DB_DATABASE=$database");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$print = fn (array $r) => fwrite(STDOUT, (string) json_encode(['label' => $label, 'database' => $database, 'transaction_level' => DB::transactionLevel(), ...$r]));
try {
    Transaction::created(function (Transaction $row) use ($pause): void {
        if ($row->type === TransactionType::Refund) {
            usleep((int) $pause * 1000);
        }
    });
    usleep((int) max(0, ((int) $startAt - (int) (microtime(true) * 1000)) * 1000));
    $event = new CallbackEvent(CallbackEvent::REFUND, null, $refundId, (int) $amount, 'NPR', $parent, raw: ['id' => $refundId]);
    $out = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);
    $print(['outcome' => $out['result'], 'status' => $out['status']]);
} catch (Throwable $e) {
    $print(['outcome' => class_basename($e), 'message' => substr($e->getMessage(), 0, 300)]);
}
