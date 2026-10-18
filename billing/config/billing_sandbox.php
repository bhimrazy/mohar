<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing sandbox gateway
    |--------------------------------------------------------------------------
    |
    | The simulated "sandbox_card" gateway lets anyone pay without money moving. Null (default) enables it
    | everywhere except production; set BILLING_SANDBOX_ENABLED=true/false to force it. This is a deployment
    | switch on purpose: it is not a runtime setting staff can change.
    |
    */

    // The same switch allows provider sandbox modes (Khalti dev.khalti.com, eSewa UAT) through
    // App\Support\Billing\SandboxPolicy: forcing it on in production (e.g. a demo host) lets those run there too.
    'enabled' => env('BILLING_SANDBOX_ENABLED'),

];
