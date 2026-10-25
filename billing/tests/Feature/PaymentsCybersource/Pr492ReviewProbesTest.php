<?php

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\PaymentAttemptRef;
use App\Data\Billing\RefundRequest;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsCybersource\Drivers\CybersourceDriver;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    cybersourceGateway();
});

function probeConfig(): GatewayConfig
{
    return new GatewayConfig('cybersource', 'Cards', cybersourceSettings(), sandbox: true);
}

// P1 raw-body path: the feature tests post arrays, so getContent() is '' and posted() falls back to the
// middleware-modified bag. With a real urlencoded body a value with a trailing space must still verify.
test('P1a raw urlencoded body with a trailing-space value verifies', function () {
    [, $attempt] = startCard();
    $fields = cardReturn($attempt, extra: ['message' => 'Request was processed successfully. ']);
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));

    expect(app(CybersourceDriver::class)->parseReturn(probeConfig(), $request))->signatureValid->toBeTrue()->signedPaid->toBeTrue();
});

test('P1b the same return through the HTTP route with a raw body settles (TrimStrings would break it)', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)]);
    $fields = cardReturn($attempt, extra: ['message' => 'Request was processed successfully. ', 'req_bill_to_address_line2' => '']);

    $this->call('POST', route('billing.gateways.return', CybersourceDriver::KEY), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded);
});

test('P1c without a raw body the fallback is trimmed and the same return is no hint (why the raw path matters)', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)]);
    postCardReturn(cardReturn($attempt, extra: ['message' => 'Request was processed successfully. ']));

    expect(Http::recorded())->toBeEmpty();
});

test('P1d duplicate keys: last wins, signature still checked over what is used', function () {
    [, $attempt] = startCard();
    $fields = cardReturn($attempt, 'DECLINE');
    $body = http_build_query($fields).'&decision=ACCEPT';
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body);

    expect(app(CybersourceDriver::class)->parseReturn(probeConfig(), $request))->signatureValid->toBeFalse()->signedPaid->toBeFalse();
});

// P2 a REST detail without a currency or amount: Billing's guard only compares a non-null currency.
test('P2 a captured payment with no currency or no amount in the REST answer goes to staff review', function (string $missing) {
    [, $attempt] = startCard();
    $txn = cardTxn($attempt->reference);
    unset($txn['orderInformation']['amountDetails'][$missing]);
    fakeCardRest([$txn]);

    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('without an amount or currency');
})->with(['currency', 'totalAmount']);

// P3 lookupRefund does not compare the refund row's own clientReferenceInformation.code with ours.
test('P3 a refund check never reports another refund (same payment, same amount) as ours', function () {
    $other = cardTxn('41', 'PENDING', '130.00', id: '7290000000000000000098', rootId: '7290000000000000000001');
    Http::fake([CS_REST.'/tss/v2/searches' => Http::response(['_embedded' => ['transactionSummaries' => [$other]]])]);
    $request = new RefundRequest('7290000000000000000001', Money::of(13_000, 'NPR'), Money::of(113_000, 'NPR'), reference: '42', sentAt: now()->subMinutes(5)->toImmutable());

    expect(app(CybersourceDriver::class)->lookupRefund(probeConfig(), $request))->status->toBe('error')->txnId->toBeNull();
});

// P4 a 201 with an id but an unlisted status: the refund stays pending, the amount stays held.
test('P4 a created refund with an unexpected status stays pending until the refund check decides', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)], [201, ['id' => '7290000000000000000091', 'status' => 'ACCEPTED']]);
    postCardReturn(cardReturn($attempt));
    $payment = Transaction::query()->where('gateway', 'cybersource')->sole();

    $refund = app(RefundTransaction::class)->handle($payment, 113_000, RefundMode::Gateway);

    expect($refund->status->value)->toBe('pending')->and($payment->fresh()->refundable_remaining)->toBe(0);
});

// P5 partial authorisation, or a payment voided or reversed, goes to staff review.
test('P5 a sale authorised for less than requested, voided or reversed is never settled', function (string $path, string $value) {
    [, $attempt] = startCard();
    $txn = cardTxn($attempt->reference);
    data_set($txn, $path, $value);
    fakeCardRest([$txn]);

    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Ambiguous);
})->with([
    'partial authorisation' => ['orderInformation.amountDetails.authorizedAmount', '500.00'],
    'voided' => ['applicationInformation.status', 'VOIDED'],
    'reversed' => ['applicationInformation.status', 'REVERSED'],
]);

// P6 the signed-cancel map outlives the request inside one process.
test('P6 a signed CANCEL is remembered for later lookups in the same process', function () {
    [, $attempt] = startCard();
    fakeCardRest([]);
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query(cardReturn($attempt, 'CANCEL')));
    app(CybersourceDriver::class)->parseReturn(probeConfig(), $request);

    expect(app(CybersourceDriver::class)->lookup(probeConfig(), new PaymentAttemptRef($attempt->reference, Money::of(113_000, 'NPR')))->sessionEnded)->toBeTrue();
});

// P7 a REST row for another reference is never used.
test('P7 a search row for another reference is ignored', function () {
    [, $attempt] = startCard();
    Http::fake([
        CS_REST.'/tss/v2/searches' => Http::response(['_embedded' => ['transactionSummaries' => [cardTxn('someotherreference00000000')]]]),
        CS_REST.'/tss/v2/transactions/*' => Http::response(cardTxn('someotherreference00000000')),
    ]);

    expect(app(CybersourceDriver::class)->lookup(probeConfig(), new PaymentAttemptRef($attempt->reference, Money::of(113_000, 'NPR')))->status)->toBe('not_found');
});

test('P8 the return controller never logs the card expiry', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)]);
    $fields = cardReturn($attempt, extra: ['req_card_expiry_date' => '12-2030']);
    $this->call('POST', route('billing.gateways.return', CybersourceDriver::KEY), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    $this->call('POST', route('billing.webhooks', CybersourceDriver::KEY), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));

    $all = GatewayLog::query()->get()->toJson().$attempt->fresh()->toJson().Transaction::query()->get()->toJson();
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)->and($all)->not->toContain('12-2030')->not->toContain('xxxxxxxxxxxx1111');
});
