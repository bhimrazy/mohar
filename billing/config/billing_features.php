<?php

return [
    // Nepal VAT mode (ADR 0024): hidden until the old paths issue notes (VAT PR 3b). Config only, not a stored setting.
    'nepal_vat_mode' => (bool) env('BILLING_NEPAL_VAT_MODE', false),
];
