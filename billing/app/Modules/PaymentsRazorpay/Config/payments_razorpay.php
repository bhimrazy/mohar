<?php

/*
| Razorpay gateway settings (docs/product/payments-gateways-expansion.md §8). Hosts are pinned here per mode and never
| taken from user input or provider responses. Razorpay uses one API host for both modes and tells them apart by key;
| the gateway's "sandbox" setting picks the mode and the key id must match it. Test mode is refused where test money
| is not allowed (App\Support\Billing\SandboxPolicy).
*/

return [
    'api' => [
        'sandbox' => 'https://api.razorpay.com/v1/',
        'live' => 'https://api.razorpay.com/v1/',
    ],

    // A payment link's short_url must be https on this registrable domain (or the `razorpay.checkout` endpoint's
    // exact host and port in development, ADR 0016 §3).
    'link_domain' => 'rzp.io',

    // Payment link lifetime. Razorpay requires expire_by at least 15 minutes ahead; values below 16 are raised to 16.
    'link_minutes' => 30,

    'min_amount' => 100, // paise (Rs 1), Razorpay's documented minimum

    // A refund we sent but never saw answered, and that the payment's refund list doesn't show, counts as not made
    // only after this long (Razorpay's refund API takes no idempotency key, so the request is never repeated).
    'refund_settle_minutes' => 15,

    'http' => [
        'connect_timeout_seconds' => 5,
        // Background lookups only (never POSTs or the customer's return): retried on connection errors and 5xx.
        'lookup_retry_delays_ms' => [300, 900],
        'interactive_connect_timeout_seconds' => 3,
        'interactive_timeout_seconds' => 6,
    ],
];
