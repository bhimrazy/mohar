<?php

return [
    // Defaults to the product name (APP_NAME) until the operator sets their own in Settings → Company.
    'company_name' => config('app.name'),
    'company_email' => 'billing@example.com',
    'default_country' => 'US',
    'date_format' => 'M j, Y', // one of App\Modules\Core\Support\DateFormats::OPTIONS
    'automation_time' => '03:00',
    // Public website footer (Settings → Company). Empty values are not shown.
    'support_hours' => '',
    'whatsapp' => '',
    'registration_number' => '',
    'social_facebook' => '',
    'social_instagram' => '',
    'social_linkedin' => '',
    'social_x' => '',
];
