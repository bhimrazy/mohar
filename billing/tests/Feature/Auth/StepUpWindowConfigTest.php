<?php

use Illuminate\Support\Facades\Config;

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
    expect(Config::get('auth.step_up_seconds'))->toBe(900);
});

test('a window outside 60 to 3600 seconds, or not a whole number, stops the application at boot', function (string $value) {
    putenv('AUTH_STEP_UP_SECONDS='.$value);

    expect(fn () => stepUpSecondsFromConfigFile())
        ->toThrow(InvalidArgumentException::class, 'AUTH_STEP_UP_SECONDS must be a whole number of seconds from 60 to 3600.');
})->with(['59', '3601', '0', '-5', '900.5', 'soon']);
