<?php

/*
| Provider endpoints for the Nepal gateway drivers (docs/product/payments-nepal.md §2). Hosts are pinned here per
| mode and never taken from user input or provider responses. A gateway's "sandbox" setting picks the mode;
| sandbox mode is refused where test money is not allowed (App\Support\Billing\SandboxPolicy).
*/

return [
    'khalti' => [
        'api' => [
            'sandbox' => 'https://dev.khalti.com/api/v2/',
            'live' => 'https://khalti.com/api/v2/',
        ],
        'refund' => [
            'sandbox' => 'https://dev.khalti.com/api/merchant-transaction/',
            'live' => 'https://khalti.com/api/merchant-transaction/',
        ],
        // payment_url returned by initiate must be https on one of these registrable domains.
        'payment_domain' => 'khalti.com',
        'min_amount' => 1000, // paisa (Rs 10), Khalti's documented minimum
        'attempt_ttl_minutes' => 60, // Khalti's production default link lifetime
    ],

    'esewa' => [
        'form' => [
            'sandbox' => 'https://rc-epay.esewa.com.np/api/epay/main/v2/form',
            'live' => 'https://epay.esewa.com.np/api/epay/main/v2/form',
        ],
        'status' => [
            'sandbox' => 'https://rc.esewa.com.np/api/epay/transaction/status/',
            'live' => 'https://esewa.com.np/api/epay/transaction/status/',
        ],
        'min_amount' => 100, // paisa (Rs 1); eSewa publishes no minimum
        'attempt_ttl_minutes' => 15, // "payment must be made within 15 minutes of login"
    ],

    'http' => [
        'connect_timeout_seconds' => 5,
        // Lookups only (never initiate or refund): retried on connection errors and 5xx after these delays.
        'lookup_retry_delays_ms' => [300, 900],
    ],
];
