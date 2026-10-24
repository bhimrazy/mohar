<?php

/*
 * The linear-time and bounded-memory budget (ADR 0023 §2): every shape that once ran quadratic or exhausted memory.
 * Folded from the review probes of #164 (B2), #252 (B1) and #294 (N1, N3, N5) (#311); a test name keeps its probe id.
 */

use App\Support\Integrations\Redactor;
use Tests\Support\Redaction\RedactorTiming;

test('a 1 MB adversarial input is masked in under 2 s with bounded memory', function (Closure $unit) {
    $large = RedactorTiming::input(1024 * 1024, $unit);

    memory_reset_peak_usage();
    $before = memory_get_usage();

    expect(RedactorTiming::bestSeconds($large, 3, ['hunter22']))->toBeLessThan(2.0)
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(24 * 1024 * 1024);
})->with([
    'colon-joined labels' => [fn (): string => 'a:'],
    'secret pairs' => [fn (): string => 'token=a&'],
    'unclosed secret elements' => [fn (): string => '<password>'],
    'distinct unclosed secret elements' => [fn (int $i): string => '<password'.base_convert((string) $i, 10, 36).'>x'],
    'distinct namespaced EPP pw' => [fn (int $i): string => '<a'.base_convert((string) $i, 10, 36).':pw>x'],
    'distinct labelled items' => [fn (int $i): string => '<i'.base_convert((string) $i, 10, 36).' key="password">'],
    'secret elements closed far away' => [fn (int $i): string => $i === 0 ? '<password>' : '</b'.$i.'>'],
    'unclosed labelled items' => [fn (): string => '<item key="reg_password">'],
    'tags with attributes' => [fn (): string => '<x a="b=c" password=\'d\'>'],
    'unterminated quotes' => [fn (): string => 'password="'],
    'escaped quotes' => [fn (): string => 'password=\\"'],
    'soft value ends' => [fn (): string => 'passwd=;,'],
    'ampersands in a secret' => [fn (): string => 'secret=a&b'],
    'url schemes' => [fn (): string => 'h://'],
    'userinfo without @' => [fn (): string => 'h://aaaa'],
    'encoded pairs' => [fn (): string => '%3Fa%3D'],
    'cli flags' => [fn (): string => ' -u a:b'],
    'long names' => [fn (): string => str_repeat('n', 300).'='],
]);

/*
 * Eight times the input costs about 8x when the scan is linear and about 64x when it is quadratic (#252 B1). 512 KB
 * against 4 MB keeps both sizes past a core's L2 cache; best of three runs, small and large interleaved so a burst of
 * other work on a shared machine hits both. 24x leaves room for that noise and none for a quadratic scan.
 */
test('the shapes that once ran quadratic scale linearly from 512 KB to 4 MB', function (Closure $unit) {
    $small = RedactorTiming::input(512 * 1024, $unit);
    $large = RedactorTiming::input(4 * 1024 * 1024, $unit);
    [$smallSeconds, $largeSeconds] = [INF, INF];

    for ($run = 0; $run < 3; $run++) {
        $smallSeconds = min($smallSeconds, RedactorTiming::bestSeconds($small, 1, ['hunter22']));
        $largeSeconds = min($largeSeconds, RedactorTiming::bestSeconds($large, 1, ['hunter22']));
    }

    expect($largeSeconds)->toBeLessThan(max(0.25, $smallSeconds * 24));
})->with([
    'distinct unclosed secret elements' => [fn (int $i): string => '<password'.base_convert((string) $i, 10, 36).'>x'],
    'distinct namespaced EPP pw' => [fn (int $i): string => '<a'.base_convert((string) $i, 10, 36).':pw>x'],
    'distinct labelled items' => [fn (int $i): string => '<i'.base_convert((string) $i, 10, 36).' key="password">'],
    'unclosed secret elements' => [fn (): string => '<password>'],
    'secret pairs' => [fn (): string => 'token=a&'],
    'void-named elements with an unclosed end tag' => [fn (): string => '<param name="password"></x'],
    'void-named elements with an end tag then text' => [fn (): string => '<param name="password"></x a'],
    'void-named elements with markers in comments' => [fn (): string => '<!-- <html> --><input name="password"><b>x</b>'],
    'void-named XML elements with CDATA' => [fn (): string => '<param name="password"><![CDATA[x]]>'],
    'labels with the value on the next line' => [fn (): string => "pw:\n"],
    'session ids with a joined tail' => [fn (): string => 'Session: cs_live_a1B2c3D4:'],
]);

