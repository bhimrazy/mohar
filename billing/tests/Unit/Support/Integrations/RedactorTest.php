<?php

use App\Support\Integrations\Redactor;

test('sensitive keys and known secret values are masked recursively', function () {
    $redacted = (new Redactor)->redact([
        'hostname' => 'web1.example.net',
        'server' => ['username' => 'root', 'password' => 'hunter22', 'api_key' => 'k-1'],
        'Authorization' => 'Bearer abc',
        'note' => 'connecting with hunter22 now',
        'list' => ['hunter22', 'ok'],
        'access_hash' => null,
        'token' => '',
    ], ['hunter22']);

    expect($redacted)->toBe([
        'hostname' => 'web1.example.net',
        'server' => ['username' => 'root', 'password' => '[redacted]', 'api_key' => '[redacted]'],
        'Authorization' => '[redacted]',
        'note' => 'connecting with [redacted] now',
        'list' => ['[redacted]', 'ok'],
        'access_hash' => null,
        'token' => '',
    ]);
});

test('a sensitive key hides its whole subtree', function () {
    expect((new Redactor)->redact(['password' => ['value' => 'plain-pass'], 'answer' => ['token' => ['a' => 'b']]]))
        ->toBe(['password' => '[redacted]', 'answer' => ['token' => '[redacted]']]);
});

test('secret keys are matched by word, whatever the spelling', function (string $key) {
    expect((new Redactor)->redact([$key => 'value', 'domain' => 'example.com']))
        ->toBe([$key => '[redacted]', 'domain' => 'example.com']);
})->with([
    'PW', 'key', 'pass', 'epp_code', 'auth_info', 'auth', 'X-Auth-Token', 'transfer_code', 'api-key', 'ApiKey', 'APIKey',
    'apikey', 'passwd', 'newpassword', 'signature', 'private_key', 'access_hash', 'card_number', 'cvv', 'credentials',
    'hmac', 'ssh_key', 'licence_key', 'cookies', 'PHPSESSID', 'dv', 'dataValidation', 'accesskey',
]);

/**
 * Every key the three rule sets on develop masked (Services' module log, Domains' registrar log) stays masked, in the
 * spellings drivers and importers send. The two deliberate narrowings (architect review R2) are covered below:
 * `code` is secret only in EPP and transfer answers, and `auth` no longer matches inside words such as `author`.
 */
test('no key the old module and registrar rules masked is visible now', function (string $key) {
    expect((new Redactor)->isSensitiveKey($key))->toBeTrue();
})->with([
    // Services: password|passwd|secret|token|api_?key|hash|authorization (substring)
    'password', 'new_password', 'passwd', 'client_secret', 'secret', 'token', 'access_token', 'api_key', 'apikey', 'API-KEY',
    'hash', 'access_hash', 'accesshash', 'passhash', 'pwhash', 'Authorization', 'proxy_authorization',
    // Domains: exact code|pw|key|pass, fragments epp|auth|password|secret|token|api_key|apikey|private_key|transfer_code
    'pw', 'key', 'pass', 'epp', 'eppcode', 'epp_code', 'domain_epp', 'auth', 'authinfo', 'auth_info', 'authcode', 'auth_code',
    'authkey', 'private_key', 'privatekey', 'transfer_code', 'secretkey',
]);

test('ordinary keys that only resemble secret words are kept, including provider error codes', function () {
    $payload = ['author' => 'Jane', 'country_code' => 'NP', 'keyword' => 'hosting', 'passive' => true, 'company' => 'Acme', 'expires' => 1700000000, 'error' => ['code' => 500, 'message' => 'Internal error']];

    expect((new Redactor)->redact($payload))->toBe($payload);
});

test('a payload can mark extra keys as secret, such as code in an EPP code answer', function () {
    expect((new Redactor)->redact(['code' => 'ABCD-1234', 'emailed' => false], sensitiveKeys: ['code']))
        ->toBe(['code' => '[redacted]', 'emailed' => false]);
});

test('objects are read as arrays before redaction, so DTOs cannot carry secrets past the mask', function () {
    $dto = new class
    {
        public string $username = 'root';

        public string $password = 'hunter22';
    };
    $json = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return ['token' => 't-1', 'ok' => true];
        }
    };

    expect((new Redactor)->redact(['server' => $dto, 'answer' => $json, 'items' => collect([['secret' => 's']])]))->toBe([
        'server' => ['username' => 'root', 'password' => '[redacted]'],
        'answer' => ['token' => '[redacted]', 'ok' => true],
        'items' => [['secret' => '[redacted]']],
    ]);
});

test('secrets inside JSON strings, query strings, form bodies and header text are masked by name', function () {
    expect((new Redactor)->redact([
        'body' => '{"user":"a","password":"hunter2"}',
        'query' => 'https://api.example/x?api-key=K123&domain=a.com',
        'form' => 'user=a&password=hunter2&next=/home',
        'header' => 'Authorization: Bearer abc.def',
        'note' => 'the password field is required',
    ]))->toBe([
        'body' => '{"user":"a","password":"[redacted]"}',
        'query' => 'https://api.example/x?api-key=[redacted]&domain=a.com',
        'form' => 'user=a&password=[redacted]&next=/home',
        'header' => 'Authorization: [redacted]',
        'note' => 'the password field is required',
    ]);
});

