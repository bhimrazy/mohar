<?php

return [
    /** Off on fresh installs (owner, 2026-10-21); hosts turn it on in Admin → Settings → Identity verification. */
    'enabled' => false,

    /** Prefix added to a phone number entered without one. */
    'phone_country_code' => '+977',

    /** Largest image a client may upload, in kilobytes. */
    'max_upload_kb' => 5120,

    /** Days documents are kept after the client account is closed (owner, 2026-10-21). */
    'retention_days' => 90,

    /** Most documents the daily retention task deletes in one run; the rest wait for the next run. */
    'prune_batch' => 500,
];
