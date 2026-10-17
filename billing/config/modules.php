<?php

return [
    /*
    | Comma-separated module names to load (Core is always loaded). Empty = all modules.
    | Useful to run one module's test-suite in isolation: MODULES=Billing php artisan test
    */
    'only' => env('MODULES', ''),
];
