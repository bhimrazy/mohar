<?php

/*
 * Fifth re-check probes for PR #252 at 2d1f731c (K1, Medium, non-blocking). Since 87d322d9 a `key` attribute value is
 * read as a label, so an element whose only payload is that value now leaks it; develop masks it (`key` is a sensitive
 * name). The parity guard misses it: the quoted case is skipped as a name, the unquoted case is not in the corpus.
 * The guards pin the OpenSRS label case this PR made readable on purpose.
 */

use App\Support\Integrations\Redactor;

require_once __DIR__.'/RedactorParityTest.php';

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
    expect(redactorParityReadableOnPurpose('<license key="Zq1x7Lm2Kp"/>', 'Zq1x7Lm2Kp'))->toBeFalse();
});

test('K1 guard: a key attribute that labels element content stays readable', function (string $input, string $kept) {
    expect((new Redactor)->maskValues($input, []))->toContain($kept);
})->with([
    'opensrs item' => ['<item key="domain">a.com</item>', 'key="domain">a.com'],
    'labelled secret item' => ['<item key="reg_password">P789</item>', 'key="reg_password"'],
]);
