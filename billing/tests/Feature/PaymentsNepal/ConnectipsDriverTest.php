<?php

/*
 * connectIPS driver (payments-gateways-expansion.md §4, §13 criteria 8-15) against faked NCHL answers. The same
 * driver runs against the connectIPS simulator in tools/simulators/tests/Connectips.
 */

use App\Contracts\Billing\GatewayDriver;
use App\Contracts\Branding\BrandProvider;
use App\Events\Billing\InvoicePaid;
use App\Modules\Billing\Enums\AttemptStatus;
use App\Modules\Billing\Enums\InvoiceStatus;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Models\GatewayLog;
use App\Modules\Billing\Models\PaymentAttempt;
use App\Modules\Billing\Models\Transaction;
use App\Modules\PaymentsNepal\Drivers\ConnectipsDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/connectips-helpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    nepalGateway(ConnectipsDriver::KEY, connectipsSettings());
});

test('the form posts paisa, NPR, the Nepal date, a 20-character TXNID and a token that verifies over the exact string', function () {
    // 19:00 UTC is already the next day in Nepal (UTC+5:45).
    $this->travelTo(CarbonImmutable::parse('2026-10-25 19:00:00', 'UTC'));
    [$invoice, $attempt, $fields] = startConnectips(113_000);
    $brand = (string) preg_replace('/[^A-Za-z0-9 ._\/-]+/', ' ', app(BrandProvider::class)->brand()->name);

    expect($fields)->toMatchArray([
        'MERCHANTID' => '550', 'APPID' => 'MER-550-APP-1', 'APPNAME' => 'Simulated Hosting', 'TXNDATE' => '26-10-2026',
        'TXNCRNCY' => 'NPR', 'TXNAMT' => '113000', 'REFERENCEID' => $invoice->displayNumber(), 'REMARKS' => 'Invoice '.$invoice->displayNumber(),
    ])
        ->and($fields['PARTICULARS'])->toBe(trim((string) preg_replace('/\s+/', ' ', $brand)))
        ->and($fields['TXNID'])->toMatch('/^[a-z2-7]{20}$/')->toBe($attempt->provider_ref);

    $message = "MERCHANTID=550,APPID=MER-550-APP-1,APPNAME=Simulated Hosting,TXNID={$fields['TXNID']},TXNDATE=26-10-2026,TXNCRNCY=NPR,TXNAMT=113000,"
        ."REFERENCEID={$fields['REFERENCEID']},REMARKS={$fields['REMARKS']},PARTICULARS={$fields['PARTICULARS']},TOKEN=TOKEN";
    expect(ConnectipsDriver::paymentMessage($fields))->toBe($message)
        ->and(openssl_verify($message, (string) base64_decode($fields['TOKEN'], true), connectipsKey()['certificate'], OPENSSL_ALGO_SHA256))->toBe(1)
        ->and($attempt)->amount->toBe(113_000)->currency->toBe('NPR')->status->toBe(AttemptStatus::Pending);

    $this->get(route('client.invoices.show', $invoice->id))->assertOk()->assertDontSee(CIPS_PASSWORD)->assertDontSee('PRIVATE KEY');
});

