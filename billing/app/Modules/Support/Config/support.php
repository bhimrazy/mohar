<?php

/*
| Support module defaults. Every value is overridable at runtime through Settings
| (app(Settings::class)->get('support.<key>')).
*/

return [
    /** Masked ticket number tokens: %A A-Z, %a a-z, %n 0-9, %y %m %d date parts, %i id. */
    'ticket_number_format' => '%n%n%n%n%n%n',

    /** Comma-separated extension allow-lists. */
    'attachment_extensions' => 'jpg,jpeg,png,gif,webp,pdf,txt,log,zip',
    'staff_attachment_extensions' => 'jpg,jpeg,png,gif,webp,pdf,txt,log,zip,doc,docx,xls,xlsx,csv,tar,gz',
    'attachment_max_mb' => 10,
    'attachment_max_files' => 5,
    'prune_attachments_after_months' => 0,

    /** Hours of inactivity before tickets in an auto-close status are closed (0 = off). */
    'auto_close_hours' => 72,
    /** Comma-separated statuses that auto-close / count as "awaiting reply". */
    'auto_close_statuses' => 'answered',
    'awaiting_reply_statuses' => 'open,customer_reply',

    'reply_ratings_enabled' => true,
    'show_clients_only_to_guests' => false,
    'network_status_requires_login' => true,

    /** Inbound email (piping). */
    /** HMAC secret for the inbound webhook; set it in Settings (empty = webhook disabled). */
    'inbound_secret' => '',
    'inbound_max_per_sender' => 10,
    'allow_unverified_email_replies' => false,
    'prevent_email_reopen' => false,
    'default_department_id' => null,
    'reply_marker' => '--- Reply above this line ---',
    'auto_add_cc' => false,
    'inbound_raw_retention_days' => 30,
];
