<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Connectips\ConnectipsApi;
use Mohar\Simulators\Providers\Connectips\ConnectipsSimulator as Sim;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The connectips simulator over its TLS port, as a driver would meet it: the signed form post, the hosted page and its
 * outcomes, validatetxn and gettxndetail with their credit codes, and the open assumptions as configuration. There is
 * no connectIPS driver yet; the tests sign and look up the way the driver will (payments-gateways-expansion.md §4).
 */
beforeEach(function () {
    $this->scenario = Processes::start(Sim::class);
    $this->act = fn (string $name, array $input = []): array => $this->scenario->simulator()->actions()[$name]($input, $this->scenario);
    ($this->act)('register_return_urls', ['success' => 'https://app.test/return/ok', 'failure' => 'https://app.test/return/failed?src=cips']);
    // The simulator's own Nepal date, frozen so a run across midnight cannot split the form's date from the check.
    $this->today = ($this->act)('clock')['today'];
    ($this->act)('configure', ['frozen_date' => $this->today]);
    openssl_pkcs12_read((string) file_get_contents(Sim::pfxPath($this->scenario)), $bundle, Sim::PFX_PASSPHRASE);
    $this->privateKey = $bundle['pkey'];
});

afterEach(function () {
    Processes::stop($this->scenario);
});

function cipsBrowser(): PendingRequest
{
    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath(), 'allow_redirects' => false])->timeout(5);
}

function cipsSign(string $message, int $algorithm = OPENSSL_ALGO_SHA256): string
{
    openssl_sign($message, $signature, test()->privateKey, $algorithm);

    return base64_encode($signature);
}

/**
 * The form the driver builds for a payment of $paisa, signed unless a token is given.
 *
 * @param  array<string, string>  $override
 * @return array<string, string>
 */
function cipsForm(string $txnId, int $paisa = 113_055, array $override = [], string $spacing = 'none'): array
{
    $fields = [
        'MERCHANTID' => Sim::MERCHANT_ID, 'APPID' => Sim::APP_ID, 'APPNAME' => Sim::APP_NAME, 'TXNID' => $txnId,
        'TXNDATE' => test()->today, 'TXNCRNCY' => 'NPR',
        'TXNAMT' => (string) $paisa, 'REFERENCEID' => 'INV-1001', 'REMARKS' => 'Hosting', 'PARTICULARS' => 'Invoice 1001',
        ...$override,
    ];

    return [...$fields, 'TOKEN' => $override['TOKEN'] ?? cipsSign(ConnectipsApi::paymentString($fields, $spacing))];
}

function cipsPost(array $form): Response
{
    return cipsBrowser()->asForm()->post(test()->scenario->apiUrl().'/connectipswebgw/loginpage', $form);
}

/** Books a payment and returns the hosted page's form action prefix, e.g. /connectipswebgw/choice/<id>. */
function cipsBook(string $txnId, int $paisa = 113_055): string
{
    $page = cipsPost(cipsForm($txnId, $paisa));
    expect($page->status())->toBe(200);
    preg_match('#/connectipswebgw/choice/([0-9a-f]+)/pay#', $page->body(), $match);

    return $match[1];
}

function cipsChoose(string $id, string $choice): Response
{
    return cipsBrowser()->post(test()->scenario->apiUrl().'/connectipswebgw/choice/'.$id.'/'.$choice);
}

/**
 * @param  array<string, string>  $override
 */
function cipsLookup(string $operation, string $txnId, int $paisa = 113_055, array $override = [], string $password = Sim::APP_PASSWORD, string $app = Sim::APP_ID, int $algorithm = OPENSSL_ALGO_SHA256): Response
{
    $body = ['merchantId' => Sim::MERCHANT_ID, 'appId' => $app, 'referenceId' => $txnId, 'txnAmt' => (string) $paisa, ...$override];
    $body['token'] = $override['token'] ?? cipsSign("MERCHANTID={$body['merchantId']},APPID={$body['appId']},REFERENCEID={$body['referenceId']},TXNAMT={$body['txnAmt']}", $algorithm);

    return cipsBrowser()->withBasicAuth($app, $password)->post(test()->scenario->apiUrl().'/connectipswebws/api/creditor/'.$operation, $body);
}

