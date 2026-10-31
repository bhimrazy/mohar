<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

    // Microseconds a sign-in check takes at least, so a wrong password and an unknown user answer alike (SessionGuard).
    'timebox_duration' => (int) env('AUTH_TIMEBOX_DURATION', 200000),

    /*
    | Step-up window: seconds a password confirmation unlocks sensitive mutations behind the "password.recent"
    | middleware (App\Http\Middleware\RequireRecentPasswordConfirmation, ADR 0018 §4). Kept apart from
    | password_timeout, which governs the "password.confirm" pages. AUTH_STEP_UP_SECONDS must be a whole number
    | from 60 to 3600: a shorter window makes confirmations useless, and a longer one turns step-up into a formality.
    | An out-of-range value stops the application at boot instead of being clamped silently.
    */

    'step_up_seconds' => (static function (): int {
        $configured = env('AUTH_STEP_UP_SECONDS');

        // env() turns "true" and "false" into booleans; keep the word so that "false" is rejected, not read as empty.
        if (is_bool($configured)) {
            $configured = $configured ? 'true' : 'false';
        }

        // An empty value (AUTH_STEP_UP_SECONDS= in a file, or a compose pass-through of an unset variable) is unset.
        if ($configured === null || trim((string) $configured) === '') {
            return 900;
        }

        $seconds = filter_var($configured, FILTER_VALIDATE_INT);

        if ($seconds === false || $seconds < 60 || $seconds > 3600) {
            throw new InvalidArgumentException('AUTH_STEP_UP_SECONDS must be a whole number of seconds from 60 to 3600, got "'.$configured.'".');
        }

        return $seconds;
    })(),

];
