<?php

/**
 * Services module defaults. Runtime overrides are stored through Core Settings
 * (e.g. app(Settings::class)->get('services.suspend.days_overdue')).
 */
return [
    'renewals' => [
        /** Days before the start of the next period that its renewal invoice is generated. */
        'days_before_due' => 14,
        /** Per-cycle override of days_before_due (null = use the default). */
        'lead_days' => [
            'monthly' => null,
            'quarterly' => null,
            'semiannually' => null,
            'annually' => null,
            'biennially' => null,
            'triennially' => null,
        ],
        /** Generate the next renewal even while an earlier renewal is still unpaid (one period per run). */
        'continuous' => false,
    ],

    'suspend' => [
        'enabled' => true,
        'days_overdue' => 7,
    ],

    'terminate' => [
        'enabled' => false,
        'days_overdue' => 30,
    ],

    'unsuspend_on_payment' => true,

    'cancellation' => [
        'client_can_request' => true,
        'cancel_unpaid_invoices' => true,
        'auto_process' => true,
    ],

    'upgrades' => [
        'credit_on_downgrade' => false,
        'unpaid_expiry_days' => 7,
        'charge_setup_fee' => false,
    ],

    'module' => [
        'max_attempts' => 3,
        /** Seconds to wait before attempt 2, 3, 4, ... */
        'backoff' => [60, 300, 900],
        /** Seconds a command waits for another command on the same service to finish. */
        'lock_wait_seconds' => 10,
        /**
         * A command job may run this long (driver HTTP timeouts must be shorter). Invariant:
         * job_timeout_seconds < lock_ttl_seconds < queue retry_after (90 s by default).
         */
        'job_timeout_seconds' => 60,
        'lock_ttl_seconds' => 80,
        /** Times a job waiting for the per-service lock is re-queued before it is reported as failed. */
        'lock_retries' => 20,
    ],

    'module_log' => [
        'retention_days' => 30,
    ],

    'random_usernames' => false,
];
