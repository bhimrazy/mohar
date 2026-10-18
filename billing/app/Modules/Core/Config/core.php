<?php

return [
    // Defaults to the product name (APP_NAME) until the operator sets their own in Settings → Company.
    'company_name' => config('app.name'),
    'company_email' => 'billing@example.com',
    'default_country' => 'US',
    'date_format' => 'Y-m-d',
    'automation_time' => '03:00',
];
