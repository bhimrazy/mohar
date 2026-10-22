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
 * plain (#218): an invoice through Invoicer that applies no credit; <pause ms> is spent after the invoice row is inserted.
 * currency (#218): Client::updateDetails (the admin client edit) changing the client's currency to <amount>, a currency
 * code; <pause ms> is spent saving the client, after the lock and the check.
 * server-import (#239): ImportServerAccounts::handle() of the confirmed server account import <id> (its own
 * transactions, one per account; <amount> unused); <pause ms> is spent creating the first service, after the client's
 * share lock and before the insert. Prints the report: {status: imported|not_imported, message, service_currency}.
 *
 * manual (importer B2): a staff credit movement of <amount> minor units (negative takes credit away) through
 * CreditLedgerService::post; <pause ms> is spent after the client lock, before the ledger row is inserted.
 * opening (importer B2): the client import's opening balance for <client or invoice id> set to <amount> by run 8.
 * opening-undo (importer B2): the undo of run 7's opening balance for the client.
 *
 * Prints one JSON object: {label, invoice, status, applied, issued, ended}, or {label, error}. A plain invoice or a
 * currency change that is refused prints status "refused" and the reason as message.
 */

use App\Contracts\Auth\ClientLoginInvitations;
use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\Invoicer;
use App\Contracts\Catalog\PriceBook;
use App\Contracts\Migration\ImportIdResolver;
use App\Contracts\Migration\RevertsImport;
use App\Contracts\Orders\PaymentGuard;
use App\Contracts\Services\ServiceRegistry;
use App\Data\Billing\InvoiceDraft;
use App\Data\Billing\InvoiceLineData;
use App\Data\Migration\ImportContext;
use App\Data\Migration\ImportOutcome;
use App\Data\Migration\ImportRecord;
use App\Data\Services\ServiceDraft;
use App\Enums\BillableType;
use App\Enums\ImportAction;
use App\Enums\InvitationOutcome;
use App\Enums\InvitationPurpose;
use App\Modules\Billing\Actions\ApplyPayment;
use App\Modules\Billing\Actions\PaymentBatches;
use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Actions\SaveDraftInvoice;
use App\Modules\Billing\Data\PaymentData;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentBatch;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Enums\EmailSource;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Migration\Jobs\SendInvitations;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Services\Actions\ImportServerAccounts;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\Service;
use App\Support\Migration\ImportRegistry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\Orders\FakeGateways;
use Tests\Fakes\Orders\FakePriceBook;
use Tests\Fakes\Orders\FakeServiceRegistry;
use Tests\Fakes\Orders\OrdersFakes;
use Tests\Fakes\Services\FakePriceBook as ServicesPriceBook;

[, $label, $mode, $database, $clientId, $amount, $delay, $pause] = $argv;
$base = dirname(__DIR__, 4);

require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.default');
config(["database.connections.$connection.database" => $database]);
DB::purge($connection);

// Every result names the database the worker wrote to and its open transaction level, so runRaceWorkers() can check
// that the work landed, committed, in the database the test reads (#256 review N3).
$print = fn (array $result) => fwrite(STDOUT, (string) json_encode(['label' => $label, ...$result,
    'database' => DB::connection()->getDatabaseName(), 'transaction_level' => DB::transactionLevel()]));

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
} elseif ($mode === 'plain') {
    $pauseOnce = (int) $pause;
    Invoice::created(function () use (&$pauseOnce): void {
        usleep($pauseOnce * 1000);
        $pauseOnce = 0;
    });
} elseif ($mode === 'currency') {
    Client::updating(fn () => usleep((int) $pause * 1000));
} elseif ($mode === 'quote-save' || $mode === 'draft' || $mode === 'quote-new') {
    // PR #237 probes: <pause ms> is spent before the quote / draft row is written, after the client's share lock.
    $pauseOnce = (int) $pause;
    $sleep = function () use (&$pauseOnce): void {
        usleep($pauseOnce * 1000);
        $pauseOnce = 0;
    };
    Quote::saving($sleep);
    Invoice::saving($sleep);
} elseif ($mode === 'manual') {
    $pauseOnce = (int) $pause;
    CreditLedgerEntry::creating(function () use (&$pauseOnce): void {
        usleep($pauseOnce * 1000);
        $pauseOnce = 0;
    });
} elseif ($mode === 'opening' || $mode === 'opening-undo') {
    // Importer B2 probes: nothing to pause; the importer locks the client before it reads the ledger.
} elseif ($mode === 'server-import') {
    $pauseOnce = (int) $pause;
    Service::creating(function () use (&$pauseOnce): void {
        usleep($pauseOnce * 1000);
        $pauseOnce = 0;
    });
} elseif ($mode === 'quote-convert') {
    // PR #237 probe: nothing to pause; convert locks the quote, then IssueInvoice locks the client.
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

if ($mode === 'invite') {
    // PR #255 probe: one SendInvitations run with the production cache store (database locks); <pause ms> is spent
    // before each invitation, so two senders overlap. The next job it dispatches is dropped (null queue).
    config(['cache.default' => 'database', 'queue.connections.null' => ['driver' => 'null'], 'queue.default' => 'null']);
    $real = app(ClientLoginInvitations::class);
    $slow = new class($real, (int) $pause) implements ClientLoginInvitations
    {
        public function __construct(private ClientLoginInvitations $real, private int $pause) {}

        public function problem(): ?string
        {
            return null;
        }

        public function invite(int $clientId, int $userId, InvitationPurpose $purpose = InvitationPurpose::LoginAdded, ?int $actorId = null): InvitationOutcome
        {
            usleep($this->pause * 1000);

            return $this->real->invite($clientId, $userId, $purpose, $actorId);
        }
    };
    usleep((int) $delay * 1000);

    try {
        $started = microtime(true);
        (new SendInvitations)->handle($slow, app(Settings::class), app(ActivityLogger::class));
        $print(['status' => 'done', 'issued' => $started, 'ended' => microtime(true)]);
        exit(0);
    } catch (Throwable $e) {
        $print(['error' => get_class($e).': '.$e->getMessage()]);
        exit(1);
    }
}

usleep((int) $delay * 1000);

if ($mode === 'server-import') {
    // No outer transaction: handle() commits each account on its own, as the queued job does.
    try {
        $import = ServerAccountImport::query()->with('server')->findOrFail((int) $clientId);
        $groupId = (int) $import->server->groups()->value('server_groups.id');
        app()->instance(PriceBook::class, $priceBook = new ServicesPriceBook);
        $priceBook->addProduct(21, 'Basic hosting', ['monthly' => 1200], driver: 'cpanel', serverGroupId: $groupId);
        [$line] = app(ImportServerAccounts::class)->handle($import->server, $import, null);
        $currency = $line['service_id'] === null ? null : Service::query()->whereKey($line['service_id'])->value('currency');
        $print(['status' => $line['imported'] ? 'imported' : 'not_imported', 'message' => $line['message'], 'service_currency' => $currency, 'ended' => microtime(true)]);
        exit(0);
    } catch (Throwable $e) {
        $print(['error' => get_class($e).': '.$e->getMessage()]);
        exit(1);
    }
}

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

    if ($mode === 'currency') {
        try {
            [$client] = Client::updateDetails((int) $clientId, ['currency' => $amount], EmailSource::Staff);
            $status = 'changed';
            $message = $client->currency;
        } catch (ValidationException $e) {
            $status = 'refused';
            $message = $e->validator->errors()->first('currency');
        }

        $issued = microtime(true);
        DB::commit();
        $print(['status' => $status, 'message' => $message, 'issued' => $issued, 'ended' => microtime(true)]);
        exit(0);
    }

    if ($mode === 'quote-save' || $mode === 'quote-convert') {
        // PR #237 probe: <client or invoice id> is the quote id.
        $quote = Quote::query()->findOrFail((int) $clientId);

        try {
            if ($mode === 'quote-save') {
                app(Quotes::class)->save(['client_id' => $quote->client_id, 'subject' => 'Edited by '.$label, 'items' => [['description' => 'Edited', 'quantity' => 1, 'unit_amount' => (int) $amount]]], $quote);
                $status = 'saved';
            } else {
                app(Quotes::class)->convert($quote);
                $status = 'converted';
            }
        } catch (BillingException $e) {
            DB::rollBack();
            $print(['status' => 'refused', 'message' => $e->getMessage(), 'issued' => microtime(true), 'ended' => microtime(true)]);
            exit(0);
        }

        $issued = microtime(true);
        DB::commit();
        $print(['invoice' => $quote->id, 'status' => $status, 'issued' => $issued, 'ended' => microtime(true)]);
        exit(0);
    }

    if ($mode === 'draft' || $mode === 'quote-new') {
        // PR #237 probe: a new draft invoice / quote for the client, in the currency the action reads.
        $data = ['client_id' => (int) $clientId, 'subject' => 'Probe', 'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_amount' => (int) $amount]]];
        $row = $mode === 'draft'
            ? app(SaveDraftInvoice::class)->handle($data)
            : app(Quotes::class)->save($data);
        $issued = microtime(true);
        DB::commit();
        $print(['invoice' => $row->id, 'status' => 'saved', 'currency' => $row->currency, 'issued' => $issued, 'ended' => microtime(true)]);
        exit(0);
    }

    if ($mode === 'manual' || $mode === 'opening' || $mode === 'opening-undo') {
        $client = Client::query()->findOrFail((int) $clientId);

        if ($mode === 'manual') {
            try {
                app(CreditLedgerService::class)->post($client->id, Money::of((int) $amount, $client->currency), CreditReason::Manual, description: 'Race '.$label);
                $status = 'posted';
            } catch (BillingException $e) {
                $status = 'refused';
            }
        } else {
            $importer = app(ImportRegistry::class)->get('credit_balance');

            if (! $importer instanceof RevertsImport) {
                throw new LogicException('The credit_balance importer cannot revert.');
            }

            $context = new ImportContext($mode === 'opening' ? 8 : 7, 1, 'csv', false, CarbonImmutable::now(), null, new class implements ImportIdResolver
            {
                public function targetId(string $entity, string $sourceId): ?int
                {
                    return null;
                }
            }, ['source_name' => 'Old billing']);
            $outcome = $mode === 'opening'
                ? $importer->upsert(new ImportRecord('credit_balance', 'csv', 'race', ['amount' => (int) $amount, 'currency' => $client->currency], ['client_id' => $client->id]), $context, $client->id)
                : $importer->revert(new ImportRecord('credit_balance', 'csv', 'race', ['amount' => 0], ['client_id' => $client->id]), new ImportOutcome($client->id, ImportAction::Created), $context);
            $status = $outcome->action->value.implode('', array_map(fn ($issue): string => ':'.$issue->code, $outcome->issues));
        }

        $issued = microtime(true);
        DB::commit();
        $print(['status' => $status, 'issued' => $issued, 'ended' => microtime(true)]);
        exit(0);
    }

    $client = Client::query()->findOrFail((int) $clientId);

    if ($mode === 'plain') {
        try {
            $lines = [new InvoiceLineData('Hosting', (int) $amount, billableType: BillableType::Manual)];
            $invoiceId = app(Invoicer::class)->issue(new InvoiceDraft($client->id, $client->currency, $lines, notify: false))->invoiceId;
        } catch (BillingException $e) {
            DB::rollBack();
            $print(['status' => 'refused', 'message' => $e->getMessage(), 'issued' => microtime(true), 'ended' => microtime(true)]);
            exit(0);
        }

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $issued = microtime(true);
        DB::commit();
        $print(['invoice' => $invoice->id, 'status' => $invoice->status->value, 'currency' => $invoice->currency, 'issued' => $issued, 'ended' => microtime(true)]);
        exit(0);
    }

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
