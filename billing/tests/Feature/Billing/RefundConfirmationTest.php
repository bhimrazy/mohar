<?php

use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Core\Models\ActivityLog;

require_once __DIR__.'/helpers.php';

require_once __DIR__.'/WebhookHelpers.php';

/**
 * Sandbox driver whose refund answer is scripted; `$during` runs inside the provider call (e.g. a callback landing first).
 */
class ScriptedRefundDriver extends SandboxCardDriver
{
    public static string $answer = ChargeResult::SUCCESS;

    public static int $calls = 0;

    /** @var list<string> */
    public static array $references = [];

    public static ?Closure $during = null;

    public static ChargeResult $lookup;

    /** @var list<string> */
    public static array $lookedUp = [];

    public function capabilities(): array
    {
        return [...parent::capabilities(), self::REFUND_LOOKUP];
    }

    public function lookupRefund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        self::$lookedUp[] = (string) $request->reference;

        return self::$lookup;
    }

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        self::$calls++;
        self::$references[] = (string) $request->reference;

        if (self::$during !== null) {
            (self::$during)($request);
        }

        return self::$answer === ChargeResult::SUCCESS
            ? parent::refund($gateway, $request)
            : new ChargeResult(self::$answer, declineReason: 'scripted_'.self::$answer, raw: ['scripted' => self::$answer]);
    }
}

beforeEach(function () {
    ScriptedRefundDriver::$answer = ChargeResult::SUCCESS;
    ScriptedRefundDriver::$calls = 0;
    ScriptedRefundDriver::$references = [];
    ScriptedRefundDriver::$during = null;
    ScriptedRefundDriver::$lookedUp = [];
    ScriptedRefundDriver::$lookup = new ChargeResult(ChargeResult::ERROR, declineReason: 'unreachable');
    app(GatewayManager::class)->register(new ScriptedRefundDriver);
    sandboxGateway();
});

function refundablePayment(int $amount = 10_000): Transaction
{
    return payInvoice(issueInvoice(billingClient(), [$amount]), $amount, 'ch_'.uniqid(), 'sandbox_card');
}

test('a provider timeout keeps the refund pending with the amount reserved, and a second attempt makes no provider call', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;

    $pending = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway);

    expect($pending->status)->toBe(TransactionStatus::Pending)->and($pending->awaitsProviderConfirmation())->toBeTrue()
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000)
        ->and($payment->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    expect(fn () => app(RefundTransaction::class)->handle($payment->fresh(), 3_000, RefundMode::Gateway))
        ->toThrow(BillingException::class, 'being confirmed with the provider');

    expect(ScriptedRefundDriver::$calls)->toBe(1)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1)
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000);
});

test('a pending answer is treated like a timeout', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::PENDING;

    expect(app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway)->status)->toBe(TransactionStatus::Pending)
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000);
});

test('a definite decline fails the refund and releases the amount', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::DECLINED;

    expect(fn () => app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway))->toThrow(BillingException::class, 'scripted_declined');

    expect($payment->fresh()->refundable_remaining)->toBe(10_000)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole()->status)->toBe(TransactionStatus::Failed);

    // Nothing is pending any more, so staff may try again.
    ScriptedRefundDriver::$answer = ChargeResult::SUCCESS;
    expect(app(RefundTransaction::class)->handle($payment->fresh(), 4_000, RefundMode::Gateway)->status)->toBe(TransactionStatus::Succeeded);
});

test('the provider callback settles the pending refund row, once, whatever else it carries', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway, reverseEffects: false);

    $callback = ['type' => 'charge.refunded', 'txn_id' => 're_late_1', 'parent_txn_id' => $payment->gateway_txn_id, 'amount' => 4_000, 'currency' => 'USD', 'reference' => (string) $pending->id];
    sandboxWebhook($callback)->assertOk()->assertJson(['result' => 'refund recorded']);
    sandboxWebhook($callback)->assertOk();

    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->id->toBe($pending->id)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_late_1')
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(6_000);
});

test('a callback for a different amount leaves the pending refund for staff', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway);

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_other', 'parent_txn_id' => $payment->gateway_txn_id, 'amount' => 1_000, 'currency' => 'USD', 'reference' => (string) $pending->id])
        ->assertOk()->assertJson(['result' => 'refund differs from the pending refund; staff review']);

    expect($pending->fresh()->status)->toBe(TransactionStatus::Pending)->and($payment->fresh()->refundable_remaining)->toBe(6_000);
});

test('a late ERROR or decline after the callback settled the refund changes nothing', function (string $late) {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = $late;
    // The provider's refund callback is booked while our call is still waiting for its answer.
    ScriptedRefundDriver::$during = fn (RefundRequest $request) => app(RefundTransaction::class)->settle(Transaction::query()->findOrFail((int) $request->reference), 're_first');

    $result = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway);

    $refund = Transaction::query()->where('type', TransactionType::Refund)->sole();
    expect($refund)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_first')
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(6_000)
        ->and($result->status)->toBe(TransactionStatus::Succeeded);
})->with([ChargeResult::ERROR, ChargeResult::DECLINED]);

