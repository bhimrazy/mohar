<?php

return [
    /** Staff without confirmed two-factor authentication must enrol before using the admin area. */
    'staff_2fa_required' => (bool) env('STAFF_2FA_REQUIRED', true),

    /** Failed sign-ins for one email + IP before that pair is locked out (spec §4). */
    'login_max_attempts' => 5,

    /** How long a locked email + IP pair stays locked. */
    'login_lockout_minutes' => 15,

    /** IPs / CIDR ranges exempt from the lockout (newline or comma separated). */
    'login_ip_allowlist' => '',

    /** Visitors may create a client account themselves (/register and sign-in providers; not checkout). */
    'client_signup_enabled' => true,
];
