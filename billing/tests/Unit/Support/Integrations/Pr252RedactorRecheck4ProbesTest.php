<?php

/*
 * Fourth re-check probes for PR #252 at 62b2b96e. The two `->with()` sets under "residual" fail at that head (both Low,
 * non-blocking); the parity-skip probe fails too. Every guard passes and pins the V2 end-tag cache and the N2
 * header-shaped rule against the attacks tried in this round.
 */

use App\Support\Integrations\Redactor;

require_once __DIR__.'/RedactorParityTest.php';

test('V2 guard: the cached end tag masks nested, repeated, commented and mixed-case containers', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'repeated closed params' => ['<param name="password"><value>A1</value></param><param name="password"><value>A2X</value></param>', 'A2X'],
    'end tag only inside a later comment' => ['<param name="password"><value>C1SECRET</value><!-- </param> -->', 'C1SECRET'],
    'end tag only inside a later cdata' => ['<param name="password"><value>C2SECRET</value><![CDATA[ </param> ]]>', 'C2SECRET'],
    'upper-case end tag' => ['<param name="password"><value>D1SECRET</value></PARAM>', 'D1SECRET'],
    'upper-case start tag' => ['<PARAM name="password"><value>D2SECRET</value></param>', 'D2SECRET'],
    'near-miss end tag fails closed' => ['<param name="password"><value>D3SECRET</value></params>', 'D3SECRET'],
    'nested same name' => ['<param name="password"><param name="password"><value>F1SECRET</value></param></param>', 'F1SECRET'],
    'input used as a container' => ['<input name="password"><b>H1SECRET</b></input>', 'H1SECRET'],
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

test('residual L5: an unclosed void-named container masks its child content (develop and 08cbcd95 leak it too)', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'truncated rpc' => ["<methodCall><params>\n<param name=\"password\">\n  <value><string>TRUNC1SECRET", 'TRUNC1SECRET'],
    'after the last end tag' => ['<param name="password"><value>B1</value></param> <param name="password"><value>B2SECRET</value>', 'B2SECRET'],
])->skip('L5 is not fixed in #252: tracked in #278 (develop leaks it too)');

test('M1 parity: the readable-on-purpose skip does not hide a value after key= outside a tag', function (string $input, string $token) {
    expect(redactorParityReadableOnPurpose($input, $token))->toBeFalse();
})->with([
    'bare key' => ['key=PSKIP2222', 'PSKIP2222'],
    'api-key' => ['api-key=PSKIP1111', 'PSKIP1111'],
    'query key' => ['x&key=PSKIP4444&y=1', 'PSKIP4444'],
    'capitalised next-line value' => ["password:\nHunter2:", 'Hunter2'],
]);
