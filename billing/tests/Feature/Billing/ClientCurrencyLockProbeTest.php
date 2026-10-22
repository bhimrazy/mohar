<?php

use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Models\Quote;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/**
 * Review probes for PR #237 (#218), PostgreSQL only: real concurrent transactions through credit-worker.php.
 */
function seedCurrencyProbeClient(bool $withQuote): Closure
{
    return function () use ($withQuote): void {
        $client = billingClient(['email' => 'currency-probe@example.test', 'currency' => 'NPR']);
        Currency::factory()->npr()->create();
        // The defaults: credit applied on generate, quotes invoiced on acceptance.
        billingSettings(['credit.auto_apply_on_generate' => true]);

        if ($withQuote) {
            app(Quotes::class)->save(['client_id' => $client->id, 'subject' => 'Probe quote', 'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_amount' => 10_000]]]);
        }
    };
}

describe('PR #237 probes (PostgreSQL)', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    // Quotes::save now takes FOR SHARE on the client before it writes the quote (client -> quote), while
    // Quotes::convert / accept lock the quote and then IssueInvoice locks the client FOR UPDATE (quote -> client).
    // A staff edit of a quote at the moment it is converted or accepted deadlocks (40P01).
    test('saving a quote while it is converted does not deadlock', function () {
        $this->useSeededDatabase('currency_probe_quote_deadlock', seedCurrencyProbeClient(true));
        $quote = Quote::query()->whereHas('client', fn ($q) => $q->where('email', 'currency-probe@example.test'))->sole();

        // "save" holds the client share lock 1.5 s before writing the quote; "convert" locks the quote 0.4 s in.
        $results = runRaceWorkers([
            'save' => ['quote-save', DB::connection()->getDatabaseName(), (string) $quote->id, '20000', '0', '1500'],
            'convert' => ['quote-convert', DB::connection()->getDatabaseName(), (string) $quote->id, '0', '400', '0'],
        ]);

        // Either order is fine once the locks agree (quote, then client): the edit lands before the conversion, or is
        // refused after it. On the PR head the save worker fails with SQLSTATE 40P01 instead.
        expect($results['convert']['status'])->toBe('converted')
            ->and($results['save']['status'])->toBeIn(['saved', 'refused']);
    });

    // Coverage the PR's race test lacks: SaveDraftInvoice and Quotes::save waiting for a currency change.
    test('a draft invoice or quote waiting for a currency change is saved in the new currency', function (string $mode) {
        $this->useSeededDatabase('currency_probe_'.str_replace('-', '_', $mode), seedCurrencyProbeClient(false));
        $client = Client::query()->where('email', 'currency-probe@example.test')->sole();

        $results = runRaceWorkers([
            'change' => ['currency', DB::connection()->getDatabaseName(), (string) $client->id, 'USD', '0', '1500'],
            'row' => [$mode, DB::connection()->getDatabaseName(), (string) $client->id, '1000', '400', '0'],
        ]);

        expect($results['change']['status'])->toBe('changed')
            ->and($results['row']['currency'])->toBe('USD')
            ->and($client->fresh()->currency)->toBe('USD');
    })->with(['draft invoice' => 'draft', 'quote' => 'quote-new']);
});
