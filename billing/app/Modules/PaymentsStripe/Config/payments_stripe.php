<?php

/*
| Stripe gateway settings (docs/product/payments-stripe.md). The API host and version are pinned here and never
| taken from user input or provider responses. Test or live mode comes from the gateway's "sandbox" setting and
| the key prefixes; test mode is refused where test money is not allowed (App\Support\Billing\SandboxPolicy).
*/

return [
    'api' => 'https://api.stripe.com/v1/',

    // Pinned per request with the Stripe-Version header; upgrading is a reviewed change with new fixtures.
    'api_version' => '2026-09-30.endive',

    // Checkout's url must be https on exactly this host.
    'checkout_host' => 'checkout.stripe.com',

    // Session lifetime; Stripe accepts 30 minutes to 24 hours. Kept short so an abandoned checkout unlocks soon.
    'session_minutes' => 35,

    // Floor in minor units (0.50 in two-decimal currencies, 50 in JPY/KRW); Stripe enforces its real minimum.
    'min_amount' => 50,

    // Accepted distance between a webhook's signed timestamp and our clock.
    'webhook_tolerance_seconds' => 300,

    'http' => [
        'connect_timeout_seconds' => 5,
        // Background lookups only (never POSTs or the customer's return): retried on connection errors and 5xx.
        'lookup_retry_delays_ms' => [300, 900],
        'interactive_connect_timeout_seconds' => 3,
        'interactive_timeout_seconds' => 6,
    ],
];
