<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound connections to staff-entered hosts (ADR 0015)
    |--------------------------------------------------------------------------
    |
    | One policy for the whole install, read by App\Support\Integrations\OutboundAddressGuard. Drivers never choose
    | it. Private ranges (RFC 1918, CGNAT, IPv6 ULA) are allowed by default because a control panel on the billing
    | server's LAN is normal; a multi-tenant (SaaS) install turns them off. Loopback is allowed only in local
    | development.
    |
    */

    'outbound' => [
        'allow_private_addresses' => (bool) env('OUTBOUND_ALLOW_PRIVATE_ADDRESSES', true),
        'allow_loopback' => (bool) env('OUTBOUND_ALLOW_LOOPBACK', env('APP_ENV') === 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint overrides for local simulators (ADR 0016)
    |--------------------------------------------------------------------------
    |
    | Read by App\Support\Integrations\Endpoints. Development and test only: a JSON map of endpoint key => base URL
    | (for example {"stripe.api": "https://127.0.0.1:8443"}) and the CA bundle that signs the simulator's certificate.
    | Ignored, and logged as critical, when the app runs in production. Never a database setting.
    |
    */

    'endpoints' => [
        'overrides' => json_decode((string) env('INTEGRATION_ENDPOINTS', ''), true) ?: [],
        'ca_bundle' => env('INTEGRATION_CA_BUNDLE'),
    ],

];
