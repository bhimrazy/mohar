<?php

return [
    'csv' => [
        // Upload limits (client-import spec §5.1); a file over one is refused before anything is stored.
        'max_bytes' => 20 * 1024 * 1024,
        'max_rows' => 100_000,
        'max_columns' => 200,
    ],
    // Days an upload that was never applied keeps its rows before they are purged (§5.8).
    'staging_days' => 7,
    // Days an applied import can be undone; its row payloads are purged afterwards (§5.7).
    'undo_days' => 7,
    // Most import invitations sent in any hour, install-wide (§5.7); 50,000 logins take about four days at 500.
    'invite_per_hour' => 500,
];
