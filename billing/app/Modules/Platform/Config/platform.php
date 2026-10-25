<?php

return [
    /** Activity-log entries without a client are pruned after this many days (spec §8). */
    'activity_log_retention_days' => 365,

    /** Automation runs are deleted after this many days, keeping each task's latest run (#49). */
    'automation_log_retention_days' => 365,

    /** Failed queue jobs and finished job batches are deleted after this many days (#49). */
    'failed_jobs_retention_days' => 30,

    /** Days a staff invitation link stays valid (spec §2: 7 days). */
    'invitation_expiry_days' => 7,

    /** Maximum length of a login-as-client session in minutes (spec §10: 60). */
    'impersonation_minutes' => 60,

    /** Minutes the signed "enter impersonation" link is valid for. */
    'impersonation_link_minutes' => 2,

    /*
     * Exchange rates (spec §6). A fetched rate that moved more than rates_max_change_percent from the rate
     * in force is held for staff review; auto-sourced rates not refreshed for rates_stale_after_days and
     * manual rates not changed for rates_manual_stale_after_days are flagged. nrb_rate_type: mid|buy|sell. rates_update_time ("HH:MM", empty = with the daily automation run) schedules the fetch.
     */
    'rates_max_change_percent' => 10,
    'rates_stale_after_days' => 3,
    'rates_manual_stale_after_days' => 30,
    'nrb_rate_type' => 'mid',
    'rates_update_time' => '',

    'exchange_rates' => [
        'ecb_url' => 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml',
        'nrb_url' => 'https://www.nrb.org.np/api/forex/v1/rates',
        'timeout' => 10,
        'connect_timeout' => 5,
        'max_bytes' => 1_048_576,
        // How long a rate change waits for another one to finish before giving up.
        'lock_wait_seconds' => 15,
    ],
];
