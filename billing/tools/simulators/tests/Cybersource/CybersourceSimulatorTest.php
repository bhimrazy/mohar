<?php

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Cybersource\CybersourceApi;
use Mohar\Simulators\Providers\Cybersource\CybersourceSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The cybersource simulator over its TLS port, as Secure Acceptance and the REST reference describe it: the signed form
 * and its refusals, the five hosted outcomes with their signed return and notice, forged returns, the HTTP Signature on
 * REST search, lookup and refunds, and the lookups the driver must distrust (another amount or currency).
 */
beforeEach(function () {
    $this->scenario = Processes::start(CybersourceSimulator::class);
    $this->actions = $this->scenario->simulator()->actions();
    $this->actions['receipt_url'](['url' => 'https://shop.example.test/billing/return/cybersource'], $this->scenario);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function csBrowser(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false])->timeout(5);
}

/**
 * The signed fields a merchant's checkout form carries, with $override replacing any of them before signing.
 *
 * @param  array<string, string>  $override
 * @return array<string, string>
 */
function csForm(string $uuid, array $override = [], string $secret = CybersourceSimulator::SECRET_KEY): array
{
    $fields = [
        'access_key' => CybersourceSimulator::ACCESS_KEY, 'profile_id' => CybersourceSimulator::PROFILE_ID, 'transaction_uuid' => $uuid, 'unsigned_field_names' => '',
        'signed_date_time' => gmdate('Y-m-d\TH:i:s\Z'), 'locale' => 'en', 'transaction_type' => 'sale', 'reference_number' => 'ATT-'.$uuid, 'amount' => '1130.55', 'currency' => 'NPR',
        ...$override,
    ];
    $fields['signed_field_names'] = implode(',', [...array_keys($fields), 'signed_field_names']);
    $fields['signature'] = CybersourceApi::signature($fields, $fields['signed_field_names'], $secret);

    return $fields;
}

function csPost(array $form): Response
{
    return csBrowser()->asForm()->post(test()->scenario->apiUrl().'/pay', $form);
}

/**
 * Opens a session and returns its form.
 *
 * @param  array<string, string>  $override
 * @return array<string, string>
 */
function csOpen(string $uuid = 'u-1', array $override = []): array
{
    $form = csForm($uuid, $override);
    expect(csPost($form)->status())->toBe(303);

    return $form;
}

/**
 * @param  array<string, mixed>  $options
 * @return array{url: string, fields: array<string, string>}
 */
function csChoose(string $choice, string $uuid = 'u-1', array $options = []): array
{
    return test()->actions[$choice](['transaction_uuid' => $uuid, ...$options], test()->scenario)['post'];
}

