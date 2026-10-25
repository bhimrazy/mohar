<?php

use App\Data\Billing\GatewayConfig;
use App\Data\Billing\RefundRequest;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Actions\ConfigureGateway;
use App\Modules\Billing\Actions\RefundTransaction;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Enums\RefundMode;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsCybersource\Drivers\CybersourceDriver;
use App\Modules\PaymentsCybersource\Support\CybersourceRest;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    cybersourceGateway();
});

test('initiate posts a form signed over every field it sends, with no card field, and keeps the uuid as provider reference', function () {
    [$invoice, $attempt, $checkout] = startCard(113_000);
    $fields = $checkout->inertiaProps('form.fields');
    $signed = explode(',', $fields['signed_field_names']);

    expect($checkout->inertiaProps('form.action'))->toBe('https://testsecureacceptance.cybersource.com/pay')
        ->and($fields)->toMatchArray([
            'access_key' => CS_ACCESS, 'profile_id' => CS_PROFILE, 'transaction_type' => 'sale', 'reference_number' => $attempt->reference,
            'amount' => '1130.00', 'currency' => 'NPR', 'unsigned_field_names' => '', 'transaction_uuid' => $attempt->provider_ref,
            'override_custom_receipt_page' => route('billing.gateways.return', CybersourceDriver::KEY),
        ])
        ->and($fields['override_custom_cancel_page'])->toContain('cancelled=1')->toContain('ref='.$attempt->reference)
        ->and($fields['signed_date_time'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and(array_diff(array_keys($fields), [...$signed, 'signature']))->toBe([])
        ->and(collect($fields)->keys()->filter(fn (string $name) => str_starts_with($name, 'card_')))->toBeEmpty()
        ->and($fields['signature'])->toBe(CybersourceDriver::signature($fields, $fields['signed_field_names'], CS_SECRET))
        ->and($attempt->provider_ref)->toMatch('/^[0-9a-f]{32}$/')
        ->and($checkout->headers->get('Content-Security-Policy'))->toContain('https://testsecureacceptance.cybersource.com');
    $this->get(route('client.invoices.show', $invoice->id))->assertOk()->assertDontSee(CS_SECRET)->assertDontSee(CS_REST_SECRET);
});

test('the form signature matches a fixed vector', function () {
    expect(CybersourceDriver::signature(['access_key' => 'a', 'amount' => '1130.00', 'currency' => 'NPR'], 'access_key,amount,currency', 'secret'))
        ->toBe(base64_encode(hash_hmac('sha256', 'access_key=a,amount=1130.00,currency=NPR', 'secret', true)))
        ->toBe('vhzWWw+EyTxkcjw5bKQ2QlOTDsieipVA2pEeTOj5QQw=');
});

test('NPR only by default; USD is offered and sent as USD when the bank agreement allows it', function () {
    [, $client] = actingAsClient();
    $usd = issueInvoice($client);
    $this->post(route('client.invoices.pay', $usd->id), ['gateway' => CybersourceDriver::KEY])->assertSessionHasErrors('billing');

    config(['services.cybersource.currencies' => 'NPR, usd']);
    $checkout = $this->post(route('client.invoices.pay', $usd->id), ['gateway' => CybersourceDriver::KEY])->assertRedirect()->headers->get('Location');

    expect($this->get((string) $checkout)->inertiaProps('form.fields.currency'))->toBe('USD');
});

test('the gateway cannot be activated without the REST key, and the form says why', function () {
    actingAsStaff(['billing.gateways.manage']);
    confirmPassword();
    $attributes = ['display_name' => 'Cards', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 0, 'convert_to_currency' => null];

    expect(fn () => app(ConfigureGateway::class)->handle('cybersource', $attributes, [...cybersourceSettings(), 'rest_key_id' => '', 'rest_secret' => '']))
        ->toThrow(BillingException::class, 'REST key ID is required to activate this gateway.');

    $this->get(route('admin.billing.gateways.edit', 'cybersource'))->assertInertia(fn (Assert $page) => $page
        ->where('fields', fn ($fields) => str_contains(collect($fields)->firstWhere('key', 'rest_secret')['help'], 'can\'t be activated without it')
            && collect($fields)->firstWhere('key', 'rest_key_id')['required'] === true)
        ->where('webhook_url', route('billing.webhooks', 'cybersource'))
        ->where('return_url', route('billing.gateways.return', 'cybersource'))
        ->where('gateway.settings.secret_key', '')
        ->where('gateway.settings.rest_secret', ''));
});

test('a signed ACCEPT settles only through the REST lookup; return, notice and poller together book one payment', function () {
    Event::fake([InvoicePaid::class]);
    [$invoice, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)]);

    postCardReturn(cardReturn($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));
    $this->post(route('billing.webhooks', 'cybersource'), cardReturn($attempt))->assertOk();
    $this->travel(10)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Succeeded)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->where('gateway', 'cybersource')->sole())->gateway_txn_id->toBe('7290000000000000000001')->amount_in->toBe(113_000)
        ->and(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/tss/v2/searches')))->not->toBeEmpty();
    Event::assertDispatchedTimes(InvoicePaid::class, 1);
});

