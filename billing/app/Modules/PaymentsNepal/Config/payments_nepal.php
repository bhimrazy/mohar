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
        // Partial refunds through the API stay off until the unit of "amount" (paisa vs rupees) is proven in the
        // Khalti sandbox: the docs say paisa but show 50.00. Full refunds send no amount and are always available.
        // Turning it on is a reviewed code change, made together with the recorded sandbox evidence (spec §8.2).
        'partial_refunds' => false,
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
        // eSewa keeps a booking payable 50 minutes after the form post (UAT bookingExpiryTime, 2026-10-21), even
        // though its docs say payment must follow login within 15 minutes. Values below 55 are raised to 55.
        'attempt_ttl_minutes' => 60,
    ],

    // Hosts are not in NCHL's public docs: these are the ones community integrations use, to confirm at onboarding
    // (spec §4.3, Q2). Paths are NCHL's documented ones.
    'connectips' => [
        'gateway' => [
            'sandbox' => 'https://uat.connectips.com/connectipswebgw',
            'live' => 'https://login.connectips.com/connectipswebgw',
        ],
        'api' => [
            'sandbox' => 'https://uat.connectips.com/connectipswebws/api/creditor',
            'live' => 'https://login.connectips.com/connectipswebws/api/creditor',
        ],
        'min_amount' => 100, // paisa (Rs 1)
        'max_amount' => 200_000_000, // paisa (Rs 2,000,000), NCHL's per-transaction limit for web payments
        'attempt_ttl_minutes' => 30, // to confirm in UAT; values below 15 are raised to 15
    ],

    'http' => [
        'connect_timeout_seconds' => 5,
        // Background lookups only (never initiate, refund or the customer's return): retried on connection errors
        // and 5xx after these delays.
        'lookup_retry_delays_ms' => [300, 900],
        // The return route's single lookup while the customer waits.
        'interactive_connect_timeout_seconds' => 3,
        'interactive_timeout_seconds' => 6,
    ],
];
