<?php

// Review probe for PR #558: outside the test environment no env value may shorten the SessionGuard timebox below
// Laravel's 200 ms (password confirmation and the non-two-factor sign-in path use it).

function pr558AuthConfigWith(array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return require config_path('auth.php');
    } finally {
        foreach ($saved as $key => [$env, $server, $put]) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($put !== false) {
                putenv("{$key}={$put}");
            }
        }
    }
}

test('production ignores an AUTH_TIMEBOX_DURATION below the 200 ms default', function (string $value) {
    $config = pr558AuthConfigWith(['APP_ENV' => 'production', 'AUTH_TIMEBOX_DURATION' => $value]);

    expect($config['timebox_duration'] ?? 200000)->toBeGreaterThanOrEqual(200000);
})->with(['0', '1', '-5', 'abc']);
