<?php

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/Support/race.php';

/*
| Review-board probes for PR #369 (VAT PR 1a).
*/

afterEach(fn () => CarbonImmutable::setTestNow());

function pr369Nepal(): Client
{
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);

    return Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
}

test('probe C1: a proforma paid at 18:15:00 UTC is dated the next Nepal day in BS; at 18:14:59 the same day', function (string $at, string $bs) {
    inCompanyTimezone('Asia/Kathmandu');
    CarbonImmutable::setTestNow('2026-10-20 10:00:00 UTC');
    $client = pr369Nepal();
    billingSettings(['sequential_paid_numbering' => true, 'set_issue_date_on_payment' => true]);
    $invoice = issueInvoice($client, [10_000]);

    CarbonImmutable::setTestNow($at);
    payInvoice($invoice, 11_300);

    expect($invoice->fresh())->bs_issue_date->toBe($bs)->numbered_at->toEqual(CarbonImmutable::parse($at));
})->with([
    'just after Nepal midnight' => ['2026-10-20 18:15:00 UTC', '2083-07-04'],
    'just before Nepal midnight' => ['2026-10-20 18:14:59 UTC', '2083-07-03'],
]);

test('probe D1: a value adjustment the previous release writes on an invoice the new release numbered is counted by the command', function () {
    $invoice = issueInvoice(pr369Nepal(), [10_000])->fresh();
    expect($invoice->numbered_at)->not->toBeNull();

    // Blue-green: the previous release (no credited_total/debited_total code) applies a late fee on this invoice.
    DB::table('invoice_adjustments')->insert(['invoice_id' => $invoice->id, 'kind' => 'debit', 'reason' => 'late_fee', 'amount' => 565,
        'taxable_amount' => 500, 'tax_amount' => 65, 'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()]);

    $this->artisan('billing:fiscal-snapshot')->assertSuccessful();

    // AC 9 / ADR 0024 §3: debited_total equals the value adjustments after numbered_at.
    expect($invoice->fresh()->debited_total)->toBe(565);
});

describe('PostgreSQL races', function () {
    beforeEach(function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs separate committed sessions on one server database (PostgreSQL).');
        }
    });

    test('probe R1: two payments that together settle a proforma number it exactly once, with one snapshot', function () {
        $this->useSeededDatabase('pr369_pay_pay', function (): void {
            $client = billingClient(['email' => 'pr369-race@example.test']);
            billingSettings(['sequential_paid_numbering' => true]);
            issueInvoice($client, [1_000]);
        });
        $client = Client::query()->where('email', 'pr369-race@example.test')->sole();
        $id = Invoice::query()->where('client_id', $client->id)->sole()->id;
        $database = DB::connection()->getDatabaseName();

        // First holds the invoice lock for 1.5 s after its payment row; second waits, then settles.
        $results = runRaceWorkers([
            'first' => ['pay', $database, (string) $id, '600', '0', '1500'],
            'second' => ['pay', $database, (string) $id, '400', '300', '0'],
        ]);

        $invoice = Invoice::query()->findOrFail($id);
        $lastPayment = DB::table('transactions')->where('invoice_id', $id)->max('created_at');

        expect(collect($results)->pluck('status')->sort()->values()->all())->toBe(['paid', 'unpaid'])
            ->and($invoice->number)->toBe('1')
            ->and($invoice->numbered_at)->not->toBeNull()
            ->and($invoice->numbered_at->greaterThanOrEqualTo(CarbonImmutable::parse($lastPayment, 'UTC')))->toBeTrue()
            ->and($invoice->fiscal_total)->toBe(1_000)
            ->and(DB::table('billing_sequences')->where('sequence', 'invoice')->value('next_number'))->toBe(2);
    });
});