test('a return with only TXNID is settled by validatetxn and gettxndetail, with the merchant-borne fee and NCHL\'s id', function () {
    Event::fake([InvoicePaid::class]);
    [$invoice, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL')), CIPS_DETAIL => Http::response(cipsDetail($attempt))]);

    $this->get(cipsReturn($attempt))->assertRedirect(route('client.invoices.show', $invoice->id));
    $this->get(cipsReturn($attempt));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(Transaction::query()->sole())->amount_in->toBe(113_000)->fee->toBe(400)->gateway_txn_id->toBe('81234567890')
        ->and($attempt->fresh()->raw)->toMatchArray(['debit_bank_code' => '0401', 'credit_status' => '000', 'charge_liability' => 'MN']);
    Event::assertDispatchedTimes(InvoicePaid::class, 1);

    Http::assertSent(function (Request $request) use ($attempt): bool {
        $message = ConnectipsDriver::validationMessage('550', 'MER-550-APP-1', (string) $attempt->provider_ref, '113000');

        return $request->url() === CIPS_VALIDATE
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('MER-550-APP-1:'.CIPS_PASSWORD)
            && $request['referenceId'] === $attempt->provider_ref && $request['txnAmt'] === '113000' && $request['merchantId'] === '550'
            && openssl_verify($message, (string) base64_decode($request['token'], true), connectipsKey()['certificate'], OPENSSL_ALGO_SHA256) === 1;
    });

    // Neither the app password nor the signing key reaches the gateway log or the stored payloads.
    $stored = json_encode([GatewayLog::query()->get()->toArray(), $attempt->fresh()->raw, Transaction::query()->sole()->raw]);
    expect($stored)->not->toContain(CIPS_PASSWORD)->not->toContain('PRIVATE KEY')->not->toContain(base64_encode('MER-550-APP-1:'.CIPS_PASSWORD));
});

test('a TXNID we never issued finds nothing and shows the couldn\'t-match message', function (string $txnId) {
    startConnectips();

    $this->get(route('billing.gateways.return', [ConnectipsDriver::KEY, 'TXNID' => $txnId]))
        ->assertRedirect(route('client.invoices.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'We couldn\'t match this payment. If you were charged, contact us.');
    Http::assertNothingSent();
})->with(['unknown' => 'aaaaaaaaaaaaaaaaaaaa', 'malformed' => '../../etc', 'empty' => '']);

test('validatetxn answers map to attempt outcomes; anything about another transaction goes to staff review', function (string $status, string $description, array $overrides, AttemptStatus $expected) {
    [$invoice, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, $status, $description, $overrides)), CIPS_DETAIL => Http::response(cipsDetail($attempt))]);

    $this->get(cipsReturn($attempt));

    expect($attempt->fresh()->status)->toBe($expected)
        ->and($invoice->fresh()->status)->toBe($expected === AttemptStatus::Succeeded ? InvoiceStatus::Paid : InvoiceStatus::Unpaid);
})->with([
    'success' => ['SUCCESS', 'TRANSACTION SUCCESSFUL', [], AttemptStatus::Succeeded],
    'success, amount in rupees' => ['SUCCESS', 'TRANSACTION SUCCESSFUL', ['txnAmt' => '1130.00'], AttemptStatus::Succeeded],
    'failed' => ['FAILED', 'TRANSACTION FAILED', [], AttemptStatus::Failed],
    'not found' => ['ERROR', 'TRANSACTION NOT FOUND', [], AttemptStatus::Pending],
    'incomplete' => ['ERROR', 'TRANSACTION INCOMPLETE', [], AttemptStatus::Pending],
    'bad token (undocumented)' => ['ERROR', 'INVALID TOKEN', [], AttemptStatus::Ambiguous],
    'another amount' => ['SUCCESS', 'TRANSACTION SUCCESSFUL', ['txnAmt' => '113100'], AttemptStatus::Ambiguous],
    'neither paisa nor rupees' => ['SUCCESS', 'TRANSACTION SUCCESSFUL', ['txnAmt' => '11300000'], AttemptStatus::Ambiguous],
    'another transaction' => ['SUCCESS', 'TRANSACTION SUCCESSFUL', ['referenceId' => 'bbbbbbbbbbbbbbbbbbbb'], AttemptStatus::Ambiguous],
    'another merchant' => ['SUCCESS', 'TRANSACTION SUCCESSFUL', ['merchantId' => '9999'], AttemptStatus::Ambiguous],
    'another app' => ['SUCCESS', 'TRANSACTION SUCCESSFUL', ['appId' => 'OTHER-APP'], AttemptStatus::Ambiguous],
    'success without the echo' => ['SUCCESS', 'TRANSACTION SUCCESSFUL', ['referenceId' => null], AttemptStatus::Ambiguous],
]);

