<?php

use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

/*
| The capture half of a note that re-opens a paid invoice (adopted from the PR #429 re-review probe): the invoice was
| paid by card, so auto-capture can act on it. It is not charged on the next run, and capture starts again on the new
| terms even when earlier retries were used up.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR', 'nepal_vat.enabled' => true,
        'late_fee.amount' => 10, 'late_fee.type' => 'percent', 'late_fee.days_overdue' => 7]);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function reopenedCardInvoice(int $attempts): Invoice
{
    sandboxGateway();
    $client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    PayMethod::factory()->create(['client_id' => $client->id]);
    $invoice = issueInvoice($client, [1_000_000], dueDate: CarbonImmutable::parse('2026-08-01'));
    billingStaff();
    confirmPassword();
    $confirm = function (array $data) use ($invoice) {
        $preview = test()->getJson(route('admin.billing.invoices.notes.preview', [$invoice, ...$data]))->assertOk()->json();

        return test()->post(route('admin.billing.invoices.notes.store', $invoice), [...$data, 'version' => $preview['version'], 'digest' => $preview['digest']]);
    };
    $confirm(['kind' => 'credit', 'allocation' => 'amount', 'amount' => '1000.00', 'reason_code' => 'goodwill', 'reason_text' => 'Goodwill'])->assertSessionHasNoErrors();
    // Earlier capture failures exhausted the retries; the client then paid by card.
    $invoice->fresh()->forceFill(['capture_attempts' => $attempts, 'first_capture_failure_on' => '2026-07-31', 'last_capture_attempt_at' => '2026-08-05 06:00:00'])->save();
    payInvoice($invoice->fresh(), 1_030_000, gateway: 'sandbox_card');
    expect($invoice->fresh())->status->toBe(InvoiceStatus::Paid)->payment_gateway->toBe('sandbox_card');
    $confirm(['reverses' => TaxNote::query()->sole()->id, 'reason_text' => 'Goodwill withdrawn'])->assertSessionHasNoErrors();

    return $invoice->fresh();
}

test('a card-paid invoice re-opened today is not captured on the next run', function () {
    $invoice = reopenedCardInvoice(0);

    expect($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and(app(BillingAutomation::class)->autoCapture(CarbonImmutable::parse('2026-10-20')))->toBe(0);
});

test('after re-opening, capture starts again on the new terms even if earlier retries were used up', function () {
    $invoice = reopenedCardInvoice(9);

    expect($invoice->capture_attempts)->toBe(0)->and($invoice->first_capture_failure_on)->toBeNull()
        ->and(app(BillingAutomation::class)->autoCapture(CarbonImmutable::parse('2026-11-02')))->toBe(1);
});
