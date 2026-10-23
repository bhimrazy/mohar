<?php

return [
    // Settings → Reports. A UTF-8 byte order mark makes Excel on Windows read non-English text correctly.
    'csv_bom' => false,

    // A synchronous export is streamed up to this many rows; a larger one must be narrowed (ADR 0025 §3).
    'sync_export_max_rows' => 5000,
];