test('a paid transaction returns only TXNID and settles through validatetxn and gettxndetail, in paisa', function () {
    $id = cipsBook('T-100');
    $return = cipsChoose($id, 'pay');

    expect($return->status())->toBe(303)->and($return->header('Location'))->toBe('https://app.test/return/ok?TXNID=T-100');

    expect(cipsLookup('validatetxn', 'T-100')->json())->toBe([
        'merchantId' => '550', 'appId' => Sim::APP_ID, 'referenceId' => 'T-100', 'txnAmt' => '113055', 'token' => null,
        'status' => 'SUCCESS', 'statusDesc' => 'TRANSACTION SUCCESSFUL',
    ]);

    $detail = cipsLookup('gettxndetail', 'T-100')->json();
    expect($detail)->toMatchArray(['status' => 'SUCCESS', 'txnAmt' => '1130.55', 'chargeAmt' => '4.00', 'chargeLiability' => 'MN', 'creditStatus' => '000', 'debitBankCode' => '0110'])
        ->and($detail['txnId'])->toMatch('/^\d{11}$/');

    // A second Pay (double click, replayed return) lands on the same success URL and changes nothing.
    expect(cipsChoose($id, 'pay')->header('Location'))->toBe('https://app.test/return/ok?TXNID=T-100')
        ->and(cipsLookup('gettxndetail', 'T-100')->json('txnId'))->toBe($detail['txnId']);
});

test('the fee follows the amount slab and a customer-borne charge is reported as CG', function () {
    foreach ([[50_000, '2.00'], [50_001, '4.00'], [500_000, '4.00'], [500_001, '8.00']] as $n => [$paisa, $fee]) {
        $id = cipsBook("S-{$n}", $paisa);
        ($this->act)('pay', ['txn_id' => "S-{$n}", 'charge_liability' => $n === 3 ? 'CG' : 'MN']);
        expect(cipsLookup('gettxndetail', "S-{$n}", $paisa)->json())->toMatchArray(['chargeAmt' => $fee, 'chargeLiability' => $n === 3 ? 'CG' : 'MN']);
    }
});

test('unpaid outcomes read as NCHL reports them: failed, incomplete, and not found for cancel or abandon', function () {
    $outcomes = ['decline' => ['FAILED', 'TRANSACTION FAILED', 'failed'], 'leave' => ['ERROR', 'TRANSACTION INCOMPLETE', 'failed'], 'cancel' => ['ERROR', 'TRANSACTION NOT FOUND', 'failed']];

    foreach ($outcomes as $choice => [$status, $description, $target]) {
        $id = cipsBook("U-{$choice}");
        expect(cipsChoose($id, $choice)->header('Location'))->toBe("https://app.test/return/{$target}?src=cips&TXNID=U-{$choice}")
            ->and(cipsLookup('validatetxn', "U-{$choice}")->json())->toMatchArray(['status' => $status, 'statusDesc' => $description])
            ->and(cipsLookup('gettxndetail', "U-{$choice}")->json())->not->toHaveKey('creditStatus');
    }

    cipsBook('U-abandon');
    expect(cipsLookup('validatetxn', 'U-abandon')->json('statusDesc'))->toBe('TRANSACTION NOT FOUND')
        ->and(cipsLookup('validatetxn', 'NEVER-POSTED')->json('statusDesc'))->toBe('TRANSACTION NOT FOUND');

    // A decided transaction takes no second choice, whichever way it ended.
    foreach (['decline', 'leave', 'cancel'] as $choice) {
        $id = cipsBook("U-end-{$choice}");
        expect(cipsChoose($id, $choice)->status())->toBe(303)
            ->and(cipsChoose($id, 'pay')->status())->toBe(410)
            ->and(cipsLookup('validatetxn', "U-end-{$choice}")->json('status'))->not->toBe('SUCCESS');
    }

    expect(cipsChoose(cipsBook('U-twice-2'), 'bogus')->status())->toBe(410);
});