test('the REST lookup decides: another amount, currency or a payment not found never settles', function (array $txns, AttemptStatus $status) {
    [$invoice, $attempt] = startCard();
    fakeCardRest(array_map(fn (Closure $txn) => $txn($attempt->reference), $txns));

    postCardReturn(cardReturn($attempt));
    // What the waiting customer's single lookup leaves open (nothing indexed yet, several payments) the poller decides.
    $this->travel(5)->minutes();
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe($status)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->where('gateway', 'cybersource')->exists())->toBeFalse();
})->with([
    'another amount' => [[fn (string $ref) => cardTxn($ref, amount: '11300.00')], AttemptStatus::Ambiguous],
    'another currency' => [[fn (string $ref) => cardTxn($ref, currency: 'USD')], AttemptStatus::Ambiguous],
    // A signed ACCEPT that REST still cannot find on the poller's lookup is kept as evidence and goes to staff review.
    'not found' => [[], AttemptStatus::Ambiguous],
    'two captured payments' => [[fn (string $ref) => cardTxn($ref), fn (string $ref) => cardTxn($ref, id: '7290000000000000000002')], AttemptStatus::Ambiguous],
]);

test('REST statuses map: review is processing, decline fails, an uncaptured authorisation goes to staff', function (string $status, bool $captured, AttemptStatus $expected) {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference, $status, captured: $captured)]);

    $this->artisan('billing:reconcile-payments')->assertSuccessful();
    $attempt->refresh();

    expect($attempt->status)->toBe($expected);

    if ($status === 'PENDING_REVIEW') {
        expect($attempt->expires_at)->toBeNull();
    }
})->with([
    'review: pending, no longer expiring' => ['PENDING_REVIEW', false, AttemptStatus::Pending],
    'declined' => ['DECLINED', false, AttemptStatus::Failed],
    'authorised, not captured' => ['AUTHORIZED', false, AttemptStatus::Ambiguous],
]);

test('a forged or incomplete return is no hint: no reference, no signed status', function (Closure $forge) {
    [, $attempt] = startCard();
    $request = Illuminate\Http\Request::create('/', 'POST', $forge(cardReturn(...), $attempt));
    $hint = app(CybersourceDriver::class)->parseReturn(new GatewayConfig('cybersource', 'Cards', cybersourceSettings(), sandbox: true), $request);

    expect($hint)->reference->toBeNull()->signatureValid->toBeFalse()->signedPaid->toBeFalse()->claimedStatus->toBeNull();
})->with([
    'wrong secret' => [fn (Closure $return, $attempt) => $return($attempt, secret: 'guessed')],
    'tampered amount' => [fn (Closure $return, $attempt) => [...$return($attempt), 'req_amount' => '1.00']],
    'unsigned extra field' => [fn (Closure $return, $attempt) => [...$return($attempt), 'injected' => '1']],
    'signed field missing' => [fn (Closure $return, $attempt) => array_diff_key($return($attempt), ['req_card_number' => 1])],
    'required field not signed' => [fn (Closure $return, $attempt) => (function () use ($attempt) {
        $fields = ['decision' => 'ACCEPT', 'req_reference_number' => $attempt->reference, 'signed_field_names' => 'decision,req_reference_number,signed_field_names'];

        return [...$fields, 'signature' => CybersourceDriver::signature($fields, $fields['signed_field_names'], CS_SECRET)];
    })()],
    'unknown signed field' => [fn (Closure $return, $attempt) => $return($attempt, extra: ['custom_flag' => 'paid'])],
    'another profile' => [fn (Closure $return, $attempt) => $return($attempt, extra: ['req_profile_id' => 'someone-else'])],
]);

