<?php

/*
 * The HTML marker walk the Redactor's void-element rule uses (#311). The Redactor-level cases live in
 * RedactorShapesVoidElementsTest.
 */

use App\Support\Integrations\HtmlDocumentMarker;
use App\Support\Integrations\Redactor;

test('one markup name rule: an ASCII letter, _, : or a UTF-8 byte starts a name', function (string $text, int $length) {
    expect(HtmlDocumentMarker::nameLength($text, 1))->toBe($length);
})->with([
    'html' => ['<html lang="en">', 4],
    'non-ascii' => ['<é t="x">', 2],
    'colon first' => ['<:x>', 2],
    'prefixed' => ['<h:html/>', 6],
    'digit first' => ['<3 it', 0],
    'end tag' => ['</x>', 0],
    'space' => ['< b', 0],
]);

test('a marker counts only before the offset and outside a skipped span, and stays seen', function () {
    $text = '<p>a</p><html><input>';
    $markers = new HtmlDocumentMarker($text);

    expect($markers->before(8))->toBeFalse()
        ->and($markers->before(strlen($text)))->toBeTrue()
        ->and($markers->before(0))->toBeTrue();

    $skipped = new HtmlDocumentMarker($text);
    $skipped->skip(8, 14);

    expect($skipped->before(strlen($text)))->toBeFalse()
        ->and((new HtmlDocumentMarker('<p>no marker</p>'))->before(16))->toBeFalse();
});

test('a name that starts with : is a tag in the marker walk, so a marker in its attribute does not count', function () {
    expect((new Redactor)->maskValues('<:x t="<html>"><input name="password"><b>COLONSECRET1</b>', []))->not->toContain('COLONSECRET1');
});

test('the text pass keeps ASCII tag names, so a pair inside a non-ASCII tag is still masked', function () {
    expect((new Redactor)->maskValues('<é api-key: NONASCII1SECRET>', []))->not->toContain('NONASCII1SECRET');
});
