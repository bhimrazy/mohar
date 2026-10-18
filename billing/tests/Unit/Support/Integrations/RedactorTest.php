<?php

use App\Support\Integrations\Redactor;

test('sensitive keys and known secret values are masked recursively', function () {
    $redacted = (new Redactor)->redact([
        'hostname' => 'web1.example.net',
        'credentials' => ['username' => 'root', 'password' => 'hunter22', 'api_key' => 'k-1'],
        'Authorization' => 'Bearer abc',
        'note' => 'connecting with hunter22 now',
        'list' => ['hunter22', 'ok'],
        'access_hash' => null,
        'token' => '',
    ], ['hunter22']);

    expect($redacted)->toBe([
        'hostname' => 'web1.example.net',
        'credentials' => ['username' => 'root', 'password' => '[redacted]', 'api_key' => '[redacted]'],
        'Authorization' => '[redacted]',
        'note' => 'connecting with [redacted] now',
        'list' => ['[redacted]', 'ok'],
        'access_hash' => null,
        'token' => '',
    ]);
});

test('a sensitive key hides its whole subtree', function () {
    expect((new Redactor)->redact(['password' => ['value' => 'plain-pass'], 'credentials' => ['token' => ['a' => 'b']]]))
        ->toBe(['password' => '[redacted]', 'credentials' => ['token' => '[redacted]']]);
});

test('secret keys are matched by word, whatever the spelling', function (string $key) {
    expect((new Redactor)->redact([$key => 'value', 'domain' => 'example.com']))
        ->toBe([$key => '[redacted]', 'domain' => 'example.com']);
})->with([
    'PW', 'key', 'pass', 'epp_code', 'auth_info', 'auth', 'X-Auth-Token', 'transfer_code', 'api-key', 'ApiKey', 'APIKey',
    'apikey', 'passwd', 'newpassword', 'signature', 'private_key', 'access_hash', 'card_number', 'cvv', 'exp_month',
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

test('card numbers are masked inside free text when they pass the Luhn check', function () {
    expect((new Redactor)->redact(['message' => 'Card 4242 4242 4242 4242 was declined', 'order' => '1234567890123']))
        ->toBe(['message' => 'Card [redacted-pan] was declined', 'order' => '1234567890123']);
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
