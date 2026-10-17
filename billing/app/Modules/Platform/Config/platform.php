<?php

return [
    /** Activity-log entries without a client are pruned after this many days (spec §8). */
    'activity_log_retention_days' => 365,

    /** Days a staff invitation link stays valid (spec §2: 7 days). */
    'invitation_expiry_days' => 7,

    /** Maximum length of a login-as-client session in minutes (spec §10: 60). */
    'impersonation_minutes' => 60,

    /** Seconds a password confirmation unlocks sensitive actions (step-up authentication). */
    'password_confirmation_seconds' => 900,

    /** Minutes the signed "enter impersonation" link is valid for. */
    'impersonation_link_minutes' => 2,

    /** Tables whose rows lock the default currency once they exist (owned by Billing). */
    'currency_lock_tables' => ['invoices', 'transactions'],
];