test('staff see that the refund is being confirmed, not an error, and no retry is invited', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.transactions.refund', $payment), ['amount' => '40.00', 'mode' => 'gateway', 'reverse_effects' => false])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => 'The refund is being confirmed with the provider.']);
});

function pendingRefund(int $amount = 4_000): array
{
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;

    return [$payment, app(RefundTransaction::class)->handle($payment, $amount, RefundMode::Gateway)];
}

test('Check refund asks the provider about the same reference and settles the same row when it was made', function () {
    [$payment, $pending] = pendingRefund();
    ScriptedRefundDriver::$lookup = new ChargeResult(ChargeResult::SUCCESS, 're_found');
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.transactions.refund.check', $pending))->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect(ScriptedRefundDriver::$lookedUp)->toBe([(string) $pending->id])
        ->and(ScriptedRefundDriver::$references)->toBe([(string) $pending->id])
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole())->id->toBe($pending->id)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_found')
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000)
        ->and(ScriptedRefundDriver::$calls)->toBe(1);
});

test('Check refund releases the amount only when the provider says there is no such refund', function (string $answer, string $status, int $remaining) {
    [$payment, $pending] = pendingRefund();
    ScriptedRefundDriver::$lookup = new ChargeResult($answer, declineReason: 'x');

    $row = app(RefundTransaction::class)->check($pending);

    expect($row->status->value)->toBe($status)->and($payment->fresh()->refundable_remaining)->toBe($remaining);
})->with([
    'no such refund' => [ChargeResult::DECLINED, 'failed', 10_000],
    'unreachable' => [ChargeResult::ERROR, 'pending', 6_000],
    'still processing' => [ChargeResult::PENDING, 'pending', 6_000],
]);

test('staff resolve a pending refund by hand with step-up, the refund permission and an audit entry', function () {
    [$payment, $pending] = pendingRefund();
    $staff = billingStaff();

    // Step-up first.
    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed'])->assertSessionHasErrors();
    expect($pending->fresh()->status)->toBe(TransactionStatus::Pending);

    confirmPassword();
    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed'])->assertSessionHasNoErrors();
    expect($pending->fresh())->status->toBe(TransactionStatus::Succeeded)
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000)
        ->and(ActivityLog::query()->where('description', 'Refund confirmed at the provider by staff')->where('user_id', $staff->id)->exists())->toBeTrue();

    // Settled: a second resolution is refused.
    expect(fn () => app(RefundTransaction::class)->resolve($pending->fresh(), false))->toThrow(BillingException::class);
});

test('"Not refunded" releases the amount, and a callback that later proves the provider paid is booked once and flagged', function () {
    [$payment, $pending] = pendingRefund();
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'not_refunded'])->assertSessionHasNoErrors();
    expect($pending->fresh()->status)->toBe(TransactionStatus::Failed)->and($payment->fresh()->refundable_remaining)->toBe(10_000);

    $callback = ['type' => 'charge.refunded', 'txn_id' => 're_surprise', 'parent_txn_id' => $payment->gateway_txn_id, 'amount' => 4_000, 'currency' => 'USD', 'reference' => (string) $pending->id];
    sandboxWebhook($callback)->assertOk();
    sandboxWebhook($callback)->assertOk()->assertJson(['result' => 'duplicate']);

    $booked = Transaction::query()->where('type', TransactionType::Refund)->where('status', TransactionStatus::Succeeded)->sole();
    expect($booked)->gateway_txn_id->toBe('re_surprise')->amount_out->toBe(4_000)
        ->and($booked->review_amount)->toBe(4_000)
        ->and($pending->fresh()->status)->toBe(TransactionStatus::Failed)
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000);
});

test('the callback and Check refund cannot book one provider refund twice', function () {
    [$payment, $pending] = pendingRefund();
    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_one', 'parent_txn_id' => $payment->gateway_txn_id, 'amount' => 4_000, 'currency' => 'USD', 'reference' => (string) $pending->id])->assertOk();

    ScriptedRefundDriver::$lookup = new ChargeResult(ChargeResult::SUCCESS, 're_one');
    expect(fn () => app(RefundTransaction::class)->check($pending->fresh()))->toThrow(BillingException::class, 'not waiting');

    // The same provider refund id booked on another row settles nothing here: this row is released instead.
    [$second, $other] = pendingRefund(1_000);
    $row = app(RefundTransaction::class)->settle($other, 're_one');
    expect($row->id)->toBe($pending->id)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->where('status', TransactionStatus::Succeeded)->count())->toBe(1)
        ->and($other->fresh()->status)->toBe(TransactionStatus::Failed);
});

test('staff without the refund permission can neither check nor resolve a pending refund', function () {
    [, $pending] = pendingRefund();
    actingAsStaff(['billing.payments.manage']);
    confirmPassword();

    $this->post(route('admin.billing.transactions.refund.check', $pending))->assertForbidden();
    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed'])->assertForbidden();
    expect($pending->fresh()->status)->toBe(TransactionStatus::Pending);
});