test('creditStatus carries 000, 999, DEFER and a failing code; a left transaction can be paid later', function () {
    foreach ([['999', '999'], ['F01', ConnectipsApi::CREDIT_FAILED]] as [$code, $given]) {
        cipsBook("C-{$code}");
        ($this->act)('pay', ['txn_id' => "C-{$code}", 'credit_status' => $given]);
        expect(cipsLookup('gettxndetail', "C-{$code}")->json('creditStatus'))->toBe($code);
    }

    $id = cipsBook('C-defer');
    cipsChoose($id, 'defer');
    expect(cipsLookup('gettxndetail', 'C-defer')->json('creditStatus'))->toBe('DEFER');
    ($this->act)('settle', ['txn_id' => 'C-defer', 'credit_status' => '000']);
    expect(cipsLookup('gettxndetail', 'C-defer')->json('creditStatus'))->toBe('000');

    cipsChoose(cipsBook('C-late'), 'leave');
    expect(cipsLookup('validatetxn', 'C-late')->json('status'))->toBe('ERROR');
    ($this->act)('settle', ['txn_id' => 'C-late', 'outcome' => 'success']);
    expect(cipsLookup('validatetxn', 'C-late')->json('status'))->toBe('SUCCESS');
    cipsBook('C-bad');
    expect(fn () => ($this->act)('settle', ['txn_id' => 'C-late', 'outcome' => 'failed']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ($this->act)('settle', ['txn_id' => 'C-late', 'credit_status' => '123']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ($this->act)('pay', ['txn_id' => 'C-bad', 'credit_status' => 'OK']))->toThrow(InvalidArgumentException::class);
});

test('the form is refused when the token, a field or the date is wrong, and a reused TXNID is a 403', function () {
    $refused = fn (array $form, string $text) => expect(cipsPost($form))->status()->toBe(400)->and(cipsPost($form)->body())->toContain($text);

    $refused(cipsForm('R-1', override: ['TOKEN' => base64_encode('not a signature')]), 'Invalid token');
    // Signed for another amount: the values must be the ones in the form, byte for byte.
    $tampered = cipsForm('R-2');
    $refused([...$tampered, 'TXNAMT' => '1'], 'Invalid token');
    $refused(cipsForm('R-3', override: ['TXNID' => str_repeat('9', 21)]), 'TXNID is longer than 20');
    $refused(cipsForm('R-4', override: ['TXNDATE' => '01-01-2020']), 'TXNDATE must be today in Nepal time');
    $refused(cipsForm('R-5', override: ['TXNCRNCY' => 'USD']), 'Currency must be NPR');
    $refused(cipsForm('R-6', override: ['APPID' => 'OTHER']), 'Unknown merchant or app');
    $refused(cipsForm('R-7', 200_000_001), 'amount 1 to 200000000 paisa');
    $refused([...cipsForm('R-8'), 'REMARKS' => ''], 'REMARKS is required');

    cipsBook('R-dup');
    expect(cipsPost(cipsForm('R-dup'))->status())->toBe(403);
    expect($this->scenario->log()->entries('loginpage')[0]['input'])->toHaveKey('TOKEN')->and(json_encode($this->scenario->log()->entries('loginpage')))->not->toContain($tampered['TOKEN']);
});

test('the token string format is an open assumption: only the configured spacing is accepted', function () {
    expect(cipsPost(cipsForm('F-1', spacing: 'space'))->status())->toBe(400);

    ($this->act)('configure', ['token_spacing' => 'space']);
    expect(cipsPost(cipsForm('F-1', spacing: 'space'))->status())->toBe(200)
        ->and(cipsPost(cipsForm('F-2'))->status())->toBe(400);
    expect(fn () => ($this->act)('configure', ['token_spacing' => 'tab']))->toThrow(InvalidArgumentException::class);
});

test('a payer choice before return URLs are registered, or after the session ends, is a 403 session expired', function () {
    $id = cipsBook('E-1');
    ($this->act)('register_return_urls', ['success' => '', 'failure' => '']);
    $this->scenario->state()->put('config', 'merchant', [...$this->scenario->state()->get('config', 'merchant'), 'return_urls' => null]);

    expect(cipsChoose($id, 'pay')->status())->toBe(403)->and(cipsChoose($id, 'pay')->body())->toContain('Session expired')
        ->and(cipsLookup('validatetxn', 'E-1')->json('status'))->toBe('ERROR');

    ($this->act)('register_return_urls', ['success' => 'https://app.test/ok', 'failure' => 'https://app.test/no']);
    $key = Sim::APP_ID.':E-1';
    $this->scenario->state()->put('txns', $key, [...$this->scenario->state()->get('txns', $key), 'expires_at' => time() - 1]);
    expect(cipsChoose($id, 'pay')->status())->toBe(403);
});

test('lookups refuse wrong credentials, a bad token, another amount and answer as configured for a bad token', function () {
    cipsChoose(cipsBook('L-1'), 'pay');

    expect(cipsLookup('validatetxn', 'L-1', password: 'wrong')->status())->toBe(401)
        ->and(cipsLookup('validatetxn', 'L-1', override: ['txnAmt' => '99'])->json('statusDesc'))->toBe('TRANSACTION NOT FOUND')
        ->and(cipsBrowser()->withBasicAuth(Sim::APP_ID, Sim::APP_PASSWORD)->post($this->scenario->apiUrl().'/connectipswebws/api/creditor/validatetxn', ['appId' => Sim::APP_ID])->status())->toBe(400);

    // The real reply to a bad token is unknown (assumption); the default is a 200 ERROR, and it is configurable.
    $bad = fn () => cipsLookup('validatetxn', 'L-1', override: ['token' => base64_encode('forged')]);
    expect($bad()->json())->toMatchArray(['status' => 'ERROR', 'statusDesc' => 'INVALID TOKEN']);
    ($this->act)('configure', ['bad_token_reply' => 'http_400']);
    expect($bad()->status())->toBe(400);
    ($this->act)('configure', ['bad_token_reply' => 'http_401']);
    expect($bad()->status())->toBe(401);

    expect(json_encode($this->scenario->log()->entries('validatetxn')))->not->toContain(Sim::APP_PASSWORD)->not->toContain(base64_encode(Sim::APP_ID.':'.Sim::APP_PASSWORD));
});

test('amount style and echo variants let a driver prove it checks what NCHL answers', function () {
    cipsChoose(cipsBook('V-1'), 'pay');

    ($this->act)('configure', ['validate_amount_style' => 'rupees']);
    expect(cipsLookup('validatetxn', 'V-1')->json('txnAmt'))->toBe('1130.55');

    foreach (['wrong_amount' => ['txnAmt', '1131.55'], 'wrong_reference' => ['referenceId', 'OTHER-V-1'], 'wrong_merchant' => ['merchantId', '9999'], 'wrong_app' => ['appId', 'OTHER-APP']] as $variant => [$field, $value]) {
        ($this->act)('configure', ['echo_variant' => $variant]);
        expect(cipsLookup('validatetxn', 'V-1')->json($field))->toBe($value);
    }
});

test('a lookup that commits and then hangs is the client timeout, and the next lookup still sees the payment', function () {
    cipsChoose(cipsBook('H-1'), 'pay');
    $this->scenario->faults()->add('validatetxn', Fault::commitThenHang(4), nth: 1);

    expect(fn () => cipsBrowser()->timeout(1)->withBasicAuth(Sim::APP_ID, Sim::APP_PASSWORD)->post($this->scenario->apiUrl().'/connectipswebws/api/creditor/validatetxn', ['x' => 1]))
        ->toThrow(ConnectionException::class);
    expect(cipsLookup('validatetxn', 'H-1')->json('status'))->toBe('SUCCESS');
});

test('the throwaway .pfx opens with its passphrase and there is no refund route', function () {
    expect(openssl_pkcs12_read((string) file_get_contents(Sim::pfxPath($this->scenario)), $bundle, 'wrong'))->toBeFalse()
        ->and(openssl_pkcs12_read((string) file_get_contents(Sim::pfxPath($this->scenario)), $bundle, Sim::PFX_PASSPHRASE))->toBeTrue()
        ->and(openssl_x509_parse($bundle['cert'])['subject']['CN'])->toBe('Simulated connectIPS creditor')
        ->and(array_filter((new Sim)->operations(), fn (string $name) => str_contains($name, 'refund')))->toBe([]);
});

test('a merchant with several apps: each app has its own password and its own TXNID space', function () {
    $form = cipsForm('M-1', override: ['APPID' => Sim::APP_ID_2]);
    expect(cipsPost($form)->status())->toBe(200);
    cipsBook('M-1');
    ($this->act)('pay', ['txn_id' => 'M-1', 'app_id' => Sim::APP_ID_2]);

    expect(cipsLookup('validatetxn', 'M-1', app: Sim::APP_ID_2, password: Sim::APP_PASSWORD_2)->json('status'))->toBe('SUCCESS')
        ->and(cipsLookup('validatetxn', 'M-1')->json('statusDesc'))->toBe('TRANSACTION NOT FOUND')
        ->and(cipsLookup('validatetxn', 'M-1', app: Sim::APP_ID_2)->status())->toBe(401);
});

test('the validation-token algorithm is an open assumption: only the configured one is accepted', function () {
    cipsChoose(cipsBook('A-1'), 'pay');

    expect(cipsLookup('validatetxn', 'A-1', algorithm: OPENSSL_ALGO_SHA1)->json('statusDesc'))->toBe('INVALID TOKEN');
    ($this->act)('configure', ['validation_algorithm' => 'sha1']);
    expect(cipsLookup('validatetxn', 'A-1', algorithm: OPENSSL_ALGO_SHA1)->json('status'))->toBe('SUCCESS')
        ->and(cipsLookup('validatetxn', 'A-1')->json('statusDesc'))->toBe('INVALID TOKEN');
});
