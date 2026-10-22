<?php

/*
 * Review probes for PR #252 (#190). Each one fails at 52a463b6 and should pass after the fix.
 */

use App\Support\Integrations\Redactor;

/** Repeat $unit (given its index) until the text is $bytes long. */
function pr252Input(int $bytes, Closure $unit): string
{
    $text = '';

    for ($index = 0; strlen($text) < $bytes; $index++) {
        $text .= $unit($index);
    }

    return $text;
}

test('B1: unclosed secret elements with distinct names stay linear (1 MB under 2 s, at most 10x of 256 KB)', function (Closure $unit) {
    $redactor = new Redactor;
    $small = pr252Input(256 * 1024, $unit);
    $large = pr252Input(1024 * 1024, $unit);

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

test('M1: realistic secret shapes the scan still lets through', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'EPP login password change (RFC 5730 newPW)' => ['<login><clID>reg1</clID><pw>OLD1</pw><newPW>NEWPW123</newPW></login>', 'NEWPW123'],
    'cPanel session cookie after another cookie' => ['Cookie: theme=dark; cpsession=root%3aCPSESS123', 'CPSESS123'],
    'WHM session cookie after another cookie' => ['Cookie: PHPSESSID=abc; whostmgrsession=WHMSESS123', 'WHMSESS123'],
    'cPanel session cookie as a bare pair' => ['Set cpsession=root:CPSESS456 failed', 'CPSESS456'],
    'print_r dump in an exception message' => ["Bad response: Array\n(\n    [password] => PRINTR123\n    [user] => bob\n)", 'PRINTR123'],
    'pretty JSON with the value on the next line' => ["HTTP 500: {\n  \"api_key\":\n    \"PRETTY123\"\n}", 'PRETTY123'],
]);

test('L1: lower-likelihood shapes that still leak', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'eSewa-style secret with & in an unquoted value' => ['secret_key=8gBm/:&EnhH.1/q', 'EnhH.1/q'],
    'name longer than 64 characters' => ['registrant_contact_extended_attributes_for_the_registry_admin_password=LONG123', 'LONG123'],
    'userinfo password with a raw @' => ['GET https://user:p@ATSECRET@host.test/ failed', 'ATSECRET'],
    'curl -u credentials' => ['curl -u root:CURLPW123 https://whm.test:2087', 'CURLPW123'],
    'HTML-escaped JSON in an error page' => ['<pre>{&quot;password&quot;:&quot;HTML123&quot;}</pre>', 'HTML123'],
]);
