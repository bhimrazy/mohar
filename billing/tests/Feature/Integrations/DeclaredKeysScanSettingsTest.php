<?php

/*
 * Re-review probe for #574 round 5 (head 27791448), non-blocking. The adopted test "a JSON body is withheld when the
 * repeated-key scan cannot run" restores pcre.jit and pcre.backtrack_limit, but a pattern PHP compiles while
 * pcre.jit=0 stays in the PCRE cache without JIT for the rest of the worker process. If that test is the first in a
 * worker to run repeatsKey(), later tests in the worker scan JSON without JIT and hit the backtrack limit far sooner.
 * This probe repeats the test's steps, then reads a 2.4 MB JSON body that a fresh process reads in a few ms. It fails
 * here. Setting only pcre.backtrack_limit=1 (JIT on) still makes the scan fail closed and leaves the cache intact.
 */

use App\Support\Integrations\Redactor;

test('the settings the failing-scan test changes do not outlast it in the worker', function () {
    $jit = ini_get('pcre.jit');
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        app(Redactor::class)->valuesUnder(['ticket'], ['body' => '{"ticket":"ZXCV-99812-QWER","ticket":"BBBB-2222-yyyy"}']);
    } finally {
        ini_set('pcre.jit', (string) $jit);
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    $body = json_encode(['html' => str_repeat('a"b\\', 400_000), 'ticket' => 'ZXCV-99812-QWER']);

    expect(app(Redactor::class)->valuesUnder(['ticket'], ['body' => $body]))->toBe(['ZXCV-99812-QWER']);
});
