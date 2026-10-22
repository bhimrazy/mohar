<?php

/*
 * Review-board probes for PR #164's Redactor change (maskPairs recursion), adopted as regression tests. M2 and the
 * #164 re-check leaks were fixed centrally in #190.
 */

use App\Support\Integrations\Redactor;

// B2 — fails at 508acbc4. maskPairs() calls itself on the value of every non-secret pair that contains `=` or `:`,
// and each level copies the rest of the string, so memory grows with (length x number of colon-joined tokens). A
// 16 KB provider string of prefixed tags (`<domain:name>…`) peaks at ~34 MB; at 84 KB it exhausts a 256 MB
// memory_limit with an uncatchable fatal error (develop: 12 MB, 1 ms). Reachable from RegistrarGateway::record(),
// ExecuteModuleCommand and VerifyUnknownOutcomes with provider-controlled text.
test('B2: masking a long colon-joined provider string uses bounded memory', function () {
    $raw = 'Response: '.str_repeat('<domain:name>x</domain:name>', 600);
    memory_reset_peak_usage();
    $before = memory_get_usage();

    (new Redactor)->maskValues($raw, []);

    expect(memory_get_peak_usage() - $before)->toBeLessThan(4 * 1024 * 1024);
});

test('B2: an 84 KB provider string is masked in well under a second and a few MB', function () {
    $raw = 'Response: '.str_repeat('<domain:name>x</domain:name>', 3000);
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $started = hrtime(true);

    $masked = (new Redactor)->maskValues($raw, []);

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(4 * 1024 * 1024)
        ->and($masked)->toBe($raw)
        ->and((new Redactor)->maskValues(str_repeat('a:', 8000).' api-key=K123', []))->not->toContain('K123');
});

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
