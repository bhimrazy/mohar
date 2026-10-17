<?php

use App\Modules\Support\Models\SpamRule;

test('spam rules match fields case-insensitively', function (string $field, string $match, string $value, bool $expected) {
    $rule = new SpamRule(['field' => $field, 'match' => $match, 'value' => $value]);

    expect($rule->matches('Spammer@Bad.test', 'Cheap PILLS today', 'Buy now'))->toBe($expected);
})->with([
    ['sender', 'equals', 'spammer@bad.test', true],
    ['sender', 'contains', 'bad.test', true],
    ['subject', 'contains', 'pills', true],
    ['subject', 'equals', 'pills', false],
    ['body', 'contains', 'later', false],
    ['body', 'contains', '   ', false],
]);
