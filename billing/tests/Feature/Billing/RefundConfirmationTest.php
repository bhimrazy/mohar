<?php

use App\Contracts\Billing\Invoicer;
use App\Contracts\Billing\LooksUpRefunds;
use App\Data\Billing\ChargeResult;
use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Events\Billing\PaymentReversed;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Drivers\Gateways\GatewayManager;
use App\Modules\Billing\Drivers\Gateways\SandboxCardDriver;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Enums\TransactionStatus;
use App\Modules\Billing\Enums\TransactionType;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\Transaction;
use App\Modules\Billing\Services\GatewayAmounts;
use App\Modules\Core\Models\ActivityLog;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/helpers.php';

require_once __DIR__.'/WebhookHelpers.php';

/**
 * Sandbox driver whose refund answer is scripted. `$during` runs inside the provider call (a callback landing first);
 * `$throw` stands in for a worker dying mid-call. This one can't be asked about a refund (staff settle by hand).
 */
class ScriptedRefundDriver extends SandboxCardDriver
{
    public static string $answer = ChargeResult::SUCCESS;

    public static bool $throw = false;

    public static int $calls = 0;

    /** @var list<string> */
    public static array $references = [];

    public static ?Closure $during = null;

    public function refund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        self::$calls++;
        self::$references[] = (string) $request->reference;

        if (self::$during !== null) {
            (self::$during)($request);
        }

        if (self::$throw) {
            throw new RuntimeException('the worker died during the provider call');
        }

        return self::$answer === ChargeResult::SUCCESS
            ? parent::refund($gateway, $request)
            : new ChargeResult(self::$answer, declineReason: 'scripted_'.self::$answer, raw: ['scripted' => self::$answer]);
    }
}

/** The same, for a provider that can be asked. */
class ScriptedLookupDriver extends ScriptedRefundDriver implements LooksUpRefunds
{
    public static ChargeResult $lookup;

    /** @var list<RefundRequest> */
    public static array $lookedUp = [];

    public function lookupRefund(GatewayConfig $gateway, RefundRequest $request): ChargeResult
    {
        self::$lookedUp[] = $request;

        return self::$lookup;
    }
}

beforeEach(function () {
    ScriptedRefundDriver::$answer = ChargeResult::SUCCESS;
    ScriptedRefundDriver::$throw = false;
    ScriptedRefundDriver::$calls = 0;
    ScriptedRefundDriver::$references = [];
    ScriptedRefundDriver::$during = null;
    ScriptedLookupDriver::$lookedUp = [];
    ScriptedLookupDriver::$lookup = new ChargeResult(ChargeResult::ERROR, declineReason: 'unreachable');
    app(GatewayManager::class)->register(new ScriptedRefundDriver);
    sandboxGateway();
});

function useLookupDriver(): void
{
    app(GatewayManager::class)->register(new ScriptedLookupDriver);
}

function refundablePayment(int $amount = 10_000): Transaction
{
    return payInvoice(issueInvoice(billingClient(), [$amount]), $amount, 'ch_'.uniqid(), 'sandbox_card');
}

/** A payment with a gateway refund whose answer was an error: pending, amount reserved. */
function pendingRefund(int $amount = 4_000): array
{
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;

    return [$payment, app(RefundTransaction::class)->handle($payment, $amount, RefundMode::Gateway)];
}

function sandboxRefundCallback(Transaction $payment, Transaction $refund, string $id, ?int $amount = null, string $currency = 'USD'): array
{
    return ['type' => 'charge.refunded', 'txn_id' => $id, 'parent_txn_id' => $payment->gateway_txn_id, 'amount' => $amount ?? $refund->amount_out, 'currency' => $currency, 'reference' => (string) $refund->id];
}

/** What a worker killed mid-call leaves behind: a pending row that kept only what the reservation wrote. */
function crashedRefund(int $minutesOld): array
{
    [$payment, $pending] = pendingRefund();
    $state = $pending->raw[Transaction::REFUND_STATE];
    unset($state['answer'], $state['reason']);
    $pending->forceFill(['raw' => [Transaction::REFUND_STATE => $state], 'created_at' => now()->subMinutes($minutesOld)])->save();

    return [$payment, $pending->fresh()];
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

test('an exception from the driver counts as an unknown answer, not a decline', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$throw = true;

    $pending = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway);

    expect($pending->status)->toBe(TransactionStatus::Pending)->and($pending->canBeResolved())->toBeTrue()
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000);
});

