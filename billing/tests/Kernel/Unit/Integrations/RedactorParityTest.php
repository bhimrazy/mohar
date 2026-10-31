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

/**
 * Tokens develop masks in each input that this Redactor leaves readable, with $secrets as the set.
 *
 * @param  list<string>  $inputs
 * @param  list<string>  $secrets
 * @return list<string>
 */
function redactorParityLeaks(array $inputs, array $secrets): array
{
    $develop = new DevelopRedactor;
    $redactor = new Redactor;
    $leaks = [];

    foreach ($inputs as $input) {
        $surfaces = [
            'maskValues' => [$develop->maskValues($input, $secrets), $redactor->maskValues($input, $secrets)],
            'redact' => [(string) $develop->redact(['note' => $input], $secrets)['note'], (string) $redactor->redact(['note' => $input], $secrets)['note']],
        ];

        foreach ($surfaces as $surface => [$before, $after]) {
            foreach (RedactorParity::maskedTokens($input, $before) as $token) {
                if (str_contains($after, $token) && ! RedactorParity::namesMaskedValue($after, $token)) {
                    $leaks[] = $surface.' '.json_encode($input).' leaks '.$token;
                }
            }
        }
    }

    return $leaks;
}

test('everything develop masks stays masked', function () {
    expect(redactorParityLeaks(RedactorParity::corpus(), []))->toBe([]);
});

// With a set, the value mask runs before the pair rules, so a pair rule must still end a value that holds a mask (#452 B1).
test('everything develop masks stays masked with a secret spliced next to a masked value', function () {
    $inputs = RedactorParity::withSecret('FZSECRETpw77');

    expect(count($inputs))->toBeGreaterThan(5000)
        ->and(redactorParityLeaks($inputs, ['FZSECRETpw77']))->toBe([]);
});
