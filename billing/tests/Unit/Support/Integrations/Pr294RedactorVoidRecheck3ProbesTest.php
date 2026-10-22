<?php

use App\Support\Integrations\Redactor;

/*
 * Third re-review probes for #294 at 57eb2efb. N5 (blocking): when nextHtmlMarker() stops at $before it resumes from
 * the old $position, not from the `<` it found, so every masked pair before a far `<` rescans the same text with
 * strpos(): quadratic (1 MB 1.3 s, 2 MB 4.7 s; develop 134 ms, 270 ms). N6 (low): a `<` that starts no tag (`a < b`,
 * `<3`) is skipped as a tag up to the next `>`, so a genuine `<html>` after it is missed and the page over-masks.
 */

function recheck3Seconds(string $text): float
{
    $best = INF;

    for ($run = 0; $run < 3; $run++) {
        $started = hrtime(true);
        (new Redactor)->maskValues($text, []);
        $best = min($best, (hrtime(true) - $started) / 1e9);
    }

    return $best;
}

test('N5: masked pairs before a distant html marker scale linearly', function (string $unit, string $tail) {
    $small = str_repeat($unit, intdiv(128 * 1024, strlen($unit))).$tail;
    $large = str_repeat($unit, intdiv(1024 * 1024, strlen($unit))).$tail;

    // Eight times the input: about 8x when linear, about 64x when quadratic; 16x leaves room for noise.
    expect(recheck3Seconds($large))->toBeLessThan(max(0.25, recheck3Seconds($small) * 16));
})->with([
    'pairs then html' => ['token=a&', '<html>'],
    'pairs then doctype' => ['token=a&', '<!doctype x>'],
    'labelled lines then html' => ["pw: a\n", '<html>'],
]);

test('N6: a less-than sign in text does not hide a later html marker', function (string $text) {
    expect((new Redactor)->maskValues($text, []))->toContain('KEEP');
})->with([
    'spaced less-than' => ['a < b <html><input name="password"><p>KEEP</p>'],
    'heart' => ['I <3 it <html><input name="password"><p>KEEP</p>'],
]);
