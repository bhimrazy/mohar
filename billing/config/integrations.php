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

];