test('module logs keep timestamps and long ids; only payment payloads scrub card numbers', function () {
    expect((new Redactor)->redact(['captured_ms' => '1700000000000', 'order' => '4242424242424242']))
        ->toBe(['captured_ms' => '1700000000000', 'order' => '4242424242424242']);
});

test('nesting deeper than the cap is masked instead of walked', function () {
    $node = new stdClass;
    $node->child = $node;

    expect(json_encode((new Redactor)->redact(['graph' => $node])))->toContain('[redacted]');
});

/**
 * Develop's gateway rule (GatewayLogger::redact() at ab3c1dd), kept verbatim as the reference for the payment rule.
 *
 * @param  array<array-key, mixed>  $payload
 * @return array<array-key, mixed>
 */
function developGatewayRedact(array $payload): array
{
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            $payload[$key] = developGatewayRedact($value);
        } elseif (is_string($key) && $key !== 'txn_id' && $key !== 'parent_txn_id' && preg_match('/card|pan|number|cvc|cvv|token|secret|signature|password|exp/i', $key) === 1) {
            $payload[$key] = '[redacted]';
        } elseif (is_string($value)) {
            $payload[$key] = (new Redactor)->scrubCardNumbers($value);
        }
    }

    return $payload;
}

test('payment payloads are redacted exactly as the gateway log did before the merge', function () {
    $payload = [
        'txn_id' => 'ch_1', 'parent_txn_id' => 'ch_0', 'amount' => 5000, 'currency' => 'NPR', 'status' => 'Completed',
        'card' => ['brand' => 'visa', 'last4' => '4242', 'number' => '4242424242424242', 'exp_month' => 12, 'expiration_year' => 2030, 'cvc' => '123'],
        'bank_account' => ['number' => '0012345678', 'holder' => 'A'],
        'source' => ['number' => '1234', 'token' => null],
        'company' => 'Acme', 'mobile' => '98******04', 'message' => 'Card 4242 4242 4242 4242 was declined',
        'signature' => 'abc', 'secret_key' => 'sk', 'payment_method_token' => 'pm_1', 'expires' => 1700000000,
    ];

    expect((new Redactor)->redactPayment($payload))->toBe(developGatewayRedact($payload))
        ->and((new Redactor)->redactPayment($payload)['card'])->toMatchArray(['brand' => 'visa', 'last4' => '4242', 'number' => '[redacted]'])
        ->and((new Redactor)->redactPayment($payload)['source']['token'])->toBe('[redacted]');
});

test('payment payloads also mask the keys payments-nepal requires: authorization, dv and dataValidation', function () {
    expect((new Redactor)->redactPayment(['Authorization' => 'Key x', 'DV' => 'abc', 'dataValidation' => 'def', 'Msisdn' => '98']))
        ->toBe(['Authorization' => '[redacted]', 'DV' => '[redacted]', 'dataValidation' => '[redacted]', 'Msisdn' => '98']);
});

test('url-encoded and base64 forms of known secrets are masked', function () {
    $secret = 'abc/def+ghi';
    $redacted = (new Redactor)->redact([
        'url' => 'https://panel.test/api?api.token='.urlencode($secret),
        'raw' => 'path/'.rawurlencode($secret),
        'header' => 'Basic '.base64_encode($secret),
    ], [$secret]);

    expect(json_encode($redacted))->not->toContain(urlencode($secret))
        ->not->toContain(rawurlencode($secret))
        ->not->toContain(base64_encode($secret));
});

test('messages are masked with the same variants', function () {
    expect((new Redactor)->maskValues('login as root:hunter22 failed', ['hunter22', 'ab']))->toBe('login as root:[redacted] failed');
});

test('cPanel session keys and cpsess tokens are masked, and SSO answers can mark url as secret', function () {
    $redacted = (new Redactor)->redact([
        'pass' => 'x1',
        'cp_security_token' => '/cpsess1234567890',
        'session' => 'root:abc',
        'note' => 'redirect to https://web1.test:2087/cpsess9876543210/login',
        'data' => ['url' => 'https://web1.test:2083/cpsess111/login/?session=u:x', 'expires' => 1700000000],
    ], sensitiveKeys: ['url']);

    expect($redacted)->toBe([
        'pass' => '[redacted]',
        'cp_security_token' => '[redacted]',
        'session' => '[redacted]',
        'note' => 'redirect to https://web1.test:2087/[redacted]/login',
        'data' => ['url' => '[redacted]', 'expires' => 1700000000],
    ])->and((new Redactor)->redact(['url' => 'https://web1.test/json-api/version']))->toBe(['url' => 'https://web1.test/json-api/version']);
});

test('raw output excerpts are redacted, masked and capped', function () {
    $redactor = new Redactor;
    $json = json_encode(['metadata' => ['reason' => 'Bad password hunter22'], 'data' => ['password' => 'p']]);

    expect($redactor->excerpt((string) $json, ['hunter22']))->toBe('{"metadata":{"reason":"Bad password [redacted]"},"data":{"password":"[redacted]"}}')
        ->and($redactor->excerpt('token cpsess42 rejected for hunter22', ['hunter22']))->toBe('token [redacted] rejected for [redacted]')
        ->and(strlen($redactor->excerpt(str_repeat('a', 10_000))))->toBe(Redactor::EXCERPT_BYTES);
});