test('a definite decline fails the refund and releases the amount', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::DECLINED;

    expect(fn () => app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway))->toThrow(BillingException::class, 'scripted_declined');

    expect($payment->fresh()->refundable_remaining)->toBe(10_000)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole()->status)->toBe(TransactionStatus::Failed);

    ScriptedRefundDriver::$answer = ChargeResult::SUCCESS;
    expect(app(RefundTransaction::class)->handle($payment->fresh(), 4_000, RefundMode::Gateway)->status)->toBe(TransactionStatus::Succeeded);
});

test('the provider callback settles the pending refund row, once', function () {
    [$payment, $pending] = pendingRefund();
    $callback = sandboxRefundCallback($payment, $pending, 're_late_1');

    sandboxWebhook($callback)->assertOk()->assertJson(['result' => 'refund recorded']);
    sandboxWebhook($callback)->assertOk();

    expect(Transaction::query()->where('type', TransactionType::Refund)->sole())->id->toBe($pending->id)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_late_1')
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(6_000);
});

test('the callback keeps the reverse-effects choice made when the refund was reserved', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway, reverseEffects: true);
    Event::fake([PaymentReversed::class]);

    sandboxWebhook(sandboxRefundCallback($payment, $pending, 're_rev'))->assertOk();

    Event::assertDispatched(PaymentReversed::class);
});

test('a callback that lands while our request is still running settles the row, and the late answer changes nothing', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;
    ScriptedRefundDriver::$during = fn (RefundRequest $request) => sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_early', 'parent_txn_id' => $payment->gateway_txn_id, 'amount' => 4_000, 'currency' => 'USD', 'reference' => $request->reference])
        ->assertOk()->assertJson(['result' => 'refund recorded']);

    $result = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway);

    expect($result)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_early')
        ->and(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1)
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(6_000);
});

test('a callback for another amount, currency or payment leaves the pending refund for staff', function (array $change) {
    [$payment, $pending] = pendingRefund();
    $other = refundablePayment();

    $callback = [...sandboxRefundCallback($payment, $pending, 're_other'), ...($change['parent'] ?? false ? ['parent_txn_id' => $other->gateway_txn_id] : $change)];
    sandboxWebhook($callback)->assertOk()->assertJson(['result' => 'refund differs from the pending refund; staff review']);

    expect($pending->fresh()->status)->toBe(TransactionStatus::Pending)->and($payment->fresh()->refundable_remaining)->toBe(6_000);
})->with([
    'amount' => [['amount' => 1_000]],
    'currency' => [['currency' => 'EUR']],
    'payment' => [['parent' => true]],
]);

test('on a payment charged in another currency the callback for the exact refund we sent settles the row', function () {
    $payment = refundablePayment(100_000);
    $payment->forceFill(['gateway_amount' => 750, 'gateway_currency' => 'EUR'])->save();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;
    $row = app(RefundTransaction::class)->handle($payment->fresh(), 33_340, RefundMode::Gateway);
    $sent = app(GatewayAmounts::class)->toGateway($payment->fresh(), 33_340);
    expect($sent->amount)->toBe(250);

    sandboxWebhook(sandboxRefundCallback($payment, $row, 're_fx', 250, 'EUR'))->assertOk()->assertJson(['result' => 'refund recorded']);

    expect($row->fresh())->status->toBe(TransactionStatus::Succeeded)->amount_out->toBe(33_340);
});

test('a late ERROR or decline after the callback settled the refund changes nothing', function (string $late) {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = $late;
    ScriptedRefundDriver::$during = fn (RefundRequest $request) => app(RefundTransaction::class)->settle(Transaction::query()->findOrFail((int) $request->reference), 're_first');

    $result = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway);

    $refund = Transaction::query()->where('type', TransactionType::Refund)->sole();
    expect($refund)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_first')
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000)
        ->and($payment->invoice->fresh()->amount_paid)->toBe(6_000)
        ->and($result->status)->toBe(TransactionStatus::Succeeded);
})->with([ChargeResult::ERROR, ChargeResult::DECLINED]);

