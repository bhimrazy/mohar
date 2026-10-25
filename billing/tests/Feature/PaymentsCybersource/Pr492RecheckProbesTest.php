<?php

use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsCybersource\Drivers\CybersourceDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    cybersourceGateway();
});

// R1 not indexed on the return: the attempt stays pending (evidence kept), the poller settles it once REST has it.
test('R1 a signed ACCEPT not yet indexed waits, keeps its evidence, and the poller books it once', function () {
    [$invoice, $attempt] = startCard();
    fakeCardRest([]);
    postCardReturn(cardReturn($attempt));

    $after = $attempt->fresh();
    expect($after->status)->toBe(AttemptStatus::Pending)->and($after->raw['signed_return'] ?? null)->not->toBeNull()
        ->and($after->expires_at)->not->toBeNull();

    fakeCardRest([cardTxn($attempt->reference)]);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('gateway', 'cybersource')->count())->toBe(1);
});

// R2 return (not indexed) then the signed notice (still not indexed): the notice's non-interactive lookup says
// not_found with the kept evidence, so the attempt goes to review at once; the poller still settles it later.
test('R2 return then notice before indexing: review, then settled by the poller', function () {
    [$invoice, $attempt] = startCard();
    fakeCardRest([]);
    postCardReturn(cardReturn($attempt));
    $this->post(route('billing.webhooks', 'cybersource'), cardReturn($attempt))->assertOk();
    $mid = $attempt->fresh()->status;

    fakeCardRest([cardTxn($attempt->reference)]);
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($mid)->toBe(AttemptStatus::Ambiguous)
        ->and($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

// R3 an unsigned return (no evidence) that REST never finds: pending until TTL + grace, then expired, invoice payable again.
test('R3 an unsigned return never found does not leave the invoice blocked', function () {
    [$invoice, $attempt] = startCard();
    fakeCardRest([]);
    $this->post(route('billing.gateways.return', [CybersourceDriver::KEY, 'ref' => $attempt->reference]), []);
    expect($attempt->fresh()->lookup_count)->toBe(1);

    $this->travel(4)->hours();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired);
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => CybersourceDriver::KEY])->assertRedirect()->assertSessionHasNoErrors();
});

// R4 the allowlist warning is written only for a valid signature: an anonymous sender cannot write names into the log.
test('R4 unknown signed names are logged only when Cybersource signed them', function () {
    [, $attempt] = startCard();
    Log::spy();

    postCardReturn(cardReturn($attempt, extra: ['attacker_chosen_name' => 'x'], secret: 'not-the-secret'));
    Log::shouldNotHaveReceived('warning');

    postCardReturn(cardReturn($attempt, extra: ['payment_token' => 'x']));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['fields'] === ['payment_token'])->once();
});

// R5 a 2xx refund answer without an id stays pending: the amount stays held until the refund check decides.
test('R5 a 201 refund answer without an id keeps the refund pending', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)], [201, ['status' => 'PENDING']]);
    postCardReturn(cardReturn($attempt));
    $payment = Transaction::query()->where('gateway', 'cybersource')->sole();

    $refund = app(RefundTransaction::class)->handle($payment, 113_000, RefundMode::Gateway);

    expect($refund->status->value)->toBe('pending')->and($payment->fresh()->refundable_remaining)->toBe(0);
});

// R6 a paid row plus a voided row under one reference never settles.
test('R6 paid and voided under one reference goes to review', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference), cardTxn($attempt->reference, 'VOIDED', id: '7290000000000000000002')]);

    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
});

// R7 interactive with two rows: no detail read, pending for the poller.
test('R7 the waiting customer makes at most one search and one read', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)]);
    postCardReturn(cardReturn($attempt));

    expect(Http::recorded())->toHaveCount(2);
});
