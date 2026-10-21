<?php

use App\Contracts\Billing\CreditLedger;
use App\Contracts\Orders\PaymentGuard;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/**
 * #192: a renewal that applies account credit reads the credit again under the client lock. Credit spent by another
 * invoice or checkout in the meantime lowers what is applied, and none left issues the invoice unpaid instead of
 * failing the renewal.
 */

/**
 * A guard that spends client credit the first time it is asked: IssueInvoice asks it after its unlocked balance read
 * and before the credit is applied, which is where a concurrent spend lands.
 */
function guardSpendingCredit(Client $client, int $amount): void
{
    app()->instance(PaymentGuard::class, new class($client->id, $amount) implements PaymentGuard
    {
        private bool $spent = false;

        public function __construct(private int $clientId, private int $amount) {}

        public function refusalReason(int $invoiceId): ?string
        {
            if (! $this->spent) {
                $this->spent = true;
                app(CreditLedgerService::class)->post($this->clientId, Money::of(-$this->amount, 'USD'), CreditReason::AppliedToInvoice, description: 'Spent elsewhere');
            }

            return null;
        }
    });
}

test('credit spent between the read and the apply issues the renewal unpaid instead of failing it', function () {
    $client = billingClient();
    app(CreditLedger::class)->credit($client->id, Money::of(1_000, 'USD'), 'Goodwill');
    guardSpendingCredit($client, 1_000);

    $invoice = issueInvoice($client, [1_000], applyCredit: true);

    expect($invoice)->status->toBe(InvoiceStatus::Unpaid)->credit_applied->toBe(0)->balance->toBe(1_000)
        ->and(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(0)
        ->and(CreditLedgerEntry::query()->where('balance_after', '<', 0)->exists())->toBeFalse();
});

test('credit partly spent in the meantime applies only what is left', function () {
    $client = billingClient();
    app(CreditLedger::class)->credit($client->id, Money::of(1_000, 'USD'), 'Goodwill');
    guardSpendingCredit($client, 600);

    $invoice = issueInvoice($client, [1_000], applyCredit: true);

    expect($invoice)->status->toBe(InvoiceStatus::Unpaid)->credit_applied->toBe(400)->balance->toBe(600)
        ->and(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(0);
});

describe('two invoices for one client applying its credit in real concurrent transactions (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('neither fails: one is paid by the credit, the other waits for it and is issued unpaid', function () {
        // Committed data the worker processes can see, on its own database: a client with 1,000 of credit.
        $this->useSeededDatabase('credit_race', function (): void {
            $client = billingClient(['email' => 'race@example.test']);
            app(CreditLedger::class)->credit($client->id, Money::of(1_000, 'USD'), 'Goodwill');
        });
        $client = Client::query()->where('email', 'race@example.test')->sole();
        $database = DB::connection()->getDatabaseName();

        // "slow" pauses 1.5 s between publishing (it holds the number) and applying the credit; "fast" starts 0.5 s
        // later. Before #192 "fast" inserted its invoice (a key-share lock on the client row), queued for the number,
        // and the credit lock of "slow" on the client then deadlocked with it: one renewal failed outright (40P01).
        $results = runRaceWorkers([
            'slow' => ['issue', $database, (string) $client->id, '1000', '0', '1500'],
            'fast' => ['issue', $database, (string) $client->id, '1000', '500', '0'],
        ]);

        expectCreditSpentOnce($results, $client);
    });
});

/**
 * Whichever worker got the credit first: one invoice paid by all 1,000, the other issued after it committed, unpaid.
 *
 * @param  array<string, array<string, mixed>>  $results
 */
function expectCreditSpentOnce(array $results, Client $client): void
{
    $sorted = collect($results)->sortBy('issued')->values();
    [$first, $second] = [$sorted[0], $sorted[1]];

    expect([$first['status'], $first['applied']])->toBe(['paid', 1_000])
        ->and([$second['status'], $second['applied']])->toBe(['unpaid', 0])
        ->and($second['issued'])->toBeGreaterThanOrEqual($first['ended'] - 0.05)
        ->and(Invoice::query()->whereKey($second['invoice'])->sole()->balance)->toBe(1_000)
        ->and(app(CreditLedger::class)->balance($client->id, 'USD')->amount)->toBe(0)
        ->and(CreditLedgerEntry::query()->where('client_id', $client->id)->where('balance_after', '<', 0)->exists())->toBeFalse();
}
