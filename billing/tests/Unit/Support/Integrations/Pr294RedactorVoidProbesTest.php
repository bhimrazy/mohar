<?php

use App\Support\Integrations\Redactor;

/*
 * Review probes for #294 (#278). B1 must pass before merge; L1 to L3 are low-severity gaps next to the fix; M1 covers `input` used as an XML element name.
 * The HTML exemption applies only when an HTML document marker precedes the element.
 */

test('B1: an HTML head void element with a secret name keeps the page after it readable (develop does)', function (string $text, string $kept) {
    expect((new Redactor)->maskValues($text, []))->toContain($kept);
})->with([
    'meta csrf-token before the title' => ["<html><head><meta name=\"csrf-token\" content=\"x\">\n<title>Gateway error</title></head><body>Order 1234 declined</body>", 'Order 1234 declined'],
    'meta session-timeout' => ['<!DOCTYPE html><html><head><meta name="session-timeout" content="30"><p>KEEP1</p>', 'KEEP1'],
]);

test('L1 to L3: a child after a blank CDATA, an underscore-named child or a stray end tag is still masked', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'L1 blank cdata then child' => ['<param name="password"><![CDATA[]]><value>CD1SECRET', 'CD1SECRET'],
    'L2 underscore child name' => ['<param name="password"><_v>US1SECRET', 'US1SECRET'],
    'L3 stray end tag before the child' => ['<param name="password"></x><value>END1SECRET', 'END1SECRET'],
]);

test('M1: an XML element named input with a secret name and an unclosed child masks the child', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'truncated soap' => ['<soap:Body><Login><input name="password"><string>INP3SECRET', 'INP3SECRET'],
    'xml-rpc shape' => ['<input name="password"><value><string>INP2SECRET', 'INP2SECRET'],
]);
