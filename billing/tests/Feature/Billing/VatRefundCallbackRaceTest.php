<?php

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }
});

dataset('vat_refund_callback_cases', [
    'plain invoice' => ['ch_plain', 11_300, 5_000],
    'overpaid invoice (credit first)' => ['ch_over', 12_000, 1_000],
]);

test('P4: two deliveries of one provider refund id at once book one refund and one note', function (string $charge, int $paid, int $refund) {
    $name = 'vat_refund_cb_'.substr($charge, 3);
    test()->useSeededDatabase($name, function () use ($charge, $paid): void {
        Currency::factory()->create();
        Currency::factory()->npr()->create();
        billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
        useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
        sandboxGateway();
        User::factory()->staff(['billing.*'])->create();
        $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
        payInvoice(issueInvoice($client, [10_000]), $paid, $charge, 'sandbox_card');
    });

    $database = DB::connection()->getDatabaseName();
    $start = (string) ((int) (microtime(true) * 1000) + 2_500);
    $results = runRaceWorkers([
        'a' => ['cb', $database, 're_dup', $charge, (string) $refund, $start, '1500'],
        'b' => ['cb', $database, 're_dup', $charge, (string) $refund, $start, '1500'],
    ], script: __DIR__.'/Support/provider-refund-worker.php');

    $payment = Transaction::query()->where('gateway_txn_id', $charge)->sole();
    $credit = app(CreditLedgerService::class)->balance($payment->client_id, 'NPR')->amount;

    $payment = Transaction::query()->where('gateway_txn_id', $charge)->sole();

    // One refund row and one note, whichever delivery got there first; on an overpaid invoice the credit is taken back once.
    expect(Transaction::query()->where('type', 'refund')->count())->toBe(1)
        ->and(TaxNote::query()->count())->toBe(1)
        ->and(app(CreditLedgerService::class)->balance($payment->client_id, 'NPR')->amount)->toBe(0);
})->with('vat_refund_callback_cases');
