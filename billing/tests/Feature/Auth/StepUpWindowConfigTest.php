<?php

use App\Http\Middleware\RequireRecentPasswordConfirmation;

afterEach(fn () => putenv('AUTH_STEP_UP_SECONDS'));

/** Evaluates config/auth.php again, so the env reading and its bounds run for real. */
function stepUpSecondsFromConfigFile(): int
{
    return (require base_path('config/auth.php'))['step_up_seconds'];
}

test('the step-up window defaults to 900 seconds and reads AUTH_STEP_UP_SECONDS when set', function () {
    putenv('AUTH_STEP_UP_SECONDS');
    expect(stepUpSecondsFromConfigFile())->toBe(900);

    putenv('AUTH_STEP_UP_SECONDS=1800');
    expect(stepUpSecondsFromConfigFile())->toBe(1800);
});

test('an empty AUTH_STEP_UP_SECONDS counts as unset and uses 900 seconds', function () {
    putenv('AUTH_STEP_UP_SECONDS=');

    expect(stepUpSecondsFromConfigFile())->toBe(900);
});

test('the sensitive-action middleware honours the configured window', function () {
    config(['auth.step_up_seconds' => 1800]);

    expect(RequireRecentPasswordConfirmation::timeout())->toBe(1800);
});

test('the window bounds 60 and 3600 seconds are accepted', function (string $value) {
    putenv('AUTH_STEP_UP_SECONDS='.$value);

    expect(stepUpSecondsFromConfigFile())->toBe((int) $value);
})->with(['60', '3600']);

test('a window outside 60 to 3600 seconds, or not a whole number, stops the application at boot and names the value', function (string $value) {
    putenv('AUTH_STEP_UP_SECONDS='.$value);

    expect(fn () => stepUpSecondsFromConfigFile())
        ->toThrow(InvalidArgumentException::class, 'AUTH_STEP_UP_SECONDS must be a whole number of seconds from 60 to 3600, got "'.$value.'".');
})->with(['59', '3601', '0', '-5', '900.5', 'soon', 'false']);
