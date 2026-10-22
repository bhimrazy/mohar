<?php

/*
 * Sixth re-check residuals for PR #252 at 07ea5f07 (Low). A `key`/`name` attribute stayed readable as a label when the
 * element held only child elements, or carried an empty `value` attribute; develop masks both. A label now needs a
 * non-blank `value` attribute or text right after the start tag, and the parity skip applies the same rule.
 */

use App\Support\Integrations\Redactor;

require_once __DIR__.'/RedactorParityTest.php';

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
    expect(redactorParityReadableOnPurpose($input, $token))->toBeFalse();
})->with('label without a value');

test('the parity skip does not hide a name attribute next to a blank value', function () {
    // develop leaves a `name` attribute readable, so parity compares it rather than skipping it
    expect(redactorParityReadableOnPurpose("<x value='\t' name='Zr9x7Lm2Kp'></x>", 'Zr9x7Lm2Kp'))->toBeFalse();
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
