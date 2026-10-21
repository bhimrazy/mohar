<?php

/**
 * PR #211 review probe: credit-worker.php plus an optional row referencing the client inserted before the invoice
 * is issued, the way PlaceOrder inserts the order first. Extra args: <child first 0|1> <pause after it ms>.
 * Race helper (#192): issues one invoice that applies account credit, in its own process and transaction, against the
 * given database, so that real concurrent transactions meet on the client's credit.
 *
 *   php credit-worker.php <database> <client id> <amount> <delay ms> <pause ms> <hold ms>
 *
 * <pause ms> is spent inside the order guard, which runs after the invoice is published and before the credit is
 * applied: it widens that window the way a slow request would. Prints "<invoice id> <status> <credit applied>
 * <microtime when issued> <microtime at commit>", or "error <class>: <message>".
 */

use App\Contracts\Billing\Invoicer;
use App\Contracts\Orders\PaymentGuard;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Enums\BillableType;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $database, $clientId, $amount, $delay, $pause, $hold] = $argv;
$childFirst = ($argv[7] ?? "0") === "1";
$childPause = (int) ($argv[8] ?? 0);
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);

app()->instance(PaymentGuard::class, new class((int) $pause) implements PaymentGuard
{
    public function __construct(private int $pause) {}

    public function refusalReason(int $invoiceId): ?string
    {
        usleep($this->pause * 1000);
        $this->pause = 0;

        return null;
    }
});

usleep((int) $delay * 1000);
DB::beginTransaction();

try {
    $client = Client::query()->findOrFail((int) $clientId);
    if ($childFirst) {
        // Like PlaceOrder: a row referencing the client (the order) is inserted before the invoice is issued.
        DB::table("payment_batches")->insert(["client_id" => $client->id, "currency" => $client->currency, "invoice_ids" => "[]", "total" => 0, "created_at" => now(), "updated_at" => now()]);
        usleep($childPause * 1000);
    }
    $lines = [new InvoiceLineData('Hosting renewal', (int) $amount, billableType: BillableType::Manual)];
    $result = app(Invoicer::class)->issue(new InvoiceDraft($client->id, $client->currency, $lines, notify: false, applyCredit: true));
    $invoice = Invoice::query()->findOrFail($result->invoiceId);
    $issued = microtime(true);
    usleep((int) $hold * 1000);
    DB::commit();
    fwrite(STDOUT, implode(' ', [$invoice->id, $invoice->status->value, $invoice->credit_applied, $issued, microtime(true)]));
} catch (Throwable $e) {
    DB::rollBack();
    fwrite(STDOUT, 'error '.get_class($e).': '.$e->getMessage());
    exit(1);
}
