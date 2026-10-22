<?php

use App\Modules\Billing\Actions\CreateAddFundsInvoice;
use App\Modules\Billing\Actions\PublishInvoice;
use App\Modules\Billing\Actions\Quotes;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\ClientFlag;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\Quote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Support\InvoiceBsDates;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

/*
 * Billing's documents are dated on the company calendar (ADR 0013 Amendment 1). The company is in Kathmandu and time
 * is frozen at a UTC instant: 18:15:00 UTC is local midnight, 18:14:59 is the last second of the day before.
 */

beforeEach(fn () => inCompanyTimezone('Asia/Kathmandu'));
afterEach(fn () => CarbonImmutable::setTestNow());

function freezeUtc(string $instant): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant, 'UTC'));
}

dataset('boundary', [
    'last second of the day' => ['2026-10-18 18:14:59', '2026-10-18'],
    'local midnight' => ['2026-10-18 18:15:00', '2026-10-19'],
    'five past midnight' => ['2026-10-18 18:20:00', '2026-10-19'],
]);

it('dates a published invoice and its due date on the company day', function (string $utc, string $date) {
    $draft = issueInvoice(billingClient(), status: 'draft');
    freezeUtc($utc);

    app(PublishInvoice::class)->handle($draft);

    expect($draft->fresh()->issue_date->toDateString())->toBe($date)
        ->and($draft->fresh()->due_date->toDateString())->toBe(CarbonImmutable::parse($date)->addDays(14)->toDateString());
})->with('boundary');

it('numbers an invoice paid after local midnight in the new period and dates it that day', function (string $utc, string $number, string $issued) {
    billingSettings(['sequential_paid_numbering' => true, 'set_issue_date_on_payment' => true, 'number_reset' => 'yearly', 'number_format' => '{YEAR}-{NUMBER}']);
    $invoice = issueInvoice(billingClient());
    freezeUtc($utc);

    payInvoice($invoice, $invoice->total);

    expect($invoice->fresh())->number->toBe($number)->and($invoice->fresh()->issue_date->toDateString())->toBe($issued);
})->with([
    'last second of 2026' => ['2026-12-31 18:14:59', '2026-1', '2026-12-31'],
    'first second of 2027' => ['2026-12-31 18:15:00', '2027-1', '2027-01-01'],
]);

it('resets a monthly sequence on the company month', function (string $utc, string $prefix) {
    billingSettings(['number_reset' => 'monthly', 'number_format' => '{YEAR}{MONTH}{DAY}-{NUMBER}']);
    $draft = issueInvoice(billingClient(), status: 'draft');
    freezeUtc($utc);

    app(PublishInvoice::class)->handle($draft);

    expect($draft->fresh()->number)->toBe($prefix.'-1');
})->with([
    ['2026-10-31 18:14:59', '20261031'],
    ['2026-10-31 18:15:00', '20261101'],
]);

it('shows the BS date of the company day, at 1 Baisakh and 1 Shrawan', function (string $utc, string $label) {
    billingSettings(['show_bs_dates' => true]);
    $draft = issueInvoice(billingClient(), status: 'draft');
    freezeUtc($utc);

    app(PublishInvoice::class)->handle($draft);

    expect(app(InvoiceBsDates::class)->label($draft->fresh()->issue_date))->toBe($label);
})->with([
    'last day of Chaitra' => ['2026-04-13 18:14:59', '30 Chaitra 2082 BS'],
    '1 Baisakh 2083' => ['2026-04-13 18:15:00', '1 Baisakh 2083 BS'],
    '1 Baisakh, evening' => ['2026-04-14 10:00:00', '1 Baisakh 2083 BS'],
    'last day of Asar' => ['2026-07-16 18:14:59', '32 Asar 2083 BS'],
    '1 Shrawan 2083' => ['2026-07-16 18:15:00', '1 Shrawan 2083 BS'],
    '1 Shrawan, five past midnight' => ['2026-07-16 18:20:00', '1 Shrawan 2083 BS'],
]);

it('turns an invoice overdue at company midnight', function (string $utc, bool $overdue, int $days) {
    $invoice = Invoice::factory()->create(['client_id' => billingClient()->id, 'due_date' => '2026-10-18']);
    freezeUtc($utc);

    expect($invoice->isOverdue())->toBe($overdue)
        ->and($invoice->daysOverdue())->toBe($days)
        ->and(Invoice::query()->overdue()->whereKey($invoice->id)->exists())->toBe($overdue);
})->with([
    ['2026-10-18 18:14:59', false, 0],
    ['2026-10-18 18:15:00', true, 1],
]);

