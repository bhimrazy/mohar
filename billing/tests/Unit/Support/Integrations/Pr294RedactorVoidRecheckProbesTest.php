<?php

use App\Support\Integrations\Redactor;

/*
 * Re-review probes for #294 at 277144d8. N1 (blocking): a stray end tag with no `>` before the next tag sends
 * voidContainerEnd() to the end of the text and back to the first end tag, once per element, so the scan is quadratic.
 * N2 (medium): the HTML marker is any `<html`, `<head`, `<body` substring before the element, also inside a comment,
 * CDATA, an attribute value, a secret value or an XML `<Body>` element, which restores the M1 leak.
 */

function recheckSeconds(string $text): float
{
    $best = INF;

    for ($run = 0; $run < 3; $run++) {
        $started = hrtime(true);
        (new Redactor)->maskValues($text, []);
        $best = min($best, (hrtime(true) - $started) / 1e9);
    }

    return $best;
}

test('N1: void-named elements followed by an unclosed end tag scale linearly', function (string $unit) {
    $small = str_repeat($unit, intdiv(32 * 1024, strlen($unit)));
    $large = str_repeat($unit, intdiv(256 * 1024, strlen($unit)));

    // Eight times the input: about 8x when linear, about 64x when quadratic.
    expect(recheckSeconds($large))->toBeLessThan(max(0.25, recheckSeconds($small) * 24));
})->with([
    'end tag with no >' => ['<param name="password"></x'],
    'end tag then text' => ['<param name="password"></x a'],
]);

test('N2: a marker that is not an HTML document tag keeps the element failing closed', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'marker in a comment' => ['<!-- <html> --><input name="password"><value>S01', 'S01'],
    'marker in CDATA' => ['<x><![CDATA[<html>]]></x><input name="password"><value>S02', 'S02'],
    'marker in an attribute value' => ['<a title="<html>"/><input name="password"><value>S03', 'S03'],
    'marker in a masked secret value' => ['<password><html>pw</password><input name="password"><value>S04', 'S04'],
    'default-namespace SOAP Body' => ['<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/"><Body><Login><input name="password"><string>S20', 'S20'],
    'XML body element before XML-RPC' => ['<?xml version="1.0"?><message><body>hi</body><param name="password"><value><string>S21', 'S21'],
])->skip('Medium N2 from the #294 re-review: CTO decision on tightening the marker; develop leaks the same way');
