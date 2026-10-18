<?php

use App\Modules\Support\Services\QuoteStripper;

test('text above the reply marker is kept', function () {
    $text = "Thanks, fixed!\n\n--- Reply above this line ---\nOld ticket content";

    expect((new QuoteStripper)->strip($text, '--- Reply above this line ---'))->toBe('Thanks, fixed!');
});

test('common quote headers and quoted lines are removed', function (string $text) {
    expect((new QuoteStripper)->strip($text, ''))->toBe('New text');
})->with([
    'gmail' => ["New text\n\nOn Tue, Oct 13, 2026 at 10:00 AM Support <help@x.test> wrote:\n> previous"],
    'outlook' => ["New text\r\n\r\n-----Original Message-----\r\nFrom: Support"],
    'trailing quotes' => ["New text\n> quoted\n> more"],
]);
