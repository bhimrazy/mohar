<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Addresses or CIDR ranges of the reverse proxies in front of the app, comma separated (for the production
    | compose stack: the internal network's range). Only requests arriving from these addresses may set the
    | client IP, scheme, host and port through X-Forwarded-* headers. Unset means no proxy is trusted, which is
    | right when nothing sits in front of the app. Never use "*" on a network others can reach: any client could
    | then spoof its IP (login lockout, activity log) and claim HTTPS.
    |
    | Illuminate\Http\Middleware\TrustProxies reads this key on every request, so it works with a cached config.
    | The trusted headers are set in bootstrap/app.php.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