test('a forged return to the return URL matches nothing and makes no lookup', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)]);

    postCardReturn(cardReturn($attempt, secret: 'guessed'))->assertRedirect(route('client.invoices.index'));

    expect(Http::recorded())->toBeEmpty()->and($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});

test('a signed CANCEL to the cancel page ends the attempt, so the invoice can be paid again', function () {
    [$invoice, $attempt] = startCard();
    fakeCardRest([]);

    postCardReturn(cardReturn($attempt, 'CANCEL', ['reason_code' => '']), cancel: true, attempt: $attempt);

    expect($attempt->fresh())->status->toBe(AttemptStatus::Failed)->review_reason->toContain('Cancelled by the customer')
        ->and(session('inertia.flash_data.toast.message'))->toContain('Payment cancelled');
    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => CybersourceDriver::KEY])->assertRedirect()->assertSessionHasNoErrors();
});

test('an unsigned cancel does not end the attempt while the customer may still be paying', function () {
    [, $attempt] = startCard();
    fakeCardRest([]);

    postCardReturn([], cancel: true, attempt: $attempt);

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);
});

test('REST requests carry an HTTP Signature over host, date, request target, digest and merchant id', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)]);
    $this->artisan('billing:reconcile-payments')->assertSuccessful();

    $search = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/tss/v2/searches'))->first()[0];
    preg_match_all('/(\w+)="([^"]*)"/', $search->header('Signature')[0], $found);
    $signature = array_combine($found[1], $found[2]);
    $lines = "host: apitest.cybersource.com\ndate: {$search->header('Date')[0]}\n(request-target): post /tss/v2/searches\ndigest: SHA-256=".base64_encode(hash('sha256', $search->body(), true))."\nv-c-merchant-id: nicasia_test";

    expect($signature)->toMatchArray(['keyid' => 'cs-rest-key-0001', 'algorithm' => 'HmacSHA256', 'headers' => 'host date (request-target) digest v-c-merchant-id'])
        ->and($signature['signature'])->toBe(base64_encode(hash_hmac('sha256', $lines, base64_decode(CS_REST_SECRET), true)))
        ->and($search->header('Digest')[0])->toBe('SHA-256='.base64_encode(hash('sha256', $search->body(), true)))
        ->and($search['query'])->toBe('clientReferenceInformation.code:'.$attempt->reference)
        ->and(CybersourceRest::signedHeaders('GET', 'https://h.test:8443/a?b=1', '', 'm', 'k', base64_encode('s'), 0)['Signature'])
        ->toContain('headers="host date (request-target) v-c-merchant-id"');
});

test('staff refund in part and then the rest through REST, with amounts as decimals and our refund reference', function () {
    [$invoice, $attempt] = startCard();
    $refunds = [];
    fakeCardRest([cardTxn($attempt->reference)], function (Request $request) use (&$refunds) {
        $refunds[] = $request->data();

        return Http::response(['id' => '72900000000000000000'.(90 + count($refunds)), 'status' => 'PENDING'], 201);
    });
    postCardReturn(cardReturn($attempt));
    $payment = Transaction::query()->where('gateway', 'cybersource')->sole();

    app(RefundTransaction::class)->handle($payment, 13_000, RefundMode::Gateway);
    app(RefundTransaction::class)->handle($payment->fresh(), 100_000, RefundMode::Gateway);

    expect($refunds)->toHaveCount(2)
        ->and($refunds[0]['orderInformation']['amountDetails'])->toBe(['totalAmount' => '130.00', 'currency' => 'NPR'])
        ->and($refunds[1]['orderInformation']['amountDetails'])->toBe(['totalAmount' => '1000.00', 'currency' => 'NPR'])
        ->and($refunds[0]['clientReferenceInformation']['code'])->not->toBe($refunds[1]['clientReferenceInformation']['code'])
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Refunded);
});

test('a refund Cybersource refuses stays refundable; a server error or timeout leaves it pending', function (?array $answer, ?string $pending) {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)], $answer ?? fn () => throw new ConnectionException('timed out'));
    postCardReturn(cardReturn($attempt));
    $payment = Transaction::query()->where('gateway', 'cybersource')->sole();

    if ($pending === null) {
        expect(fn () => app(RefundTransaction::class)->handle($payment, 113_000, RefundMode::Gateway))->toThrow(BillingException::class);
        expect($payment->fresh()->refundable_remaining)->toBe(113_000);

        return;
    }

    expect(app(RefundTransaction::class)->handle($payment, 113_000, RefundMode::Gateway)->status->value)->toBe($pending);
})->with([
    'refused' => [[400, ['status' => 'INVALID_REQUEST', 'reason' => 'INVALID_REQUEST', 'message' => 'The refund exceeds the amount still refundable.']], null],
    'server error' => [[502, ['message' => 'bad gateway']], 'pending'],
    'timeout' => [null, 'pending'],
]);

