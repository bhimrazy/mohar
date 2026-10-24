<?php

/*
 * While Nepal VAT mode is on, only the note issuer writes value adjustments on a numbered invoice (vat-register §5.4,
 * ADR 0024 §10 PR 3b). The old paths still write them for invoices without a number, so each file that writes an old-style
 * value adjustment must decide the mode first: it reads `inEffectLocked()` (a shared lock on the mode row, one instant per
 * transaction). The refund path is the one exception until VAT PR 4 moves it to the reservation state machine; the list
 * below is that exception, so a new writer fails here until it is gated or listed.
 */

const OLD_STYLE_VALUE_REASONS = 'Cancellation|ItemCancellation|LateFee|Refund|Manual|Chargeback';

/** Files that write an old-style value adjustment before the mode is read: PR 4 replaces them (refund state machine). */
const VALUE_WRITERS_UNTIL_PR4 = ['Modules/Billing/Actions/RefundTransaction.php'];

/**
 * @return list<string> files under app/ whose code calls `adjust(` with an old-style value reason, relative to app/
 */
function valueAdjustmentWriters(string $root): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = collect(token_get_all((string) file_get_contents($file->getPathname())))
            ->reject(fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($token) => is_array($token) ? $token[1] : $token)->implode('');

        if (preg_match('/->\s*adjust\s*\([^;]*?AdjustmentReason\s*::\s*('.OLD_STYLE_VALUE_REASONS.')\b/s', $code) === 1) {
            $found[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    sort($found);

    return $found;
}

test('every writer of an old-style value adjustment reads the mode under its lock, except the refund path until PR 4', function () {
    $root = dirname(__DIR__, 2).'/app';
    $writers = valueAdjustmentWriters($root);

    expect($writers)->not->toBeEmpty();

    foreach ($writers as $file) {
        if (in_array($file, VALUE_WRITERS_UNTIL_PR4, true)) {
            continue;
        }

        expect(str_contains((string) file_get_contents($root.'/'.$file), 'inEffectLocked('))->toBeTrue("{$file} writes a value adjustment without reading Nepal VAT mode under its lock");
    }

    // The exception list holds only files that still write one.
    expect(array_diff(VALUE_WRITERS_UNTIL_PR4, $writers))->toBe([]);
});

afterAll(fn () => releaseArchCache());
