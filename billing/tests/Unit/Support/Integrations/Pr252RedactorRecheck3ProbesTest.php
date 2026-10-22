<?php

/*
 * Third re-check probes for PR #252 at 4774a6f7. Every case in a `->with()` set fails at that head; the guards pass.
 * V2 was masked at 08cbcd95 (develop leaks it too). N2 is masked on develop and at 08cbcd95.
 */

use App\Support\Integrations\Redactor;

test('V2: a void-named XML element keeps its content masked when a child element or CDATA comes first', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'nested value element' => ['<param name="password"><value>VOIDNEST1</value></param>', 'VOIDNEST1'],
    'indented nested elements' => ["<param name=\"password\">\n  <value><string>VOIDNEST2</string></value>\n</param>", 'VOIDNEST2'],
    'comment first' => ['<param name="password"><!-- note -->VOIDNEST3</param>', 'VOIDNEST3'],
    'text after cdata' => ['<param name="password"><![CDATA[a]]>VOIDNEST4</param>', 'VOIDNEST4'],
]);

test('V2 guard: HTML void elements with a secret name still leave the markup after them alone', function (string $html) {
    expect((new Redactor)->maskValues($html, []))->toBe($html);
})->with([
    'forgot password' => ['<body><input type="password" name="password" value=""><p>Forgot your password?</p>'],
    'self-closed input' => ['<input name="password" /> Enter it here <b>now</b>'],
    'non-secret param' => ['<param name="domain">a.com.np</param><br>ok'],
]);

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
