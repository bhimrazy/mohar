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

test('registrar keys are covered: exact short keys, EPP codes, signatures and dashed API keys', function (string $key) {
    expect((new Redactor)->redact([$key => 'value', 'domain' => 'example.com']))
        ->toBe([$key => '[redacted]', 'domain' => 'example.com']);
})->with(['code', 'PW', 'key', 'pass', 'epp_code', 'auth_info', 'transfer_code', 'api-key', 'ApiKey', 'passwd', 'signature', 'private_key']);

test('ordinary keys that only resemble short secret keys are kept', function () {
    expect((new Redactor)->redact(['country_code' => 'NP', 'keyword' => 'hosting', 'passive' => true]))
        ->toBe(['country_code' => 'NP', 'keyword' => 'hosting', 'passive' => true]);
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
