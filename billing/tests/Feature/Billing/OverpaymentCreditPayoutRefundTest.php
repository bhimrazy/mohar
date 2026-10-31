<?php

use App\Data\Billing\CallbackEvent;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Models\User;
use App\Modules\Billing\Actions\RefundAccountCredit;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\CreditReason;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\CreditLedgerEntry;
use App\Modules\Billing\Models\TaxNote;
use App\Modules\Billing\Models\TaxRule;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\CreditLedgerService;
use App\Modules\Billing\Services\GatewayCallbackHandler;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Currency;
use App\Support\Money;
use Carbon\CarbonImmutable;

require_once __DIR__.'/helpers.php';

/* Review probes for #464, adopted: credit paid out through a payment, late provider success, refund to credit of a numbered invoice. */

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    $this->vat = TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']);
    app(GatewayManager::class)->register(new SandboxCardDriver);
    sandboxGateway();
    $this->staff = User::factory()->staff(['billing.*'])->create()->id;
    $this->client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $this->balance = fn (): int => app(CreditLedgerService::class)->balance($this->client->id, 'NPR')->amount;
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('P1 mode on: overpayment credit paid out through its payment, then the sale refunded: the credit is not taken back twice', function () {
    useNepalVatRule($this->vat);
    $payment = payInvoice(issueInvoice($this->client, [10_000]), 12_000, 'ch_p1', 'sandbox_card');
    expect(($this->balance)())->toBe(700);

    // Staff pay the 700 overpayment back through the payment that placed it.
    app(RefundAccountCredit::class)->throughGateway($this->client, $payment, 700, null, $this->staff);

    // Then the whole sale is refunded.
    app(RefundTransaction::class)->handle($payment->fresh(), $payment->fresh()->refundable_remaining, RefundMode::Gateway, false, $this->staff, reason: 'Cancelled');

    $note = TaxNote::query()->sole();

    expect(($this->balance)())->toBe(0)->and($note->total)->toBe(10_600);
});

test('P1b mode off: overpayment credit paid out, then the provider reports a refund of the sale: the credit is not taken back twice', function () {
    $payment = payInvoice(issueInvoice($this->client, [10_000]), 12_000, 'ch_p1b', 'sandbox_card');
    app(RefundAccountCredit::class)->throughGateway($this->client, $payment, 700, null, $this->staff);
    expect(($this->balance)())->toBe(0);

    $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_p1b', 11_300, 'NPR', 'ch_p1b', raw: ['id' => 're_p1b']);
    $out = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    expect(($this->balance)())->toBe(0);
});

test('P3 refund to credit of a numbered invoice: one note, one credit row, idempotent amounts', function () {
    useNepalVatRule($this->vat);
    $payment = payInvoice(issueInvoice($this->client, [10_000]), 11_300, 'ch_p3', 'sandbox_card');

    $refund = app(RefundTransaction::class)->handle($payment, 11_300, RefundMode::ToCredit, false, $this->staff, reason: 'Moved to credit');

    expect(TaxNote::query()->count())->toBe(1)->and($refund->tax_note_id)->toBe(TaxNote::query()->value('id'))
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::RefundToCredit)->count())->toBe(1)
        ->and(($this->balance)())->toBe(11_300)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::CreditNote)->count())->toBe(0);
});

test('P3b refund to credit of an overpaid numbered invoice: the client never ends up with more credit than it paid', function () {
    useNepalVatRule($this->vat);
    $payment = payInvoice(issueInvoice($this->client, [10_000]), 12_000, 'ch_p3b', 'sandbox_card');
    $err = null;

    try {
        app(RefundTransaction::class)->handle($payment, 12_000, RefundMode::ToCredit, false, $this->staff, reason: 'Moved to credit');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }

    expect(($this->balance)())->toBeLessThanOrEqual(12_000);
});

test('P4 a late SUCCESS on a failed credit refund without a provider refund id leaves a trace on the review list', function () {
    $payment = payInvoice(issueInvoice($this->client, [10_000]), 11_300, 'ch_p4', 'sandbox_card');
    app(CreditLedgerService::class)->credit($this->client->id, Money::of(5_000, 'NPR'), 'opening');
    app(GatewayManager::class)->register(new class extends SandboxCardDriver
    {
        public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
        {
            return new ChargeResult(ChargeResult::DECLINED, declineReason: 'no');
        }
    });

    try {
        app(RefundAccountCredit::class)->throughGateway($this->client, $payment, 5_000, null, $this->staff);
    } catch (Throwable) {
    }
    $row = Transaction::query()->where('purpose', 'credit_refund')->sole();

    $event = new CallbackEvent(CallbackEvent::REFUND, null, '', 5_000, 'NPR', 'ch_p4', raw: [], reference: (string) $row->id);
    $out = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    expect($row->fresh()->review_amount)->not->toBeNull();
});