test('a refund left pending by a dead worker is settled by the callback, never answered 409 forever', function () {
    [$payment, $row] = crashedRefund(0);

    // The request may still be running: staff can neither check nor settle it yet, and no new refund goes out.
    expect(fn () => app(RefundTransaction::class)->resolve($row, false))->toThrow(BillingException::class, 'may still be running');
    expect(fn () => app(RefundTransaction::class)->handle($payment->fresh(), 1_000, RefundMode::Gateway))->toThrow(BillingException::class, 'being confirmed');

    sandboxWebhook(sandboxRefundCallback($payment, $row, 're_lost'))->assertOk()->assertJson(['result' => 'refund recorded']);
    expect($row->fresh())->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_lost');
});

test('after ten minutes a refund with no answer can be checked or settled by staff', function () {
    [$payment, $row] = crashedRefund(11);

    expect($row->canBeResolved())->toBeTrue();
    $released = app(RefundTransaction::class)->resolve($row, false);

    expect($released->status)->toBe(TransactionStatus::Failed)->and($payment->fresh()->refundable_remaining)->toBe(10_000);

    [$payment2, $row2] = crashedRefund(11);
    useLookupDriver();
    ScriptedLookupDriver::$lookup = new ChargeResult(ChargeResult::SUCCESS, 're_found');
    expect(app(RefundTransaction::class)->check($row2)->status)->toBe(TransactionStatus::Succeeded);
});

test('Check refund asks about the same reference, amount and send time, and settles the same row when it was made', function () {
    useLookupDriver();
    [$payment, $pending] = pendingRefund();
    ScriptedLookupDriver::$lookup = new ChargeResult(ChargeResult::SUCCESS, 're_found');
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.transactions.refund.check', $pending))->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect(ScriptedLookupDriver::$lookedUp)->toHaveCount(1)
        ->and(ScriptedLookupDriver::$lookedUp[0]->reference)->toBe((string) $pending->id)
        ->and(ScriptedLookupDriver::$lookedUp[0]->amount->amount)->toBe(4_000)
        ->and(ScriptedLookupDriver::$lookedUp[0]->sentAt)->not->toBeNull()
        ->and(ScriptedRefundDriver::$references)->toBe([(string) $pending->id])
        ->and(Transaction::query()->where('type', TransactionType::Refund)->sole())->id->toBe($pending->id)->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_found')
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000);
});

test('Check refund releases the amount only when the provider says there is no such refund', function (string $answer, string $status, int $remaining) {
    useLookupDriver();
    [$payment, $pending] = pendingRefund();
    ScriptedLookupDriver::$lookup = new ChargeResult($answer, declineReason: 'x');

    $row = app(RefundTransaction::class)->check($pending);

    expect($row->status->value)->toBe($status)->and($payment->fresh()->refundable_remaining)->toBe($remaining);
})->with([
    'no such refund' => [ChargeResult::DECLINED, 'failed', 10_000],
    'unreachable' => [ChargeResult::ERROR, 'pending', 6_000],
    'still processing' => [ChargeResult::PENDING, 'pending', 6_000],
]);

test('staff resolve a pending refund by hand with step-up, the refund permission, the provider reference and an audit entry', function () {
    [$payment, $pending] = pendingRefund();
    $staff = billingStaff();

    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed', 'provider_reference' => 'KH-77'])->assertSessionHasErrors();
    expect($pending->fresh()->status)->toBe(TransactionStatus::Pending);

    confirmPassword();
    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed'])->assertSessionHasErrors('provider_reference');
    expect($pending->fresh()->status)->toBe(TransactionStatus::Pending);

    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed', 'provider_reference' => ' KH-77 '])->assertSessionHasNoErrors();
    expect($pending->fresh())->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('KH-77')
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000);

    $entry = ActivityLog::query()->where('description', 'Refund confirmed at the provider by staff')->where('user_id', $staff->id)->sole();
    expect($entry->properties['provider_reference'])->toBe('KH-77');

    // The provider's own callback later finds the booked refund: nothing is booked twice.
    sandboxWebhook(sandboxRefundCallback($payment, $pending, 'KH-77'))->assertOk()->assertJson(['result' => 'duplicate']);
    expect(Transaction::query()->where('type', TransactionType::Refund)->count())->toBe(1);
});

test('a provider reference already booked on another refund is refused', function () {
    [$payment, $pending] = pendingRefund();
    app(RefundTransaction::class)->handle($payment->fresh(), 1_000, RefundMode::RecordOnly, gatewayTxnId: 'KH-1');

    expect(fn () => app(RefundTransaction::class)->resolve($pending, true, 'KH-1'))->toThrow(BillingException::class, 'already recorded');
});

