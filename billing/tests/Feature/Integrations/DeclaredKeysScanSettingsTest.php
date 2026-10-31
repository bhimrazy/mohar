<?php

use App\Support\Integrations\Redactor;

// Mirrors the failing-scan test in DeclaredKeysMaskingTest: lowering only pcre.backtrack_limit leaves JIT on, so the
// compiled pattern stays usable for long bodies later in the same worker (pcre.jit=0 would cache it without JIT).
test('the settings the failing-scan test changes do not outlast it in the worker', function () {
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        app(Redactor::class)->valuesUnder(['ticket'], ['body' => '{"ticket":"ZXCV-99812-QWER","ticket":"BBBB-2222-yyyy"}']);
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
    }

    $body = json_encode(['html' => str_repeat('a"b\\', 400_000), 'ticket' => 'ZXCV-99812-QWER']);

    expect(app(Redactor::class)->valuesUnder(['ticket'], ['body' => $body]))->toBe(['ZXCV-99812-QWER']);
});
