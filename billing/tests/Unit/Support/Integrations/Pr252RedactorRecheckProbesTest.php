<?php

/*
 * Re-check probes for PR #252 at 08cbcd95. Each one fails at that head. R1 cases were masked on develop and at 52a463b6;
 * R2 was masked at 52a463b6; R3 never was.
 */

use App\Support\Integrations\Redactor;

test('R1: a Session or hash label in free text still hides a credential that is not a reference id', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'Session header line' => ["Session: SESSTOKEN1\r\nHost: whm.test", 'SESSTOKEN1'],
    'lower-case session label' => ['login ok, session: SESSTOKEN2', 'SESSTOKEN2'],
    'session label with the value on the next line' => ["Session:\n  SESSTOKEN3", 'SESSTOKEN3'],
    'password hash in a debug message' => ['user bob hash: $2y$10$HASHVALUE4abcdefghijk', 'HASHVALUE4'],
]);

test('R1: a Stripe Checkout Session id after a Session label stays readable', function () {
    expect((new Redactor)->maskValues('Session: cs_live_a1B2c3D4 expired', []))->toBe('Session: cs_live_a1B2c3D4 expired');
});

test('R2: a secret element whose end tag comes after more than 64 other end tags is still masked whole', function () {
    $text = '<password>'.str_repeat('<i>x</i>', 65).'DEEPSECRET</password>';

    expect((new Redactor)->maskValues($text, []))->toBe('<password>[redacted]</password>');
});

test('R3: curl credentials in quotes or attached to the flag are masked', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'single-quoted' => ["curl -u 'root:CURLQ1' https://whm.test:2087", 'CURLQ1'],
    'double-quoted' => ['curl -u "root:CURLQ2" https://whm.test:2087', 'CURLQ2'],
    'attached to -u' => ['curl -uroot:CURLQ3 https://whm.test:2087', 'CURLQ3'],
]);

test('R4: an empty Cookie header does not mask the next header line', function () {
    expect((new Redactor)->maskValues("Cookie:\r\nHost: keepme.test", []))->toBe("Cookie:\r\nHost: keepme.test");
});
