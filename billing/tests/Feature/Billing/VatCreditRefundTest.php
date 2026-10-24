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
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Exceptions\BillingException;
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

/* ADR 0024 §5a: paying account credit out. A payout is not a supply: no note, no invoice change, mode on or off. */

class CreditRefundDriver extends SandboxCardDriver
{
    public static string $answer = ChargeResult::SUCCESS;

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        return self::$answer === ChargeResult::SUCCESS ? parent::refund($gateway, $request) : new ChargeResult(self::$answer, declineReason: 'scripted');
    }
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-10-20 06:00:00');
    inCompanyTimezone();
    Currency::factory()->create();
    Currency::factory()->npr()->create();
    billingSettings(['tax.enabled' => true, 'tax.home_country' => 'NP', 'tax.home_state' => '', 'tax.report_currency' => 'NPR']);
    useNepalVatRule(TaxRule::factory()->create(['name' => 'VAT', 'level' => 1, 'country' => 'NP', 'rate' => '13.000']));
    CreditRefundDriver::$answer = ChargeResult::SUCCESS;
    app(GatewayManager::class)->register(new CreditRefundDriver);
    sandboxGateway();
    $this->staff = User::factory()->staff(['billing.*'])->create()->id;
    $this->client = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);
    $this->credit = app(CreditLedgerService::class);
    $this->payment = payInvoice(issueInvoice($this->client, [10_000]), 11_300, 'ch_cr', 'sandbox_card');
    $this->credit->credit($this->client->id, Money::of(130_000, 'NPR'), 'opening');
    $this->balance = fn (): int => $this->credit->balance($this->client->id, 'NPR')->amount;
});

afterEach(fn () => CarbonImmutable::setTestNow());

function creditRefundNotes(): int
{
    return TaxNote::query()->count();
}

test('a refund made outside writes a credit_refunded row and a refund transaction, no note, no invoice change (AC19)', function () {
    $row = app(RefundAccountCredit::class)->recorded($this->client, 100_000, 'sandbox_card', 'BANK-1', 'cheque', $this->staff);

    $ledger = CreditLedgerEntry::query()->where('reason', CreditReason::CreditRefunded)->sole();
    expect(($this->balance)())->toBe(30_000)
        ->and($ledger->amount)->toBe(-100_000)->and($ledger->transaction_id)->toBe($row->id)
        ->and($row->purpose)->toBe('credit_refund')->and($row->invoice_id)->toBeNull()->and($row->status)->toBe(TransactionStatus::Succeeded)
        ->and(creditRefundNotes())->toBe(0)->and($this->payment->invoice->fresh()->amount_paid)->toBe(11_300);
});

test('a refund above the balance, or with a reference used before, is refused and leaves nothing', function () {
    expect(fn () => app(RefundAccountCredit::class)->recorded($this->client, 130_001, 'sandbox_card', 'BANK-2', null, $this->staff))->toThrow(BillingException::class);
    app(RefundAccountCredit::class)->recorded($this->client, 1_000, 'sandbox_card', 'BANK-3', null, $this->staff);

    expect(fn () => app(RefundAccountCredit::class)->recorded($this->client, 1_000, 'sandbox_card', 'BANK-3', null, $this->staff))->toThrow(BillingException::class)
        ->and(($this->balance)())->toBe(129_000)
        ->and(Transaction::query()->where('purpose', 'credit_refund')->count())->toBe(1);
});

test('through the gateway: the payout is posted before the call, the payment refundable falls, the invoice does not change', function () {
    CreditRefundDriver::$answer = ChargeResult::ERROR;
    $row = app(RefundAccountCredit::class)->throughGateway($this->client, $this->payment, 5_000, null, $this->staff);

    expect($row->status)->toBe(TransactionStatus::Pending)->and(($this->balance)())->toBe(125_000)
        ->and($this->payment->fresh()->refundable_remaining)->toBe(6_300)->and($this->payment->invoice->fresh()->amount_paid)->toBe(11_300)
        ->and(creditRefundNotes())->toBe(0);
});

test('a declined gateway credit refund reverses the payout and restores the refundable amount', function () {
    CreditRefundDriver::$answer = ChargeResult::DECLINED;

    expect(fn () => app(RefundAccountCredit::class)->throughGateway($this->client, $this->payment, 5_000, null, $this->staff))->toThrow(BillingException::class);

    $row = Transaction::query()->where('purpose', 'credit_refund')->sole();
    expect($row->status)->toBe(TransactionStatus::Failed)->and(($this->balance)())->toBe(130_000)
        ->and($this->payment->fresh()->refundable_remaining)->toBe(11_300)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::CreditRefundReleased)->sole()->amount)->toBe(5_000);
});

test('a confirmed gateway credit refund stands; SUCCESS on a settled row is a no-op', function () {
    $row = app(RefundAccountCredit::class)->throughGateway($this->client, $this->payment, 5_000, null, $this->staff);
    app(RefundTransaction::class)->settle($row->fresh(), 're_again');
    app(RefundTransaction::class)->release($row->fresh());

    expect($row->fresh()->status)->toBe(TransactionStatus::Succeeded)->and(($this->balance)())->toBe(125_000)
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::CreditRefunded)->count())->toBe(1)
        ->and($row->fresh()->review_amount)->toBe(5_000);
});

