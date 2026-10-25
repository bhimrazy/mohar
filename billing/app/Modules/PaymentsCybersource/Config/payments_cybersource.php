<?php

/*
| Cybersource Secure Acceptance Hosted Checkout and REST (docs/product/payments-gateways-expansion.md §6.2), as NIC
| Asia Bank provisions it. Hosts are pinned here per mode and never taken from settings or provider responses; the
| gateway's "sandbox" setting picks the mode and is refused where test money is not allowed (SandboxPolicy).
*/

return [
    'pay' => [
        'sandbox' => 'https://testsecureacceptance.cybersource.com/pay',
        'live' => 'https://secureacceptance.cybersource.com/pay',
    ],
    'rest' => [
        'sandbox' => 'https://apitest.cybersource.com',
        'live' => 'https://api.cybersource.com',
    ],

    // What the bank agreement lets the profile settle in: NPR, plus USD only where the agreement allows it
    // (owner decision Q4). Other invoice currencies need the gateway's "Convert to" set to one of these.
    'currencies' => array_values(array_intersect(['NPR', 'USD'], array_map('trim', explode(',', strtoupper((string) env('CYBERSOURCE_CURRENCIES', 'NPR')))))),

    'min_amount' => 100, // minor units (Rs 1 or $1); the bank enforces its own floor
    'attempt_ttl_minutes' => 60,

    // A refund that a transaction search cannot find this long after it was sent is taken as never made.
    'refund_search_lag_minutes' => 30,

    'http' => [
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
        // Background lookups only (never initiate, refund or the customer's return): retried on connection errors and 5xx.
        'lookup_retry_delays_ms' => [300, 900],
        'interactive_connect_timeout_seconds' => 3,
        'interactive_timeout_seconds' => 6,
    ],
];
