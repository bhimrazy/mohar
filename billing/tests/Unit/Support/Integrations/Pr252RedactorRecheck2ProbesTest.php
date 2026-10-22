<?php

/*
 * Second re-check probes for PR #252 at d8f3dfc8. Every case in a `->with()` set fails at that head; the guards pass.
 * V1 was masked at 08cbcd95; N1 and A1 were masked on develop and at 08cbcd95 (A1 UUID: masked on develop).
 */

use App\Support\Integrations\Redactor;

test('V1: an XML element named like an HTML void element still has its secret content masked', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'param labelled password' => ['<param name="password">VOIDSECRET1</param>', 'VOIDSECRET1'],
    'param with cdata' => ['<param name="password"><![CDATA[VOIDSECRET2]]></param>', 'VOIDSECRET2'],
    'namespaced param' => ['<ns:param name="api_key">VOIDSECRET3</ns:param>', 'VOIDSECRET3'],
    'source keyed like opensrs' => ['<source key="password">VOIDSECRET4</source>', 'VOIDSECRET4'],
]);

test('V1 guard: an HTML void element with a secret name masks nothing after it', function () {
    $html = '<body><input type="password" name="password" value=""><p>Forgot your password?</p>';

    expect((new Redactor)->maskValues($html, []))->toBe($html);
});

test('N1: an unquoted secret label with its value on the next line is still masked', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'password then newline' => ["password:\nNEXTLINE1", 'NEXTLINE1'],
    'api key header, crlf' => ["api_key:\r\nNEXTLINE2\r\nHost: a.test", 'NEXTLINE2'],
    'equals then newline' => ["password=\nNEXTLINE3", 'NEXTLINE3'],
    'blank line between' => ["token:\n\nNEXTLINE4", 'NEXTLINE4'],
]);

test('N1 guard: an empty Cookie header still keeps the next header line', function () {
    expect((new Redactor)->maskValues("Cookie:\r\nHost: keepme.test", []))->toBe("Cookie:\r\nHost: keepme.test");
});

test('A1: a Session label keeps only an exact reference id readable, nothing joined to it', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'colon after the id' => ['Session: cs_live_a1B2c3D4:ALLOWSECRET1', 'ALLOWSECRET1'],
    'encoded colon after the id' => ['Session: cs_live_a1B2c3D4%3AALLOWSECRET2', 'ALLOWSECRET2'],
    'comma after the id' => ['Session: pi_a1B2c3D4,ALLOWSECRET3', 'ALLOWSECRET3'],
    'uuid session token' => ['Invalid session: 3f2a9c4e-1b7d-4e8a-9c2f-6d5e4b3a2c1f', '3f2a9c4e-1b7d-4e8a-9c2f-6d5e4b3a2c1f'],
]);

test('A1 guard: a Stripe id after a Session label stays readable', function () {
    expect((new Redactor)->maskValues('Session: cs_live_a1B2c3D4 expired; Session: pi_a1B2c3D4 failed', []))
        ->toBe('Session: cs_live_a1B2c3D4 expired; Session: pi_a1B2c3D4 failed');
});