test('a payment that the provider paid after we released it re-posts the payout once and puts a to-do on the list', function () {
    CreditRefundDriver::$answer = ChargeResult::ERROR;
    $row = app(RefundAccountCredit::class)->throughGateway($this->client, $this->payment, 5_000, null, $this->staff);
    app(RefundTransaction::class)->release($row->fresh());
    // Another refund used the amount meanwhile.
    $this->payment->forceFill(['refundable_remaining' => 2_000])->save();

    $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_late', 5_000, 'NPR', 'ch_cr', raw: ['id' => 're_late'], reference: (string) $row->id);
    $first = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);
    $again = app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    expect($row->fresh()->status)->toBe(TransactionStatus::Succeeded)->and($row->fresh()->gateway_txn_id)->toBe('re_late')
        ->and(($this->balance)())->toBe(125_000)->and($this->payment->fresh()->refundable_remaining)->toBe(0)
        ->and($row->fresh()->review_amount)->toBe(5_000)->and($row->fresh()->raw['mohar']['late']['refundable_short'])->toBe(3_000)
        ->and($again['result'])->toBe('duplicate')
        ->and(CreditLedgerEntry::query()->where('reason', CreditReason::CreditRefunded)->count())->toBe(2)
        ->and(creditRefundNotes())->toBe(0)->and($this->payment->invoice->fresh()->amount_paid)->toBe(11_300);
});

test('the provider callback settles a pending credit refund and never goes down the invoice path', function () {
    CreditRefundDriver::$answer = ChargeResult::ERROR;
    $row = app(RefundAccountCredit::class)->throughGateway($this->client, $this->payment, 5_000, null, $this->staff);

    $event = new CallbackEvent(CallbackEvent::REFUND, null, 're_ok', 5_000, 'NPR', 'ch_cr', raw: ['id' => 're_ok'], reference: (string) $row->id);
    app(GatewayCallbackHandler::class)->process('sandbox_card', $event);

    expect($row->fresh()->status)->toBe(TransactionStatus::Succeeded)->and($row->fresh()->review_amount)->toBeNull()
        ->and(Transaction::query()->where('type', 'refund')->count())->toBe(1)->and($this->payment->invoice->fresh()->amount_paid)->toBe(11_300)
        ->and(($this->balance)())->toBe(125_000);
});

test('a second gateway refund of the payment is refused while one is pending, and a payment of another client is refused', function () {
    CreditRefundDriver::$answer = ChargeResult::ERROR;
    app(RefundAccountCredit::class)->throughGateway($this->client, $this->payment, 1_000, null, $this->staff);
    $other = Client::factory()->create(['country' => 'NP', 'currency' => 'NPR']);

    expect(fn () => app(RefundAccountCredit::class)->throughGateway($this->client, $this->payment, 1_000, null, $this->staff))->toThrow(BillingException::class)
        ->and(fn () => app(RefundAccountCredit::class)->throughGateway($other, $this->payment, 1_000, null, $this->staff))->toThrow(BillingException::class);
});

test('the refund credit route needs billing.credit.refund, and the gateway variant also billing.refunds.manage', function () {
    $payload = ['client_id' => $this->client->id, 'mode' => 'recorded', 'amount' => '10.00', 'method' => 'sandbox_card', 'reference' => 'BANK-9'];
    confirmPassword();

    actingAsStaff(['billing.credit.manage']);
    $this->post(route('admin.billing.credit.refund'), $payload)->assertForbidden();

    actingAsStaff(['billing.credit.refund']);
    confirmPassword();
    $this->post(route('admin.billing.credit.refund'), ['mode' => 'gateway', 'payment_id' => $this->payment->id] + $payload)->assertForbidden();
    expect(Transaction::query()->where('purpose', 'credit_refund')->count())->toBe(0);
});

test('staff with the permission and a recent password refund credit from the page', function () {
    $payload = ['client_id' => $this->client->id, 'mode' => 'recorded', 'amount' => '1000.00', 'method' => 'sandbox_card', 'reference' => 'BANK-10'];

    actingAsStaff(['billing.credit.refund', 'billing.credit.manage']);
    confirmPassword();
    $this->post(route('admin.billing.credit.refund'), $payload)->assertRedirect();

    expect(($this->balance)())->toBe(30_000);
});

test('the credit page offers the refundable payments by gateway name, date and invoice, not by key and provider id', function () {
    actingAsStaff(['billing.credit.manage', 'billing.credit.refund', 'billing.refunds.manage']);

    $this->get(route('admin.billing.credit.index', ['client' => $this->client->id]))->assertInertia(fn ($page) => $page
        ->has('refundable_payments', 1)
        ->where('refundable_payments.0.id', $this->payment->id)
        ->where('refundable_payments.0.label', fn (string $label): bool => ! str_contains($label, 'ch_cr') && ! str_contains($label, 'sandbox_card') && str_contains($label, '2026-10-20'))
        ->where('can.refund_gateway', true));
});