it('expires a card at the end of its month on the company calendar', function (string $utc, bool $expired) {
    $card = PayMethod::factory()->create(['client_id' => billingClient()->id, 'expiry_year' => 2026, 'expiry_month' => 10]);
    freezeUtc($utc);

    expect($card->isExpired())->toBe($expired);
})->with([
    ['2026-10-31 18:14:59', false],
    ['2026-10-31 18:15:00', true],
]);

it('applies a tax rule from its effective date on the company day', function (string $utc, int $tax) {
    billingSettings(['tax.enabled' => true]);
    TaxRule::factory()->create(['rate' => '19.000']);
    TaxRule::factory()->create(['rate' => '21.000', 'priority' => 0, 'effective_from' => '2026-10-19']);
    $client = billingClient();
    freezeUtc($utc);

    expect(issueInvoice($client, [10_000])->tax1_amount)->toBe($tax);
})->with([
    ['2026-10-18 18:14:59', 1_900],
    ['2026-10-18 18:15:00', 2_100],
]);

it('sets the add-funds due date and a quote validity from the company day', function () {
    $client = billingClient();
    ClientFlag::query()->create(['client_id' => $client->id, 'first_order_accepted_at' => now()]);
    freezeUtc('2026-10-18 18:20:00');

    $quote = app(Quotes::class)->save(['client_id' => $client->id, 'subject' => 'Q', 'items' => [['description' => 'Hosting', 'quantity' => 1, 'unit_amount' => 10_000]]]);

    expect($quote->valid_until->toDateString())->toBe('2026-11-18')
        ->and(app(CreateAddFundsInvoice::class)->handle($client, 5_000)->due_date->toDateString())->toBe('2026-10-19');
});

it('refuses a quote on the first company day after its validity', function (string $utc, bool $accepted) {
    $quote = Quote::factory()->delivered()->create(['client_id' => billingClient()->id, 'valid_until' => '2026-10-18']);
    freezeUtc($utc);

    $accept = fn () => app(Quotes::class)->accept($quote);
    $accepted ? expect($accept()->stage->value)->toBe('accepted') : expect($accept)->toThrow(BillingException::class);
})->with([
    ['2026-10-18 18:14:59', true],
    ['2026-10-18 18:15:00', false],
]);

it('lists numbered invoices created between 00:00 and 05:45 Nepal time and rewrites nothing', function () {
    $client = billingClient();
    $early = Invoice::factory()->create(['client_id' => $client->id, 'number' => '41', 'issue_date' => '2026-10-18', 'created_at' => '2026-10-18 18:20:00']);
    $edge = Invoice::factory()->create(['client_id' => $client->id, 'number' => '42', 'issue_date' => '2026-10-18', 'created_at' => '2026-10-18 18:15:00']);
    Invoice::factory()->create(['client_id' => $client->id, 'number' => '43', 'created_at' => '2026-10-18 18:14:59']);
    Invoice::factory()->create(['client_id' => $client->id, 'number' => '44', 'created_at' => '2026-10-18 05:00:00']);
    Invoice::factory()->create(['client_id' => $client->id, 'number' => null, 'created_at' => '2026-10-18 20:00:00']);

    $this->artisan('billing:report-utc-dates')
        ->expectsOutputToContain('41')->expectsOutputToContain('42')->expectsOutputToContain('2 numbered invoice(s)')
        ->doesntExpectOutputToContain('43')->doesntExpectOutputToContain('44')
        ->assertSuccessful();

    expect($early->fresh()->issue_date->toDateString())->toBe('2026-10-18')->and($edge->fresh()->issue_date->toDateString())->toBe('2026-10-18');
});

it('tells the owner when no invoice is affected, and caps the listing', function () {
    $this->artisan('billing:report-utc-dates')->expectsOutputToContain('No numbered invoice')->assertSuccessful();

    foreach ([1, 2, 3] as $n) {
        Invoice::factory()->create(['client_id' => billingClient()->id, 'number' => (string) $n, 'created_at' => '2026-10-18 19:00:00']);
    }

    $this->artisan('billing:report-utc-dates', ['--limit' => 2])->expectsOutputToContain('3 numbered invoice(s)')->expectsOutputToContain('2 listed')->assertSuccessful();
});
