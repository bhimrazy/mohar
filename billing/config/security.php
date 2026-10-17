<?php

return [
    /** Staff without confirmed two-factor authentication must enrol before using the admin area. */
    'staff_2fa_required' => (bool) env('STAFF_2FA_REQUIRED', true),
];
