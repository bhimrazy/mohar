<?php

/*
 * Parity guard for #252: nothing develop's Redactor masks may become readable. The baseline is a frozen, test-only copy
 * of develop's Redactor (Tests\Fixtures\Redactor\DevelopRedactor); the corpus and the readable-on-purpose rule are in
 * Tests\Support\Redaction\RedactorParity.
 *
 * A token counts as masked by develop when it is in the input and missing from develop's output; it must then be
 * missing from this Redactor's output too. This Redactor may mask more.
 */

use App\Support\Integrations\Redactor;
use Tests\Fixtures\Redactor\DevelopRedactor;
use Tests\Support\Redaction\RedactorParity;

test('the parity corpus is deterministic and covers the probe files and the generated shapes', function () {
    $corpus = RedactorParity::corpus();

    expect($corpus)->toBe(RedactorParity::corpus())
        ->and(count(RedactorParity::literals()))->toBeGreaterThan(150)
        ->and(count($corpus))->toBeGreaterThan(5000)
        ->and($corpus)->toContain("token:\nQWxhZGRpbjpvcGVuc2VzYW1l==", '<param name="password"><value>VOIDNEST1</value></param>', "password:\nhunter2:", 'api-key=PARKEY2');
});

test('everything develop masks stays masked', function () {
    $develop = new DevelopRedactor;
    $redactor = new Redactor;
    $leaks = [];

    foreach (RedactorParity::corpus() as $input) {
        $surfaces = [
            'maskValues' => [$develop->maskValues($input, []), $redactor->maskValues($input, [])],
            'redact' => [(string) $develop->redact(['note' => $input])['note'], (string) $redactor->redact(['note' => $input])['note']],
        ];

        foreach ($surfaces as $surface => [$before, $after]) {
            foreach (RedactorParity::maskedTokens($input, $before) as $token) {
                if (str_contains($after, $token)) {
                    $leaks[] = $surface.' '.json_encode($input).' leaks '.$token;
                }
            }
        }
    }

    expect($leaks)->toBe([]);
});
