<?php

use App\Modules\Catalog\Support\RegexMatcher;

it('matches delimited and bare patterns', function () {
    expect(RegexMatcher::matches('/^[a-z]+$/i', 'Hello'))->toBeTrue()
        ->and(RegexMatcher::matches('^[a-z]+$', 'hello'))->toBeTrue()
        ->and(RegexMatcher::matches('^[a-z]+$', 'hello1'))->toBeFalse()
        ->and(RegexMatcher::matches('a~b', 'xa~by'))->toBeTrue();
});

it('detects invalid patterns', function () {
    expect(RegexMatcher::isValid('/[a-z/'))->toBeFalse()
        ->and(RegexMatcher::isValid('(unclosed'))->toBeFalse()
        ->and(RegexMatcher::isValid('/^\d{3}$/'))->toBeTrue();
});

it('never matches when the pattern backtracks catastrophically', function () {
    expect(RegexMatcher::matches('/^(a+)+$/', str_repeat('a', 5000).'b'))->toBeFalse();
});
