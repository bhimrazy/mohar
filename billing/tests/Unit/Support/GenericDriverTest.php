<?php

use App\Modules\Support\Drivers\Inbound\GenericDriver;

test('the generic driver normalises addresses, headers and attachments', function () {
    $mail = (new GenericDriver)->parse([
        'message_id' => '<a@b>',
        'from' => '"Jane Doe" <Jane@Example.com>',
        'to' => 'Help@Support.test, other@x.test',
        'cc' => [['email' => 'Boss@X.test', 'name' => 'Boss']],
        'subject' => '  Hello  ',
        'text' => 'Body',
        'headers' => ['Auto-Submitted' => 'no', 'References' => '<r1@x> <r2@x>'],
        'attachments' => [['filename' => 'a.txt', 'content' => base64_encode('hi')], ['filename' => 'bad', 'content' => '%%%']],
    ]);

    expect($mail)->fromEmail->toBe('jane@example.com')->fromName->toBe('Jane Doe')
        ->to->toBe(['help@support.test', 'other@x.test'])->cc->toBe(['boss@x.test'])
        ->subject->toBe('Hello')->references->toBe(['<r1@x>', '<r2@x>'])
        ->and($mail->header('AUTO-SUBMITTED'))->toBe('no')
        ->and($mail->attachments)->toBe([['name' => 'a.txt', 'content' => 'hi']])
        ->and($mail->dedupeKey())->toBe('<a@b>');
});

test('the dedupe key falls back to a hash of from, date and subject', function () {
    $driver = new GenericDriver;
    $a = $driver->parse(['from' => 'x@y.z', 'subject' => 'S', 'date' => 'D']);
    $b = $driver->parse(['from' => 'X@Y.Z', 'subject' => 'S', 'date' => 'D']);

    expect($a->dedupeKey())->toStartWith('hash:')->toBe($b->dedupeKey());
});

test('signatures are HMAC-SHA256 of the raw body', function () {
    expect(GenericDriver::sign('{}', 'secret'))->toBe('sha256='.hash_hmac('sha256', '{}', 'secret'));
});

test('the driver reads the provider authentication verdict', function (array $payload, ?bool $expected) {
    expect((new GenericDriver)->parse(['from' => 'a@b.c', ...$payload])->authenticated)->toBe($expected);
})->with([
    'dmarc pass' => [['auth' => ['dmarc' => 'pass']], true],
    'spf and dkim pass' => [['auth' => ['spf' => 'pass', 'dkim' => 'pass']], true],
    'spf pass only' => [['auth' => ['spf' => 'pass']], null],
    'dmarc fail' => [['auth' => ['dmarc' => 'fail']], false],
    'header pass' => [['headers' => ['Authentication-Results' => 'mx.example; spf=pass smtp.mailfrom=a; dkim=pass header.d=x; dmarc=pass']], true],
    'header fail' => [['headers' => ['Authentication-Results' => 'mx; spf=softfail; dkim=none']], false],
    'unknown' => [[], null],
]);

test('control characters are stripped from subjects', function () {
    expect((new GenericDriver)->parse(['from' => 'a@b.c', 'subject' => "Hello\r\nBcc: evil@x"])->subject)->toBe('Hello Bcc: evil@x');
});
