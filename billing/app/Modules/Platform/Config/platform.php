<?php

return [
    /** Days a staff invitation link stays valid (spec §2: 7 days). */
    'invitation_expiry_days' => 7,

    /** Maximum length of a login-as-client session in minutes (spec §10: 60). */
    'impersonation_minutes' => 60,

    /** Minutes the signed "enter impersonation" link is valid for. */
    'impersonation_link_minutes' => 2,

    /** Tables whose rows lock the default currency once they exist (owned by Billing). */
    'currency_lock_tables' => ['invoices', 'transactions'],

    /** Tables with a client_id column that a client merge must not re-point. */
    'merge_excluded_tables' => ['clients', 'client_user', 'activity_log', 'impersonation_sessions'],
];