test('masking a body with one void-named secret element uses bounded memory (#278)', function (string $unit) {
    // The leading comment holds marker text, so the marker walk runs over the whole body.
    $raw = '<!-- <html> -->'.str_repeat($unit, intdiv(1024 * 1024, strlen($unit))).'<input name="password"><b>x</b>';
    memory_reset_peak_usage();
    $before = memory_get_usage();

    (new Redactor)->maskValues($raw, []);

    expect(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
})->with([
    'tags' => ['<b>'],
    'comments' => ['<!-- c -->'],
    'cdata' => ['<![CDATA[x]]>'],
    'quoted attributes' => ['<a t="<html>">'],
    'processing instructions' => ['<?p >?>'],
]);

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

test('B1: unclosed secret elements with distinct names stay linear (1 MB under 2 s, at most 10x of 256 KB)', function (Closure $unit) {
    $redactor = new Redactor;
    $small = RedactorTiming::input(256 * 1024, $unit);
    $large = RedactorTiming::input(1024 * 1024, $unit);

    $started = hrtime(true);
    $redactor->maskValues($small, []);
    $smallSeconds = (hrtime(true) - $started) / 1e9;

    $started = hrtime(true);
    $redactor->maskValues($large, []);
    $largeSeconds = (hrtime(true) - $started) / 1e9;

    expect($largeSeconds)->toBeLessThan(2.0)
        ->and($largeSeconds)->toBeLessThan(max(0.05, $smallSeconds * 10));
})->with([
    // Measured at 52a463b6 (XDEBUG_MODE=off): 1 MB takes 22 s, 45 s and 10 s; 256 KB takes 1.5 s, 2.7 s and 0.6 s.
    'secret-named elements' => [fn (int $i): string => '<password'.base_convert((string) $i, 10, 36).'>x'],
    'namespaced EPP pw' => [fn (int $i): string => '<a'.base_convert((string) $i, 10, 36).':pw>x'],
    'labelled OpenSRS-style items' => [fn (int $i): string => '<i'.base_convert((string) $i, 10, 36).' key="password">'],
]);

test('N1: void-named elements followed by an unclosed end tag scale linearly', function (string $unit) {
    $small = str_repeat($unit, intdiv(32 * 1024, strlen($unit)));
    $large = str_repeat($unit, intdiv(256 * 1024, strlen($unit)));

    // Eight times the input: about 8x when linear, about 64x when quadratic.
    expect(RedactorTiming::bestSeconds($large))->toBeLessThan(max(0.25, RedactorTiming::bestSeconds($small) * 24));
})->with([
    'end tag with no >' => ['<param name="password"></x'],
    'end tag then text' => ['<param name="password"></x a'],
]);

test('N3: the HTML marker pre-pass uses bounded memory on a tag-dense body', function () {
    $raw = '<input name="password"><b>x</b>'.str_repeat('<b>', intdiv(1024 * 1024, 3));
    memory_reset_peak_usage();
    $before = memory_get_usage();

    (new Redactor)->maskValues($raw, []);

    // develop: about 1.4 MB; 0e0598c3: about 217 MB.
    expect(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
});

test('N5: masked pairs before a distant html marker scale linearly', function (string $unit, string $tail) {
    $small = str_repeat($unit, intdiv(128 * 1024, strlen($unit))).$tail;
    $large = str_repeat($unit, intdiv(1024 * 1024, strlen($unit))).$tail;

    // Eight times the input: about 8x when linear, about 64x when quadratic; 16x leaves room for noise.
    expect(RedactorTiming::bestSeconds($large))->toBeLessThan(max(0.25, RedactorTiming::bestSeconds($small) * 16));
})->with([
    'pairs then html' => ['token=a&', '<html>'],
    'pairs then doctype' => ['token=a&', '<!doctype x>'],
    'labelled lines then html' => ["pw: a\n", '<html>'],
]);