/** The REST call with the HTTP Signature a merchant's client computes. */
function csRest(string $method, string $path, ?array $body = null, array $tamper = []): Response
{
    $json = $body === null ? '' : (string) json_encode($body);
    $host = parse_url(test()->scenario->apiUrl(), PHP_URL_HOST).':'.parse_url(test()->scenario->apiUrl(), PHP_URL_PORT);
    $date = $tamper['date'] ?? gmdate('D, d M Y H:i:s').' GMT';
    $headers = ['host' => $host, 'date' => $date, '(request-target)' => strtolower($method).' '.$path, 'v-c-merchant-id' => CybersourceSimulator::MERCHANT_ID];

    if ($body !== null) {
        $headers = ['host' => $host, 'date' => $date, '(request-target)' => $headers['(request-target)'], 'digest' => 'SHA-256='.base64_encode(hash('sha256', $json, true)), 'v-c-merchant-id' => CybersourceSimulator::MERCHANT_ID];
    }

    $signing = implode("\n", array_map(fn (string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers));
    $signature = base64_encode(hash_hmac('sha256', $signing, (string) base64_decode($tamper['secret'] ?? CybersourceSimulator::REST_SECRET), true));
    $request = csBrowser()->withHeaders([
        'v-c-merchant-id' => CybersourceSimulator::MERCHANT_ID, 'Date' => $date, 'Host' => $host,
        'Signature' => 'keyid="'.($tamper['keyid'] ?? CybersourceSimulator::REST_KEY_ID).'", algorithm="HmacSHA256", headers="'.implode(' ', array_keys($headers)).'", signature="'.$signature.'"',
        ...($body === null ? [] : ['Digest' => $headers['digest']]),
    ]);
    $url = test()->scenario->apiUrl().$path;

    return $method === 'GET' ? $request->get($url) : $request->withBody($tamper['body'] ?? $json, 'application/json')->post($url);
}

/** @return array<string, mixed> the one transaction summary found for the attempt reference, or [] */
function csFind(string $reference): array
{
    return csRest('POST', '/tss/v2/searches', ['query' => 'clientReferenceInformation.code:'.$reference, 'sort' => 'id:desc'])->json('_embedded.transactionSummaries.0') ?? [];
}

test('the signed form opens a session, the page offers the five outcomes, and Accept returns a signed result that the REST lookup confirms', function () {
    $form = csOpen();
    $booked = csPost(csForm('u-page'));
    $page = csBrowser()->get($booked->header('Location'));

    expect($page->body())->toContain('NPR 1,130.55')->toContain('Accept')->toContain('Decline')->toContain('Review')->toContain('Error')->toContain('Cancel')
        ->and($page->body())->not->toContain('card_number');

    $post = csChoose('accept');
    $fields = $post['fields'];
    $signed = explode(',', $fields['signed_field_names']);

    expect($post['url'])->toBe('https://shop.example.test/billing/return/cybersource')
        ->and(CybersourceApi::signature($fields, $fields['signed_field_names'], CybersourceSimulator::SECRET_KEY))->toBe($fields['signature'])
        ->and(array_diff(array_keys($fields), [...$signed, 'signature']))->toBe([])
        ->and($fields)->toMatchArray(['decision' => 'ACCEPT', 'reason_code' => '100', 'req_reference_number' => $form['reference_number'], 'req_amount' => '1130.55', 'req_currency' => 'NPR', 'req_transaction_uuid' => 'u-1'])
        ->and($fields['req_card_number'])->toBe('xxxxxxxxxxxx1111');

    $found = csFind($form['reference_number']);
    $detail = csRest('GET', '/tss/v2/transactions/'.$fields['transaction_id'])->json();

    expect($found)->toMatchArray(['id' => $fields['transaction_id'], 'applicationInformation' => ['status' => 'TRANSMITTED', 'reasonCode' => '100']])
        ->and($found['orderInformation']['amountDetails'])->toBe(['totalAmount' => '1130.55', 'currency' => 'NPR'])
        ->and($detail['applicationInformation']['applications'][1]['name'])->toBe('ics_bill')
        ->and(csRest('GET', '/tss/v2/transactions/0000000000000000000000')->status())->toBe(404);
});

test('the hosted page button posts the signed return to the customer response URL', function () {
    $booked = csPost(csForm('u-btn'));
    $return = csBrowser()->post($booked->header('Location').'/decline');

    expect($return->status())->toBe(200)
        ->and($return->body())->toContain('action="https://shop.example.test/billing/return/cybersource"')->toContain('name="decision" value="DECLINE"')->toContain('name="signature"');
});

test('a form with a bad signature, an unknown key, an unsigned required field or a reused uuid is refused before a session opens', function () {
    $good = csForm('u-ok');
    $unsigned = $good;
    $unsigned['signed_field_names'] = implode(',', array_diff(explode(',', $good['signed_field_names']), ['amount']));
    $unsigned['signature'] = CybersourceApi::signature($unsigned, $unsigned['signed_field_names'], CybersourceSimulator::SECRET_KEY);

    $withoutUnsignedList = csForm('u-nolist');
    unset($withoutUnsignedList['unsigned_field_names']);
    $withoutUnsignedList['signed_field_names'] = implode(',', array_diff(explode(',', $withoutUnsignedList['signed_field_names']), ['unsigned_field_names']));
    $withoutUnsignedList['signature'] = CybersourceApi::signature($withoutUnsignedList, $withoutUnsignedList['signed_field_names'], CybersourceSimulator::SECRET_KEY);

    expect(csPost($withoutUnsignedList)->status())->toBe(303)
        ->and(csPost([...csForm('u-extra'), 'bill_to_email' => 'x@example.test'])->status())->toBe(403)
        ->and(csPost([...$good, 'amount' => '1.00'])->status())->toBe(403)
        ->and(csPost(csForm('u-wrong', secret: 'not-the-secret'))->status())->toBe(403)
        ->and(csPost(csForm('u-key', ['access_key' => 'someoneelseskey']))->status())->toBe(403)
        ->and(csPost($unsigned)->status())->toBe(403)
        ->and(csPost(csForm('u-profile', ['profile_id' => 'another-profile']))->status())->toBe(400)
        ->and(csPost(csForm('u-cur', ['currency' => 'INR']))->status())->toBe(400)
        ->and(csPost(csForm('u-amt', ['amount' => '10.555']))->status())->toBe(400)
        ->and(csPost(csForm('u-type', ['transaction_type' => 'create_payment_token']))->status())->toBe(400);

    csOpen('u-twice');

    expect(csPost(csForm('u-twice'))->status())->toBe(400)
        ->and($this->scenario->state()->all('sessions'))->toHaveCount(2);
});

test('card fields on the form are refused, signed or not, and never stored, returned or sent in a notice', function () {
    $this->actions['notice_url'](['url' => 'https://shop.example.test/billing/webhooks/cybersource'], $this->scenario);
    // Marker values, not digits: random ids in the stored state can contain any short run of digits.
    $signed = csForm('u-card-signed', ['card_number' => '4111111111111111', 'card_cvn' => 'cvn-marker-s3']);

    expect(csPost([...csForm('u-card'), 'card_number' => '4242424242424242', 'card_cvn' => 'cvn-marker-q7'])->status())->toBe(400)
        ->and(csPost($signed)->status())->toBe(400)
        ->and($this->scenario->state()->all('sessions'))->toBe([]);

    csOpen('u-clean');
    $fields = csChoose('accept', 'u-clean')['fields'];
    $everything = json_encode([$this->scenario->state()->all('sessions'), $fields, $this->scenario->outbox()->pending()]);

    expect($everything)->not->toContain('4242424242424242')->not->toContain('4111111111111111')->not->toContain('cvn-marker-q7')->not->toContain('cvn-marker-s3')->not->toContain('req_card_cvn')
        ->and($fields['req_card_number'])->toBe('xxxxxxxxxxxx1111');
});

test('decline, review, error and cancel return their decisions; only a decline or review has a transaction to look up', function () {
    foreach (['decline' => ['DECLINE', '203', 'DECLINED'], 'review' => ['REVIEW', '480', 'PENDING_REVIEW'], 'error' => ['ERROR', '150', null], 'cancel' => ['CANCEL', null, null]] as $choice => [$decision, $reason, $status]) {
        $form = csOpen("u-{$choice}");
        $fields = csChoose($choice, "u-{$choice}")['fields'];

        expect($fields['decision'])->toBe($decision)
            ->and($fields['reason_code'] ?? null)->toBe($reason)
            ->and(isset($fields['transaction_id']))->toBe($status !== null)
            ->and(CybersourceApi::signature($fields, $fields['signed_field_names'], CybersourceSimulator::SECRET_KEY))->toBe($fields['signature'])
            ->and(csFind($form['reference_number'])['applicationInformation']['status'] ?? null)->toBe($status);
    }
});

test('a cancel posts to the form\'s cancel page when it names one; other outcomes still go to the receipt page', function () {
    $pages = ['override_custom_receipt_page' => 'https://shop.example.test/return', 'override_custom_cancel_page' => 'https://shop.example.test/return?cancelled=1'];
    csOpen('u-c1', $pages);
    csOpen('u-c2', $pages);

    expect(csChoose('cancel', 'u-c1')['url'])->toBe($pages['override_custom_cancel_page'])
        ->and(csChoose('decline', 'u-c2')['url'])->toBe($pages['override_custom_receipt_page']);
});

test('a review becomes a capture or a decline when Decision Manager decides, and each verdict sends a signed notice', function () {
    $this->actions['notice_url'](['url' => 'https://shop.example.test/billing/webhooks/cybersource'], $this->scenario);
    $form = csOpen('u-r1');
    csChoose('review', 'u-r1');
    $this->actions['resolve_review'](['transaction_uuid' => 'u-r1', 'outcome' => 'accept'], $this->scenario);
    csOpen('u-r2');
    csChoose('review', 'u-r2');
    $this->actions['resolve_review'](['transaction_uuid' => 'u-r2', 'outcome' => 'reject'], $this->scenario);

    $notices = array_map(function (array $delivery): array {
        parse_str($delivery['body'], $fields);

        return $fields;
    }, $this->scenario->outbox()->pending());

    expect(csFind($form['reference_number'])['applicationInformation'])->toBe(['status' => 'TRANSMITTED', 'reasonCode' => '100'])
        ->and(csFind('ATT-u-r2')['applicationInformation'])->toBe(['status' => 'REJECTED', 'reasonCode' => '481'])
        ->and(array_column($notices, 'decision'))->toBe(['REVIEW', 'ACCEPT', 'REVIEW', 'DECLINE'])
        ->and(CybersourceApi::signature($notices[1], $notices[1]['signed_field_names'], CybersourceSimulator::SECRET_KEY))->toBe($notices[1]['signature'])
        ->and(fn () => $this->actions['resolve_review'](['transaction_uuid' => 'u-r1', 'outcome' => 'reject'], $this->scenario))->toThrow(InvalidArgumentException::class);
});

test('a duplicate return is the same signed result and books one transaction; another choice after a decision is refused', function () {
    $form = csOpen();
    $first = csChoose('accept');
    $again = csChoose('accept');

    expect($again)->toBe($first)
        ->and(csRest('POST', '/tss/v2/searches', ['query' => 'clientReferenceInformation.code:'.$form['reference_number']])->json('count'))->toBe(1)
        ->and(fn () => csChoose('decline'))->toThrow(InvalidArgumentException::class);
});

test('forged returns are signed wrongly, tampered, unsigned or with a field the signature does not cover', function () {
    foreach (['wrong_secret', 'tampered', 'unsigned', 'extra_field', 'missing_field'] as $variant) {
        $uuid = 'u-'.str_replace('_', '-', $variant);
        csOpen($uuid);
        $fields = csChoose('accept', $uuid, ['variant' => $variant])['fields'];
        $signed = explode(',', $fields['signed_field_names']);
        $valid = isset($fields['signature']) && hash_equals(CybersourceApi::signature($fields, $fields['signed_field_names'], CybersourceSimulator::SECRET_KEY), $fields['signature']);
        $covered = array_diff(array_keys($fields), [...$signed, 'signature']) === [] && array_diff($signed, array_keys($fields)) === [];

        expect($valid && $covered)->toBeFalse($variant);
    }
});

test('the REST lookup reports the amount and currency that were really charged, which can differ from the return', function () {
    csOpen('u-amt');
    $fields = csChoose('accept', 'u-amt', ['settled_amount' => '1.00'])['fields'];
    csOpen('u-cur');
    csChoose('accept', 'u-cur', ['settled_currency' => 'USD']);

    expect($fields['req_amount'])->toBe('1130.55')
        ->and(csFind('ATT-u-amt')['orderInformation']['amountDetails'])->toBe(['totalAmount' => '1.00', 'currency' => 'NPR'])
        ->and(csFind('ATT-u-cur')['orderInformation']['amountDetails'])->toBe(['totalAmount' => '1130.55', 'currency' => 'USD']);
});

test('an authorization is found but is not captured, so it cannot be refunded', function () {
    csOpen('u-auth', ['transaction_type' => 'authorization']);
    $id = csChoose('accept', 'u-auth')['fields']['transaction_id'];

    expect(csFind('ATT-u-auth')['applicationInformation']['status'])->toBe('AUTHORIZED')
        ->and(csRest('POST', "/pts/v2/payments/{$id}/refunds", ['orderInformation' => ['amountDetails' => ['totalAmount' => '1.00', 'currency' => 'NPR']]])->status())->toBe(400);
});

test('REST calls need a valid HTTP Signature: key, secret, date, digest and merchant id', function () {
    $query = ['query' => 'clientReferenceInformation.code:none'];

    expect(csRest('POST', '/tss/v2/searches', $query)->status())->toBe(200)
        ->and(csRest('GET', '/tss/v2/transactions/1')->status())->toBe(404)
        ->and(csRest('POST', '/tss/v2/searches', $query, ['keyid' => 'another-key'])->status())->toBe(401)
        ->and(csRest('POST', '/tss/v2/searches', $query, ['secret' => base64_encode('SomeOtherSharedSecret000000000000')])->status())->toBe(401)
        ->and(csRest('POST', '/tss/v2/searches', $query, ['date' => gmdate('D, d M Y H:i:s', time() - 3600).' GMT'])->status())->toBe(401)
        ->and(csRest('POST', '/tss/v2/searches', $query, ['body' => '{"query":"id:1"}'])->status())->toBe(401)
        ->and(csRest('GET', '/tss/v2/transactions/1', tamper: ['secret' => base64_encode('SomeOtherSharedSecret000000000000')])->status())->toBe(401)
        ->and(csBrowser()->get($this->scenario->apiUrl().'/tss/v2/transactions/1')->json('response.rmsg'))->toBe('Authentication Failed')
        ->and(csRest('POST', '/tss/v2/searches', ['query' => 'status:AUTHORIZED'])->json('reason'))->toBe('INVALID_DATA');
});

test('REST refunds take part and then the rest of a captured payment, and refuse more, another currency or an unknown payment', function () {
    $form = csOpen();
    $id = csChoose('accept')['fields']['transaction_id'];
    $refund = fn (string $amount, string $currency = 'NPR', ?string $payment = null): Response => csRest('POST', '/pts/v2/payments/'.($payment ?? $id).'/refunds', [
        'clientReferenceInformation' => ['code' => 'RF-'.$amount], 'orderInformation' => ['amountDetails' => ['totalAmount' => $amount, 'currency' => $currency]],
    ]);

    $partial = $refund('130.55');

    expect($partial->status())->toBe(201)
        ->and($partial->json())->toMatchArray(['status' => 'PENDING', 'refundAmountDetails' => ['currency' => 'NPR', 'refundAmount' => '130.55']])
        ->and($refund('1000.01')->json('reason'))->toBe('INVALID_REQUEST')
        ->and($refund('1.00', 'USD')->status())->toBe(400)
        ->and($refund('0.00')->status())->toBe(400)
        ->and($refund('1.00', payment: '0000000000000000000000')->status())->toBe(400);

    $full = $refund('1000.00');
    $related = csRest('POST', '/tss/v2/searches', ['query' => 'rootId:'.$id])->json('_embedded.transactionSummaries');

    expect($full->status())->toBe(201)
        ->and($refund('0.01')->status())->toBe(400)
        ->and(array_column(array_column(array_column($related, 'orderInformation'), 'amountDetails'), 'totalAmount'))->toBe(['1000.00', '130.55'])
        ->and(csRest('GET', '/tss/v2/transactions/'.$partial->json('id'))->json('applicationInformation.applications.0.name'))->toBe('ics_credit')
        ->and(csFind($form['reference_number'])['id'])->toBe($id);
});

test('the framework faults apply to the REST operations', function () {
    $this->scenario->faults()->add('search', new Fault('status', ['status' => 503, 'body' => '{}']), 1);

    expect(csRest('POST', '/tss/v2/searches', ['query' => 'id:1'])->status())->toBe(503)
        ->and(csRest('POST', '/tss/v2/searches', ['query' => 'id:1'])->status())->toBe(200);
});
