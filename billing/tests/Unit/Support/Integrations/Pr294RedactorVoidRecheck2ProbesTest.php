<?php

use App\Support\Integrations\Redactor;

/*
 * Second re-review probes for #294 at 0e0598c3. N3 (blocking): the HTML marker pre-pass keeps every tag, comment and
 * CDATA match of the whole text in one preg_match_all() array (about 210 bytes per input byte), so a 1.5 MB body with
 * one void-named secret element exhausts the 256M production memory_limit with an uncatchable fatal error (the
 * class of #164 B2). N4 (medium): a processing instruction that contains `>` ends early, so `<html` after it counts.
 */

test('N3: the HTML marker pre-pass uses bounded memory on a tag-dense body', function () {
    $raw = '<input name="password"><b>x</b>'.str_repeat('<b>', intdiv(1024 * 1024, 3));
    memory_reset_peak_usage();
    $before = memory_get_usage();

    (new Redactor)->maskValues($raw, []);

    // develop: about 1.4 MB; 0e0598c3: about 217 MB.
    expect(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
});

test('N4: an html tag inside a processing instruction that contains > is not a marker', function () {
    expect((new Redactor)->maskValues('<?pi a > <html ?><input name="password"><value>PI1SECRET', []))->not->toContain('PI1SECRET');
})->skip('Medium N4 from the #294 second re-review: develop leaks the same way');
