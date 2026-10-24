<?php

/*
 * HTML void element names (`<input name="password">`): next-tag bound in an HTML document, fail closed anywhere else
 * (#278). Folded from the review probes of #294 and #252 (#311); a test name keeps its probe id (B1, N2, N4, ...).
 */

use App\Support\Integrations\Redactor;

test('an unclosed void-named container masks its child content to the end of the text (#278)', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'truncated rpc' => ["<methodCall><params>\n<param name=\"password\">\n  <value><string>TRUNC1SECRET", 'TRUNC1SECRET'],
    'closed child after the last end tag' => ['<param name="password"><value>B1</value></param> <param name="password"><value>B2SECRET</value>', 'B2SECRET'],
    'mixed case' => ['<PARAM Name="Password"><VALUE>MIX1SECRET</VALUE>', 'MIX1SECRET'],
    'extra attributes' => ['<param id="p1" name="password" type="x"><value>ATTR1SECRET', 'ATTR1SECRET'],
    'nested unclosed' => ['<param name="password"><param name="user"><value>NEST1SECRET', 'NEST1SECRET'],
    'comment before the child' => ['<param name="password"><!-- note --><value>COM1SECRET', 'COM1SECRET'],
    'cdata after a comment' => ['<param name="password"><!-- note --><![CDATA[CDA1SECRET]]>', 'CDA1SECRET'],
    'unterminated comment' => ['<param name="password"><!-- <value>UNT1SECRET', 'UNT1SECRET'],
    'input as an xml element' => ['<soap:Body><input name="password"><string>INP1SECRET', 'INP1SECRET'],
    'marker in a comment' => ['<!-- <html> --><input name="password"><value>S01SECRET', 'S01SECRET'],
    'marker in an unterminated comment' => ['<!-- <html><input name="password"><value>S01BSECRET', 'S01BSECRET'],
    'marker in CDATA' => ['<x><![CDATA[<html>]]></x><input name="password"><value>S02SECRET', 'S02SECRET'],
    'marker in an attribute value' => ['<a title="<html>"/><input name="password"><value>S03SECRET', 'S03SECRET'],
    'marker in an unterminated attribute value' => ['<a title="<html><input name="password"><value>S03BSECRET', 'S03BSECRET'],
    'marker in a masked secret value' => ['<password><html>pw</password><input name="password"><value>S04SECRET', 'S04SECRET'],
    'end tag of html is not a marker' => ['</html><input name="password"><value>S06SECRET', 'S06SECRET'],
    'soap body is not a marker' => ['<Envelope xmlns="urn:x"><Body><input name="password"><string>S20SECRET', 'S20SECRET'],
    'xml body element' => ['<?xml version="1.0"?><message><body>hi</body><param name="password"><value><string>S21SECRET', 'S21SECRET'],
    'marker in a processing instruction with >' => ['<?pi a > <html ?><input name="password"><value>S07SECRET', 'S07SECRET'],
    'marker in an unterminated processing instruction' => ['<?pi <html><input name="password"><value>S08SECRET', 'S08SECRET'],
    'marker in a declaration' => ['<!ENTITY x "<html>"><input name="password"><value>S09SECRET', 'S09SECRET'],
    'unterminated cdata' => ['<![CDATA[ <html> <input name="password"><value>S10SECRET', 'S10SECRET'],
    'prefixed and near-miss names' => ['<h:html/><htmlx/><html-x/><head><body><input name="password"><value>S11SECRET', 'S11SECRET'],
    'upper-case input' => ['<INPUT name="password"><value>INP2SECRET', 'INP2SECRET'],
    'header is not an html marker' => ['<header/><input name="password"><b>INP3SECRET', 'INP3SECRET'],
    'html marker only after the element' => ['<input name="password"><b>INP4SECRET</b><html>', 'INP4SECRET'],
    'other void names' => ['<source name="token"><value>SRC1SECRET', 'SRC1SECRET'],
]);

test('a void-named element that is not a container keeps what follows it (#278)', function (string $text, string $kept) {
    expect((new Redactor)->maskValues($text, []))->toContain($kept);
})->with([
    'self-closing' => ['<param name="password"/><value>KEEP1</value>', 'KEEP1'],
    'self-closing with a space' => ['<param name="password" /><value>KEEP2</value>', 'KEEP2'],
    'non-secret name' => ['<param name="domain"><value>KEEP3</value>', 'KEEP3'],
    'html input before a paragraph' => ['<HTML><body><input name="password"><p>KEEP4</p>', 'KEEP4'],
    'html marker after a closed comment' => ['<!-- c --><html lang="en"><meta name="csrf-token" content="x"><p>KEEP7</p>', 'KEEP7'],
    'doctype with a line break' => ["<!DOCTYPE\nhtml><meta name=\"csrf-token\"><p>KEEP8</p>", 'KEEP8'],
    'stray end tag then text' => ['<param name="password"></x>KEEP6<value>', 'KEEP6'],
    'empty at the end of the input' => ['<param name="password">', '<param name="password">'],
    'closed by its parent' => ['<a><param name="password"></a>KEEP5', 'KEEP5'],
]);

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

test('N2: a marker that is not an HTML document tag keeps the element failing closed', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'marker in a comment' => ['<!-- <html> --><input name="password"><value>S01', 'S01'],
    'marker in CDATA' => ['<x><![CDATA[<html>]]></x><input name="password"><value>S02', 'S02'],
    'marker in an attribute value' => ['<a title="<html>"/><input name="password"><value>S03', 'S03'],
    'marker in a masked secret value' => ['<password><html>pw</password><input name="password"><value>S04', 'S04'],
    'default-namespace SOAP Body' => ['<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/"><Body><Login><input name="password"><string>S20', 'S20'],
    'XML body element before XML-RPC' => ['<?xml version="1.0"?><message><body>hi</body><param name="password"><value><string>S21', 'S21'],
]);

test('N4: an html tag inside a processing instruction that contains > is not a marker', function () {
    expect((new Redactor)->maskValues('<?pi a > <html ?><input name="password"><value>PI1SECRET', []))->not->toContain('PI1SECRET');
});

test('N6: a less-than sign in text does not hide a later html marker', function (string $text) {
    expect((new Redactor)->maskValues($text, []))->toContain('KEEP');
})->with([
    'spaced less-than' => ['a < b <html><input name="password"><p>KEEP</p>'],
    'heart' => ['I <3 it <html><input name="password"><p>KEEP</p>'],
]);

test('residual L5: an unclosed void-named container masks its child content (#278)', function (string $text, string $secret) {
    expect((new Redactor)->maskValues($text, []))->not->toContain($secret);
})->with([
    'truncated rpc' => ["<methodCall><params>\n<param name=\"password\">\n  <value><string>TRUNC1SECRET", 'TRUNC1SECRET'],
    'after the last end tag' => ['<param name="password"><value>B1</value></param> <param name="password"><value>B2SECRET</value>', 'B2SECRET'],
]);
