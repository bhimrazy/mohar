<?php

return [
    /*
    | Comma-separated module names to load (Core is always loaded). Empty = all modules and extensions.
    | Extensions are named "<Family>/<Name>" and load only when named: MODULES=Billing,Gateways/Stripe.
    | Useful to run one module's test-suite in isolation: MODULES=Billing php artisan test
    */
    'only' => env('MODULES', ''),
];
