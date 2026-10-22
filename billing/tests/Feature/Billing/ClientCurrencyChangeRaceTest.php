<?php

use App\Modules\Billing\Models\Invoice;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/**
 * #218: a client's currency change and the client's first invoice, in real concurrent transactions. Whichever commits
 * first wins, and no invoice is ever left in a currency other than the client's.
 */
/**
 * Committed data the workers can see: an NPR client with no records. Each test seeds its own database, since the
 * workers' commits stay in it. With credit applied on generate (the default) an invoice locks the client for update;
 * without it, the invoice reads the client under a share lock.
 */
function seedCurrencyRaceClient(bool $applyCredit): Closure
{
    return function () use ($applyCredit): void {
        billingClient(['email' => 'currency-race@example.test', 'currency' => 'NPR']);
        Currency::factory()->npr()->create();
        billingSettings(['credit.auto_apply_on_generate' => $applyCredit]);
    };
}

describe('a currency change racing the first invoice (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('a change that locked the client first wins, and the invoice waiting for it is refused in the old currency', function (bool $applyCredit) {
        $this->useSeededDatabase('currency_race_change_first_'.($applyCredit ? 'credit' : 'plain'), seedCurrencyRaceClient($applyCredit));
        $this->client = Client::query()->where('email', 'currency-race@example.test')->sole();
        $this->database = DB::connection()->getDatabaseName();

        // "change" holds the client lock for 1.5 s after its check; "invoice" starts 0.4 s later, having read NPR.
        $results = runRaceWorkers([
            'change' => ['currency', $this->database, (string) $this->client->id, 'USD', '0', '1500'],
            'invoice' => ['plain', $this->database, (string) $this->client->id, '1000', '400', '0'],
        ]);

        expect($results['change']['status'])->toBe('changed')
            ->and($results['invoice']['status'])->toBe('refused')
            ->and($results['invoice']['message'])->toBe('Invoices must be in the client currency (USD).')
            ->and($this->client->fresh()->currency)->toBe('USD')
            ->and(Invoice::query()->where('client_id', $this->client->id)->exists())->toBeFalse();
    })->with(['credit applied on generate' => true, 'no credit applied' => false]);

    test('an invoice inserted first makes the change wait for it, then refuses the change', function () {
        $this->useSeededDatabase('currency_race_invoice_first', seedCurrencyRaceClient(false));
        $this->client = Client::query()->where('email', 'currency-race@example.test')->sole();
        $this->database = DB::connection()->getDatabaseName();

        // "invoice" pauses 1.5 s after inserting its row; "change" starts 0.4 s later and finds no invoice without the lock.
        $results = runRaceWorkers([
            'invoice' => ['plain', $this->database, (string) $this->client->id, '1000', '0', '1500'],
            'change' => ['currency', $this->database, (string) $this->client->id, 'USD', '400', '0'],
        ]);

        expect($results['invoice']['status'])->toBe('unpaid')
            ->and($results['invoice']['currency'])->toBe('NPR')
            ->and($results['change']['status'])->toBe('refused')
            ->and($results['change']['message'])->toBe('This client has billing records in NPR, so the currency can\'t be changed.')
            ->and($results['change']['ended'])->toBeGreaterThanOrEqual($results['invoice']['ended'] - 0.05)
            ->and($this->client->fresh()->currency)->toBe('NPR');
    });
});