test('NOT FOUND expires after the TTL plus grace, INCOMPLETE once the attempt\'s own time is over', function (string $description, int $minutes) {
    [$invoice, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'ERROR', $description))]);

    $this->travel($minutes - 1)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    // The poller looks an attempt up at most every 5 minutes.
    $this->travel(6)->minutes();
    $this->artisan('billing:reconcile-payments', ['--only' => 'open'])->assertSuccessful();
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Expired)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0);
})->with([
    'not found: 30 + 120 minutes' => ['TRANSACTION NOT FOUND', 150],
    'incomplete: 30 minutes' => ['TRANSACTION INCOMPLETE', 30],
]);

test('gettxndetail decides the credit leg and the fee', function (array $detail, AttemptStatus $expected, int $fee) {
    [, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL')), CIPS_DETAIL => Http::response(cipsDetail($attempt, $detail))]);

    $this->get(cipsReturn($attempt));

    expect($attempt->fresh()->status)->toBe($expected);

    if ($expected === AttemptStatus::Succeeded) {
        expect(Transaction::query()->sole()->fee)->toBe($fee);
    } else {
        expect(Transaction::query()->count())->toBe(0)->and($attempt->fresh()->review_reason)->toContain('credit');
    }
})->with([
    'merchant pays the fee' => [[], AttemptStatus::Succeeded, 400],
    'customer pays the fee' => [['chargeLiability' => 'CG'], AttemptStatus::Succeeded, 0],
    'credit pending at NCHL (999)' => [['creditStatus' => '999'], AttemptStatus::Succeeded, 400],
    'credit deferred' => [['creditStatus' => 'DEFER', 'chargeAmt' => '8.00'], AttemptStatus::Succeeded, 800],
    'credit failed' => [['creditStatus' => 'F01'], AttemptStatus::Ambiguous, 0],
    'credit status missing' => [['creditStatus' => null], AttemptStatus::Ambiguous, 0],
]);

test('nothing settles without gettxndetail: an outage waits for the poller, a refusal goes to staff review', function (int $httpStatus, AttemptStatus $expected, string $reason) {
    [$invoice, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'SUCCESS', 'TRANSACTION SUCCESSFUL')), CIPS_DETAIL => Http::response(['error' => 'x'], $httpStatus)]);

    $this->get(cipsReturn($attempt));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Unpaid)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($attempt->fresh())->status->toBe($expected)
        ->and($attempt->fresh()->raw['error'])->toContain($reason);
})->with([
    'outage (502)' => [502, AttemptStatus::Pending, 'gettxndetail answered HTTP 502'],
    'refused (400)' => [400, AttemptStatus::Ambiguous, 'credit leg unconfirmed'],
]);

test('the form is kept on the attempt, TXNDATE included and the token redacted, across lookups', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-25 19:00:00', 'UTC'));
    [, $attempt, $fields] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::response(cipsValidate($attempt, 'ERROR', 'TRANSACTION NOT FOUND'))]);

    $this->get(cipsReturn($attempt));

    $form = $attempt->fresh()->raw['provider_form'];
    expect($form)->toMatchArray(['TXNDATE' => '26-10-2026', 'TXNID' => $attempt->provider_ref, 'TXNAMT' => '113000'])
        ->and(json_encode($form))->not->toContain($fields['TOKEN'])
        ->and($attempt->fresh()->raw['status'])->toBe('ERROR');
});

