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
