<?php

/**
 * Race helper (#192): one invoice that applies account credit, issued in its own process and transaction against the
 * given database, so that real concurrent transactions meet on the client's credit. Started by runRaceWorkers().
 *
 *   php credit-worker.php <label> <issue|checkout|pay> <database> <client or invoice id> <amount> <delay ms> <pause ms>
 *
 * issue: a renewal-style invoice through Invoicer; <pause ms> is spent in the order guard, after the invoice is
 * published (it holds the invoice number) and before the credit is applied.
 * checkout: a real PlaceOrder checkout applying credit (catalog and services faked); <pause ms> is spent creating the
 * pending service, after the order row is inserted and before the invoice is issued.
 * pay (#224): ApplyPayment of <amount> on the invoice <id> (an overpayment goes to account credit); <pause ms> is spent
 * after the payment row is inserted (its foreign key holds a key-share lock on the client) and before the credit post.
 *
 * Prints one JSON object: {label, invoice, status, applied, issued, ended}, or {label, error}.
 */

use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Catalog\PriceBook;
use App\Contracts\Orders\PaymentGuard;
use App\Contracts\Services\ServiceRegistry;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Data\Services\ServiceDraft;
use App\Enums\BillableType;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\Client;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Data\CheckoutData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Orders\FakeGateways;
use Tests\Fakes\Orders\FakePriceBook;
use Tests\Fakes\Orders\FakeServiceRegistry;
use Tests\Fakes\Orders\OrdersFakes;

[, $label, $mode, $database, $clientId, $amount, $delay, $pause] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);

$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result]));

if ($mode === 'checkout') {
    app()->instance(PriceBook::class, (new FakePriceBook)->addProduct(1, 'Starter Hosting', ['monthly' => [(int) $amount, 0]]));
    app()->instance(GatewayDirectory::class, new FakeGateways);
    // Distinct service ids per worker: the invoice lines' period guard is unique on them.
    app()->instance(ServiceRegistry::class, new class((int) $pause, $label) extends FakeServiceRegistry
    {
        public function __construct(private int $pause, private string $label) {}

        public function createPending(ServiceDraft $draft): int
        {
            usleep($this->pause * 1000);
            $this->pause = 0;

            return crc32($this->label) % 1_000_000 + parent::createPending($draft);
        }
    });
} elseif ($mode === 'pay' || $mode === 'batch') {
    $pauseOnce = (int) $pause;
    Transaction::created(function () use (&$pauseOnce): void {
        usleep($pauseOnce * 1000);
        $pauseOnce = 0;
    });
} else {
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
}

usleep((int) $delay * 1000);
DB::beginTransaction();

try {
    if ($mode === 'batch') {
        // PR #229 probe: a combined payment of the batch <id> settles each invoice through ApplyPayment in one
        // transaction; <pause ms> is spent after the first invoice's payment row is inserted.
        $batch = PaymentBatch::query()->findOrFail((int) $clientId);
        app(PaymentBatches::class)->settle($batch, 'bank_transfer', 'race-'.$label, (int) $amount);
        $issued = microtime(true);
        DB::commit();
        $print(['invoice' => $batch->id, 'status' => (string) $batch->fresh()?->status, 'applied' => (int) $amount, 'issued' => $issued, 'ended' => microtime(true)]);
        exit(0);
    }

    if ($mode === 'pay') {
        $invoice = Invoice::query()->findOrFail((int) $clientId);
        app(ApplyPayment::class)->handle($invoice, new PaymentData((int) $amount, 'bank_transfer', 'race-'.$label));
        $invoice->refresh();
        $issued = microtime(true);
        DB::commit();
        $print(['invoice' => $invoice->id, 'status' => $invoice->status->value, 'applied' => $invoice->amount_paid, 'issued' => $issued, 'ended' => microtime(true)]);
        exit(0);
    }

    $client = Client::query()->findOrFail((int) $clientId);

    if ($mode === 'checkout') {
        $data = OrdersFakes::checkout($client, [OrdersFakes::productItem(1)], skipFraud: true);
        $invoiceId = app(PlaceOrder::class)->handle(new CheckoutData(...[...get_object_vars($data), 'applyCredit' => true, 'notifyInvoice' => false]))->order->invoice_id;
    } else {
        $lines = [new InvoiceLineData('Hosting renewal', (int) $amount, billableType: BillableType::Manual)];
        $invoiceId = app(Invoicer::class)->issue(new InvoiceDraft($client->id, $client->currency, $lines, notify: false, applyCredit: true))->invoiceId;
    }

    $invoice = Invoice::query()->findOrFail($invoiceId);
    $issued = microtime(true);
    DB::commit();
    $print(['invoice' => $invoice->id, 'status' => $invoice->status->value, 'applied' => $invoice->credit_applied, 'issued' => $issued, 'ended' => microtime(true)]);
} catch (Throwable $e) {
    DB::rollBack();
    $print(['error' => get_class($e).': '.$e->getMessage()]);
    exit(1);
}
