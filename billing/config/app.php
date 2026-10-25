<?php

/*
 * APP_KEY and APP_PREVIOUS_KEYS come from the environment, or from the file `<NAME>_FILE` names (Docker and Compose
 * secrets under /run/secrets, systemd credentials, Kubernetes secret volumes); a relative path is taken from the
 * application directory. A non-empty variable wins. Each file is read once, when the configuration is loaded or cached.
 */
$secretFile = static function (string $name): ?string {
    $path = (string) env($name.'_FILE');

    return $path === '' ? null : (str_starts_with($path, '/') ? $path : base_path($path));
};
$readSecret = static function (?string $path): ?string {
    $contents = $path !== null && is_file($path) && is_readable($path) ? trim((string) file_get_contents($path)) : '';

    return $contents === '' ? null : $contents;
};
$appKey = (string) env('APP_KEY') !== '' ? (string) env('APP_KEY') : $readSecret($secretFile('APP_KEY'));
$previousKeys = (string) env('APP_PREVIOUS_KEYS') !== '' ? (string) env('APP_PREVIOUS_KEYS') : $readSecret($secretFile('APP_PREVIOUS_KEYS'));

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Mohar'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    // See docs/devops/key-rotation.md. Previous keys are separated by commas or new lines.
    'key' => $appKey,

    'previous_keys' => [
        ...array_values(array_filter(preg_split('/[\s,]+/', (string) $previousKeys) ?: [])),
    ],

    // For the install check: where the key came from ('env', 'file' or null), and the files named by *_FILE.
    'key_source' => (string) env('APP_KEY') !== '' ? 'env' : ($appKey !== null ? 'file' : null),

    'key_file' => $secretFile('APP_KEY'),

    'previous_keys_file' => $secretFile('APP_PREVIOUS_KEYS'),

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