test('staff resolution is for providers that cannot be asked, and a second resolution of a settled refund is refused', function () {
    useLookupDriver();
    [, $pending] = pendingRefund();
    expect(fn () => app(RefundTransaction::class)->resolve($pending, false))->toThrow(BillingException::class, 'Use Check refund');

    app(GatewayManager::class)->register(new ScriptedRefundDriver);
    expect(app(RefundTransaction::class)->resolve($pending->fresh(), true, 'KH-9')->status)->toBe(TransactionStatus::Succeeded);
    expect(fn () => app(RefundTransaction::class)->resolve($pending->fresh(), false))->toThrow(BillingException::class);
});

test('a stale "Release" after a "Confirmed" is refused and leaves no audit entry', function () {
    [, $pending] = pendingRefund();
    billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed', 'provider_reference' => 'KH-5'])->assertSessionHasNoErrors();

    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'not_refunded'])->assertNotFound();

    expect(ActivityLog::query()->where('description', 'Refund released by staff')->count())->toBe(0)
        ->and($pending->fresh()->status)->toBe(TransactionStatus::Succeeded);
});

test('"Release" frees the amount, and a callback that later proves the provider paid is booked once and flagged', function () {
    [$payment, $pending] = pendingRefund();
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'not_refunded'])->assertSessionHasNoErrors();
    expect($pending->fresh()->status)->toBe(TransactionStatus::Failed)->and($payment->fresh()->refundable_remaining)->toBe(10_000);

    $callback = sandboxRefundCallback($payment, $pending, 're_surprise');
    sandboxWebhook($callback)->assertOk();
    sandboxWebhook($callback)->assertOk()->assertJson(['result' => 'duplicate']);

    $booked = Transaction::query()->where('type', TransactionType::Refund)->where('status', TransactionStatus::Succeeded)->sole();
    expect($booked)->gateway_txn_id->toBe('re_surprise')->amount_out->toBe(4_000)
        ->and($booked->review_amount)->toBe(4_000)
        ->and($pending->fresh()->status)->toBe(TransactionStatus::Failed)
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000);
});

test('a late refund absorbed entirely by account credit is still flagged for review', function () {
    $invoice = issueInvoice(billingClient(), [1_000]);
    app(Invoicer::class)->cancel($invoice->id);
    $payment = payInvoice($invoice, 1_000, 'ch_credit', 'sandbox_card');
    ScriptedRefundDriver::$answer = ChargeResult::DECLINED;
    // The money went to credit, so there is nothing to refund through the books; take the refund row by hand.
    $failed = Transaction::query()->create([
        'client_id' => $payment->client_id, 'invoice_id' => $payment->invoice_id, 'gateway' => 'sandbox_card', 'type' => TransactionType::Refund, 'status' => TransactionStatus::Failed,
        'amount_out' => 1_000, 'currency' => $payment->currency, 'parent_transaction_id' => $payment->id, 'occurred_at' => now(),
    ]);

    sandboxWebhook(['type' => 'charge.refunded', 'txn_id' => 're_credit', 'parent_txn_id' => 'ch_credit', 'amount' => 1_000, 'currency' => 'USD', 'reference' => (string) $failed->id])->assertOk();

    $booked = Transaction::query()->where('gateway_txn_id', 're_credit')->sole();
    expect($booked->review_amount)->toBe(1_000)->and($booked->reviewed_at)->toBeNull();
});

test('the callback and Check refund cannot book one provider refund twice', function () {
    useLookupDriver();
    [$payment, $pending] = pendingRefund();
    sandboxWebhook(sandboxRefundCallback($payment, $pending, 're_one'))->assertOk();

    ScriptedLookupDriver::$lookup = new ChargeResult(ChargeResult::SUCCESS, 're_one');
    expect(fn () => app(RefundTransaction::class)->check($pending->fresh()))->toThrow(BillingException::class, 'not waiting');

    // The same provider refund id booked on another row settles nothing here: this row is released instead.
    $second = app(RefundTransaction::class)->handle($payment->fresh(), 1_000, RefundMode::Gateway);
    $row = app(RefundTransaction::class)->settle($second, 're_one');
    expect($row->id)->toBe($pending->id)
        ->and(Transaction::query()->where('type', TransactionType::Refund)->where('status', TransactionStatus::Succeeded)->count())->toBe(1)
        ->and($second->fresh()->status)->toBe(TransactionStatus::Failed)
        ->and($payment->fresh()->refundable_remaining)->toBe(6_000);
});

