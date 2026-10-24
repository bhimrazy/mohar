<?php

/*
 * `name=value`, `name: value` and labelled text: secret pairs behind labels, values on the next line, Session and hash
 * labels, curl credentials and header lines. Folded from the review probes of #164 and #252 (#311); a test name keeps
 * its probe id (M2, R1, N1, A1, ...).
 */

use App\Support\Integrations\Redactor;
use Tests\Support\Redaction\RedactorParity;

// Verifies "public behaviour unchanged" for ordinary labelled text.
test('labelled text without secrets is unchanged', function () {
    $text = 'Rejected: domain: a.com.np, time: 12:30:45, url: https://r.test/a?b=c';

    expect((new Redactor)->maskValues($text, []))->toBe($text);
});

// M2 — fail at 508acbc4: secret formats the pair pass still keeps (pre-existing; the C5 contract claims "no credential
// ever reaches registrar_command_log"). OpenSRS answers in XML, ResellerClub error bodies are JSON.
test('M2: secrets in a JSON body quoted after a label, or in XML, are masked', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'json after a label' => ['HTTP 500: {"api-key":"K123","status":"ERROR"}', 'K123'],
    'xml element' => ['<passwd>P456</passwd>', 'P456'],
    'opensrs item' => ['<item key="reg_password">P789</item>', 'P789'],
    'value with a semicolon' => ['passwd=ab;cd', ';cd'],
]);

// #190 re-check (issue comment): past #164's bounds (2 levels, 4 KB values) a secret was kept whole.
test('#190: a secret pair past any number of labels or behind a long value is masked', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'three labels' => ['a:b:c:api-key=K1', 'K1'],
    'label, pair, label' => ['err:code=x:token=K1', 'K1'],
    'label value over 4 KB' => ['Rejected: '.str_repeat('x', 5000).'?api-key=K1', 'K1'],
    'url over 4 KB' => ['url: https://r.test/'.str_repeat('a', 5000).'?token=K1', 'K1'],
]);

test('M1: realistic secret shapes the scan still lets through', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'EPP login password change (RFC 5730 newPW)' => ['<login><clID>reg1</clID><pw>OLD1</pw><newPW>NEWPW123</newPW></login>', 'NEWPW123'],
    'cPanel session cookie after another cookie' => ['Cookie: theme=dark; cpsession=root%3aCPSESS123', 'CPSESS123'],
    'WHM session cookie after another cookie' => ['Cookie: PHPSESSID=abc; whostmgrsession=WHMSESS123', 'WHMSESS123'],
    'cPanel session cookie as a bare pair' => ['Set cpsession=root:CPSESS456 failed', 'CPSESS456'],
    'print_r dump in an exception message' => ["Bad response: Array\n(\n    [password] => PRINTR123\n    [user] => bob\n)", 'PRINTR123'],
    'pretty JSON with the value on the next line' => ["HTTP 500: {\n  \"api_key\":\n    \"PRETTY123\"\n}", 'PRETTY123'],
]);

test('L1: lower-likelihood shapes that still leak', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'eSewa-style secret with & in an unquoted value' => ['secret_key=8gBm/:&EnhH.1/q', 'EnhH.1/q'],
    'name longer than 64 characters' => ['registrant_contact_extended_attributes_for_the_registry_admin_password=LONG123', 'LONG123'],
    'userinfo password with a raw @' => ['GET https://user:p@ATSECRET@host.test/ failed', 'ATSECRET'],
    'curl -u credentials' => ['curl -u root:CURLPW123 https://whm.test:2087', 'CURLPW123'],
    'HTML-escaped JSON in an error page' => ['<pre>{&quot;password&quot;:&quot;HTML123&quot;}</pre>', 'HTML123'],
]);

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

test('N2: a secret label with its value on the next line is masked even when the value contains = or :', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'base64 with padding' => ["token:\nQWxhZGRpbjpvcGVuc2VzYW1l==", 'QWxhZGRpbjpvcGVuc2VzYW1l'],
    'value with equals' => ["password:\nabcSECRET=def", 'abcSECRET'],
    'value with colon' => ["secret:\nhunter2:NEXTSECRET", 'hunter2'],
    'equals label, crlf' => ["api_key=\r\nKEYSECRET5==\r\nHost: a.test", 'KEYSECRET5'],
]);

test('N2 guard: an empty header keeps a next line that is itself a header', function (string $text) {
    expect((new Redactor)->maskValues($text, []))->toBe($text);
})->with([
    'cookie crlf' => ["Cookie:\r\nHost: keepme.test"],
    'cookie lf' => ["Cookie:\nHost: keepme.test"],
    'authorization' => ["Authorization:\nContent-Type: text/plain"],
]);

test('N2 guard: a header-shaped next line is kept but its own secret is still masked', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'x-token' => ["password:\nX-Token: abc12345", 'abc12345'],
    'x-api-key crlf' => ["password:\r\nX-Api-Key: KEYZZZZ", 'KEYZZZZ'],
    'authorization bearer' => ["password:\nAuthorization: Bearer ZZZTOKEN", 'ZZZTOKEN'],
]);

test('residual L4: a next-line value that ends in a colon is masked, as on develop', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'password' => ["password:\nhunter2:", 'hunter2'],
    'secret before a header' => ["secret:\nabc12345:\r\nHost: a.test", 'abc12345'],
    'token' => ["token:\nQWxhZGRpbjpvcGVu:\n", 'QWxhZGRpbjpvcGVu'],
]);

test('M1 parity: the readable-on-purpose skip does not hide a value after key= outside a tag', function (string $input, string $token) {
    expect(RedactorParity::readableOnPurpose($input, $token))->toBeFalse();
})->with([
    'bare key' => ['key=PSKIP2222', 'PSKIP2222'],
    'api-key' => ['api-key=PSKIP1111', 'PSKIP1111'],
    'query key' => ['x&key=PSKIP4444&y=1', 'PSKIP4444'],
    'capitalised next-line value' => ["password:\nHunter2:", 'Hunter2'],
]);