test('a refund check finds our refund by reference under the payment, and says no only after the search lag', function () {
    $driver = app(CybersourceDriver::class);
    $config = new GatewayConfig('cybersource', 'Cards', cybersourceSettings(), sandbox: true);
    $request = fn (?int $minutesAgo, int $amount = 13_000) => new RefundRequest('7290000000000000000001', Money::of($amount, 'NPR'), Money::of(113_000, 'NPR'), reference: '42', sentAt: $minutesAgo === null ? null : now()->subMinutes($minutesAgo)->toImmutable());
    fakeCardRest([cardTxn('42', 'PENDING', '130.00', id: '7290000000000000000099', rootId: '7290000000000000000001')]);

    expect($driver->lookupRefund($config, $request(1)))->status->toBe('success')->txnId->toBe('7290000000000000000099')
        ->and($driver->lookupRefund($config, $request(1, 50_000))->status)->toBe('error');

    fakeCardRest([]);
    expect($driver->lookupRefund($config, $request(5))->status)->toBe('error')
        ->and($driver->lookupRefund($config, $request(60))->status)->toBe('declined');
});

test('a refund under our reference for another amount or currency is an error that takes no refund id', function (string $amount, string $currency) {
    $config = new GatewayConfig('cybersource', 'Cards', cybersourceSettings(), sandbox: true);
    fakeCardRest([cardTxn('42', 'PENDING', $amount, $currency, id: '7290000000000000000099', rootId: '7290000000000000000001')]);

    $result = app(CybersourceDriver::class)->lookupRefund($config, new RefundRequest('7290000000000000000001', Money::of(13_000, 'NPR'), Money::of(113_000, 'NPR'), reference: '42'));

    expect($result)->status->toBe('error')->txnId->toBeNull()->raw->toMatchArray(['refund' => '7290000000000000000099']);
})->with([
    'amount' => ['200.00', 'NPR'],
    'currency' => ['130.00', 'USD'],
]);

test('the notice only triggers a lookup: a bad signature is refused, a stale one too', function () {
    [, $attempt] = startCard();
    fakeCardRest([]);

    $this->post(route('billing.webhooks', 'cybersource'), cardReturn($attempt, secret: 'guessed'))->assertForbidden();
    $this->post(route('billing.webhooks', 'cybersource'), cardReturn($attempt, extra: ['signed_date_time' => gmdate('Y-m-d\TH:i:s\Z', time() - 900)]))->assertStatus(400);
    $this->post(route('billing.webhooks', 'cybersource'), cardReturn($attempt))->assertOk();

    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending)
        ->and(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/tss/v2/searches')))->toHaveCount(1);
});

test('test connection reads a transaction that cannot exist and moves no money', function (int $status, bool $ok) {
    Http::fake([CS_REST.'/tss/v2/transactions/*' => Http::response(['response' => ['rmsg' => 'x']], $status)]);

    expect(app(CybersourceDriver::class)->testConnection(new GatewayConfig('cybersource', 'Cards', cybersourceSettings(), sandbox: true))->ok)->toBe($ok);
    Http::assertSentCount(1);
})->with(['accepted' => [404, true], 'refused' => [401, false]]);

test('secrets never reach the gateway log, stored payloads or staff pages', function () {
    [, $attempt] = startCard();
    fakeCardRest([cardTxn($attempt->reference)]);
    postCardReturn(cardReturn($attempt, extra: ['req_card_expiry_date' => '12-2030']));
    actingAsStaff();

    $everything = GatewayLog::query()->get()->toJson().$attempt->fresh()->toJson().Transaction::query()->get()->toJson()
        .$this->get(route('admin.billing.gateways.edit', 'cybersource'))->getContent();

    expect($everything)->not->toContain(CS_SECRET)->not->toContain(CS_REST_SECRET)->not->toContain('xxxxxxxxxxxx1111')->not->toContain('12-2030');
});