test('releasing after a dispute larger than what was left gives back only what the payment can still return', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;
    $pending = app(RefundTransaction::class)->handle($payment, 4_000, RefundMode::Gateway);

    // An 80.00 dispute arrives while the refund waits: 60.00 is left on the payment, 20.00 is not absorbed.
    sandboxWebhook(['type' => 'charge.dispute', 'txn_id' => 'dp_rr5', 'parent_txn_id' => $payment->gateway_txn_id, 'amount' => 8_000, 'currency' => 'USD'])->assertOk();
    expect(Transaction::query()->where('type', TransactionType::Chargeback)->sole())->amount_out->toBe(8_000);

    app(RefundTransaction::class)->release($pending);

    // 100.00 paid - 80.00 charged back = 20.00 can still be given back.
    expect($payment->fresh()->refundable_remaining)->toBe(2_000);
});

test('a refund the provider can be asked about but keeps answering "unknown" can be settled by hand after three days', function () {
    useLookupDriver();
    [$payment, $pending] = pendingRefund();
    ScriptedLookupDriver::$lookup = new ChargeResult(ChargeResult::ERROR, declineReason: 'unreachable');
    app(RefundTransaction::class)->check($pending);

    // Not yet: Check refund is the way.
    expect(fn () => app(RefundTransaction::class)->resolve($pending->fresh(), false))->toThrow(BillingException::class, 'Use Check refund');

    $pending->forceFill(['created_at' => now()->subHours(73)])->save();
    expect($pending->fresh()->stuckAtLookup())->toBeTrue();

    // A reference is still required to confirm, and a reference booked elsewhere is refused.
    expect(fn () => app(RefundTransaction::class)->resolve($pending->fresh(), true))->toThrow(BillingException::class, 'reference');

    $staff = billingStaff();
    confirmPassword();
    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed', 'provider_reference' => 're_seen_in_dashboard'])->assertSessionHasNoErrors();

    expect($pending->fresh())->status->toBe(TransactionStatus::Succeeded)->gateway_txn_id->toBe('re_seen_in_dashboard')
        ->and(ActivityLog::query()->where('description', 'Refund confirmed at the provider by staff')->where('user_id', $staff->id)->exists())->toBeTrue();
});

test('the hand settlement of a provider that can be asked needs three days and a last check that answered unknown', function (string $lastCheck, int $hours) {
    useLookupDriver();
    [, $pending] = pendingRefund();
    ScriptedLookupDriver::$lookup = new ChargeResult($lastCheck, declineReason: 'x');
    app(RefundTransaction::class)->check($pending);
    $pending->forceFill(['created_at' => now()->subHours($hours)])->save();

    expect(fn () => app(RefundTransaction::class)->resolve($pending->fresh(), false))->toThrow(BillingException::class);
})->with([
    'pending answer, old' => [ChargeResult::PENDING, 100],
    'unknown answer, young' => [ChargeResult::ERROR, 48],
]);

test('staff without the refund permission can neither check nor resolve a pending refund', function () {
    [, $pending] = pendingRefund();
    actingAsStaff(['billing.payments.manage']);
    confirmPassword();

    $this->post(route('admin.billing.transactions.refund.check', $pending))->assertForbidden();
    $this->post(route('admin.billing.transactions.refund.resolve', $pending), ['outcome' => 'confirmed', 'provider_reference' => 'x'])->assertForbidden();
    expect($pending->fresh()->status)->toBe(TransactionStatus::Pending);
});

test('staff see that the refund is being confirmed, not an error, and no retry is invited', function () {
    $payment = refundablePayment();
    ScriptedRefundDriver::$answer = ChargeResult::ERROR;
    billingStaff();
    confirmPassword();

    $this->post(route('admin.billing.transactions.refund', $payment), ['amount' => '40.00', 'mode' => 'gateway', 'reverse_effects' => false])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'info', 'message' => 'The refund is being confirmed with the provider.']);
});

test('pending refunds past ten minutes are on the to-do list and the waiting filter', function () {
    [, $fresh] = pendingRefund();
    [, $old] = crashedRefund(30);

    expect(Transaction::refundsAwaitingProvider()->pluck('id')->all())->toBe([$old->id])
        ->and(Transaction::refundsAwaitingProvider(0)->count())->toBe(2);

    billingStaff();
    $this->get(route('admin.billing.transactions.index', ['waiting' => 1]))->assertInertia(fn ($page) => $page->has('transactions.data', 2));
});
