<?php

use App\Modules\Services\Support\Redactor;

test('sensitive keys and known secret values are masked recursively', function () {
    $redacted = (new Redactor)->redact([
        'hostname' => 'web1.example.net',
        'auth' => ['username' => 'root', 'password' => 'hunter22', 'api_key' => 'k-1'],
        'Authorization' => 'Bearer abc',
        'note' => 'connecting with hunter22 now',
        'list' => ['hunter22', 'ok'],
        'access_hash' => null,
    ], ['hunter22']);

    expect($redacted)->toBe([
        'hostname' => 'web1.example.net',
        'auth' => ['username' => 'root', 'password' => '***', 'api_key' => '***'],
        'Authorization' => '***',
        'note' => 'connecting with *** now',
        'list' => ['***', 'ok'],
        'access_hash' => null,
    ]);
});

test('a sensitive key hides its whole subtree', function () {
    expect((new Redactor)->redact(['password' => ['value' => 'plain-pass'], 'credentials' => ['token' => ['a' => 'b']]]))
        ->toBe(['password' => '***', 'credentials' => ['token' => '***']]);
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
