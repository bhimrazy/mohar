<?php

/*
 * XML elements and their `key`/`name` labels: end tags far away or in other cases, void-named XML containers, and a
 * `key` attribute that is an element's only payload. Folded from the review probes of #252 (#311); a test name keeps
 * its probe id (V1, V2, K1, ...).
 */

use App\Support\Integrations\Redactor;
use Tests\Support\Redaction\RedactorParity;

test('R2: a secret element whose end tag comes after more than 64 other end tags is still masked whole', function () {
    $text = '<password>'.str_repeat('<i>x</i>', 65).'DEEPSECRET</password>';

    expect((new Redactor)->maskValues($text, []))->toBe('<password>[redacted]</password>');
});

test('V1: an XML element named like an HTML void element still has its secret content masked', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'param labelled password' => ['<param name="password">VOIDSECRET1</param>', 'VOIDSECRET1'],
    'param with cdata' => ['<param name="password"><![CDATA[VOIDSECRET2]]></param>', 'VOIDSECRET2'],
    'namespaced param' => ['<ns:param name="api_key">VOIDSECRET3</ns:param>', 'VOIDSECRET3'],
    'source keyed like opensrs' => ['<source key="password">VOIDSECRET4</source>', 'VOIDSECRET4'],
]);

test('V1 guard: an HTML void element with a secret name masks nothing after it', function () {
    $html = '<!DOCTYPE html><html><body><input type="password" name="password" value=""><p>Forgot your password?</p>';

    expect((new Redactor)->maskValues($html, []))->toBe($html);
});

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
    'forgot password' => ['<!DOCTYPE html><html><body><input type="password" name="password" value=""><p>Forgot your password?</p>'],
    'self-closed input' => ['<input name="password" /> Enter it here <b>now</b>'],
    'non-secret param' => ['<param name="domain">a.com.np</param><br>ok'],
]);

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

test('K1: a key attribute that is the element\'s only payload is masked, as on develop', function (string $input, string $token) {
    expect((new Redactor)->maskValues($input, []))->not->toContain($token)
        ->and((string) (new Redactor)->redact(['note' => $input])['note'])->not->toContain($token);
})->with([
    'self-closing' => ['<license key="Zq1x7Lm2Kp"/>', 'Zq1x7Lm2Kp'],
    'self-closing with a blank' => ['<license key="Zq2x7Lm2Kp" />', 'Zq2x7Lm2Kp'],
    'empty element' => ['<license key="Zq3x7Lm2Kp"></license>', 'Zq3x7Lm2Kp'],
    'after another attribute' => ['<auth user="u" key="Zq4x7Lm2Kp"/>', 'Zq4x7Lm2Kp'],
    'unquoted' => ['<x key=Zq8x7Lm2Kp/>', 'Zq8x7Lm2Kp'],
]);

test('K1 parity: the readable-on-purpose skip does not hide a key attribute with no element content', function () {
    expect(RedactorParity::readableOnPurpose('<license key="Zq1x7Lm2Kp"/>', 'Zq1x7Lm2Kp'))->toBeFalse();
});

test('K1 guard: a key attribute that labels element content stays readable', function (string $input, string $kept) {
    expect((new Redactor)->maskValues($input, []))->toContain($kept);
})->with([
    'opensrs item' => ['<item key="domain">a.com</item>', 'key="domain">a.com'],
    'labelled secret item' => ['<item key="reg_password">P789</item>', 'key="reg_password"'],
]);

dataset('label without a value', [
    'child elements only' => ['<license key="Zr1x7Lm2Kp"><child/></license>', 'Zr1x7Lm2Kp'],
    'child elements on new lines' => ["<license key=\"Zr2x7Lm2Kp\">\n  <child>x</child>\n</license>", 'Zr2x7Lm2Kp'],
    'comment only' => ['<license key="Zr3x7Lm2Kp"><!-- note --></license>', 'Zr3x7Lm2Kp'],
    'whitespace text' => ["<license key='Zr4x7Lm2Kp'> \t\r\n </license>", 'Zr4x7Lm2Kp'],
    'empty CDATA' => ['<license key="Zr5x7Lm2Kp"><![CDATA[]]></license>', 'Zr5x7Lm2Kp'],
    'blank CDATA' => ["<license key=\"Zr6x7Lm2Kp\"><![CDATA[ \n ]]></license>", 'Zr6x7Lm2Kp'],
    'empty value attribute' => ['<x key="Zr7x7Lm2Kp" value=""/>', 'Zr7x7Lm2Kp'],
    'blank value attribute' => ['<x key="Zr8x7Lm2Kp" value=" "/>', 'Zr8x7Lm2Kp'],
]);

test('a key/name attribute with no non-blank value or text in its element is masked, as on develop', function (string $input, string $token) {
    expect((new Redactor)->maskValues($input, []))->not->toContain($token)
        ->and((string) (new Redactor)->redact(['note' => $input])['note'])->not->toContain($token);
})->with('label without a value');

test('the parity skip does not hide a key/name attribute with no non-blank value or text', function (string $input, string $token) {
    expect(RedactorParity::readableOnPurpose($input, $token))->toBeFalse();
})->with('label without a value');

test('the parity skip does not hide a name attribute next to a blank value', function () {
    // develop leaves a `name` attribute readable, so parity compares it rather than skipping it
    expect(RedactorParity::readableOnPurpose("<x value='\t' name='Zr9x7Lm2Kp'></x>", 'Zr9x7Lm2Kp'))->toBeFalse();
});

test('a label with text, non-blank CDATA or a non-blank value stays readable and its secret is masked', function (string $input, string $expected) {
    expect((new Redactor)->maskValues($input, []))->toBe($expected);
})->with([
    'text' => ['<item key="reg_password">P789</item>', '<item key="reg_password">[redacted]</item>'],
    'CDATA text' => ['<item key="reg_password"><![CDATA[ P789]]></item>', '<item key="reg_password">[redacted]</item>'],
    'value attribute' => ['<param name="api_key" value="V1"/>', '<param name="api_key" value="[redacted]"/>'],
    'plain label' => ['<item key="domain">a.com</item>', '<item key="domain">a.com</item>'],
]);

test('a secret-naming key/name with child elements only still masks the content', function () {
    expect((new Redactor)->maskValues('<param name="password"><value>Zr0x7Lm2Kp</value></param>', []))
        ->not->toContain('Zr0x7Lm2Kp');
});
