<?php

/*
| Switches for unfinished work, per module: `features.<module>.<flag>`, env `FEATURE_<MODULE>_<FLAG>`, off by default.
| Config only, never a stored setting. The PR that finishes the work deletes its flag and every check of it.
| A flag hides the screen and its routes; it does not undo data the feature already wrote (architecture.md, Feature flags).
*/
return [
    'billing' => [
        // Nepal VAT mode settings page (ADR 0024). Lifts when PR 4 merges. With the flag off while the mode is on, the mode
        // stays in effect (classification, locked numbering): only the page that changes it is hidden.
        'nepal_vat_mode' => (bool) env('FEATURE_BILLING_NEPAL_VAT_MODE', false),
    ],
];