test('the gateway form refuses connectIPS values NCHL would refuse, at their fields', function () {
    actingAsStaff();
    confirmPassword();

    $this->put(route('admin.billing.gateways.update', ConnectipsDriver::KEY), [
        'display_name' => 'connectIPS', 'is_active' => false, 'show_on_order_form' => true,
        'settings' => ['merchant_id' => 'M-550', 'app_id' => 'MER-550-APP-1-TOO-LONG', 'app_name' => 'Hosting, Pvt'],
    ])->assertSessionHasErrors(['settings.merchant_id', 'settings.app_id', 'settings.app_name']);

    expect(Gateway::query()->where('key', ConnectipsDriver::KEY)->sole()->setting('merchant_id'))->toBe('550');
});

test('a 401 goes to staff review with a clear reason; an outage leaves the attempt to the poller', function () {
    [, $attempt] = startConnectips();
    Http::fake([CIPS_VALIDATE => Http::sequence()->push('', 503)->push(['error' => 'Unauthorized'], 401)]);

    $this->get(cipsReturn($attempt));
    expect($attempt->fresh()->status)->toBe(AttemptStatus::Pending);

    $this->travel(2)->minutes();
    $this->get(cipsReturn($attempt));
    expect($attempt->fresh())->status->toBe(AttemptStatus::Ambiguous)->review_reason->toContain('HTTP 401');
});

test('an expired signing certificate refuses to start a payment with a plain message', function () {
    Gateway::query()->where('key', ConnectipsDriver::KEY)->sole()->update(['config' => connectipsSettings(CarbonImmutable::now()->subDay())]);
    [, $invoice] = nprInvoice([113_000]);

    $this->post(route('client.invoices.pay', $invoice->id), ['gateway' => ConnectipsDriver::KEY])
        ->assertSessionHasErrors('billing');
    expect(PaymentAttempt::query()->sole())->status->toBe(AttemptStatus::Failed)
        ->and(GatewayLog::query()->where('result', 'hosted payment start failed')->sole()->payload['error'])->toContain('signing certificate expired');
});

test('connectIPS has no refund API: staff are told to refund by bank transfer and record it', function () {
    $driver = app(ConnectipsDriver::class);
    expect($driver->supports(GatewayDriver::REFUND))->toBeFalse()->and($driver->supports(GatewayDriver::PARTIAL_REFUND))->toBeFalse();

    actingAsStaff();
    $this->get(route('admin.billing.gateways.edit', ConnectipsDriver::KEY))->assertInertia(fn (Assert $page) => $page
        ->where('gateway.refund_note', $driver->manualRefundNote())
        ->where('return_url', route('billing.gateways.return', ConnectipsDriver::KEY))
        ->where('gateway.secrets_set.app_password', true)
        ->etc());
    expect($driver->manualRefundNote())->toContain('bank transfer')->toContain('record the refund');
});

test('Test connection reports accepted credentials, a 401 and an answer it can\'t read, and moves no money', function (int $status, array $body, bool $ok, string $message) {
    Http::fake([CIPS_VALIDATE => Http::response($body, $status)]);

    $check = app(ConnectipsDriver::class)->testConnection(connectipsConfig());

    expect($check->ok)->toBe($ok)->and($check->message)->toContain($message);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === CIPS_VALIDATE && $request['txnAmt'] === '100');
})->with([
    'accepted' => [200, ['status' => 'ERROR', 'statusDesc' => 'TRANSACTION NOT FOUND'], true, 'accepted the app ID, password and signing key'],
    'wrong app ID or password' => [401, ['error' => 'Unauthorized'], false, 'rejected the app ID or app password'],
    'bad token (undocumented)' => [200, ['status' => 'ERROR', 'statusDesc' => 'INVALID TOKEN'], false, 'could not be confirmed (sandbox mode): INVALID TOKEN'],
]);

test('connectIPS is offered for NPR only and keeps NCHL\'s per-payment limits', function () {
    $driver = app(ConnectipsDriver::class);

    expect($driver->currencies())->toBe(['NPR'])
        ->and($driver->limits(connectipsConfig()))->min->toBe(100)->max->toBe(200_000_000)
        ->and($driver->attemptTtl(connectipsConfig())->totalMinutes)->toBe(30.0);
});
