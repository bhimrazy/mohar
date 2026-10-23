<?php

use App\Events\Billing\InvoiceOverdue;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PayMethod;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Services\BillingAutomation;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

/*
| Re-review probe (PR #429): the capture half of the reopen, with an invoice paid by card so auto-capture can act.
| A note that re-opens a paid invoice is a correction made today (adopted from the PR #429 review probe): the invoice
| gets a new due date on the normal terms, and the next automation run charges no late fee, sends no overdue notice and
| does not charge the stored card.
*/

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR', 'nepal_vat.enabled' => true,
        'late_fee.amount' => 10, 'late_fee.type' => 'percent', 'late_fee.days_overdue' => 7]);
    TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
});

afterEach(fn () => CarbonImmutable::setTestNow());


function rrReopenByCard(int $attempts): Invoice
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

test('rr probe: a card-paid invoice re-opened today is not captured on the next run', function () {
    $invoice = rrReopenByCard(0);

    expect($invoice->status)->toBe(InvoiceStatus::Unpaid)
        ->and(app(BillingAutomation::class)->autoCapture(CarbonImmutable::parse('2026-10-20')))->toBe(0);
});

test('rr probe: after re-opening, capture starts again on the new terms even if earlier retries were used up', function () {
    $invoice = rrReopenByCard(9);

    expect($invoice->capture_attempts)->toBe(0)->and($invoice->first_capture_failure_on)->toBeNull()
        ->and(app(BillingAutomation::class)->autoCapture(CarbonImmutable::parse('2026-11-02')))->toBe(1);
});
