<?php

return [
    // Settings → Reports. A UTF-8 byte order mark makes Excel on Windows read non-English text correctly.
    'csv_bom' => false,

    // A synchronous CSV is streamed up to this many rows; a larger one must be narrowed (ADR 0025 §3).
    'sync_export_max_rows' => 5000,

    // A PDF is built in memory (about 70 KB per row), so its limit is lower than the CSV's.
    'sync_pdf_max_rows' => 1000,
];
