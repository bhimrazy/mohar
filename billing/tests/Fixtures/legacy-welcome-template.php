<?php

/*
 * client.welcome as shipped before default tracking (PR #28): an install that never edited it holds this text.
 */

return [
    'subject' => 'Welcome to {{ company.name }}',
    'preheader' => null,
    'heading' => null,
    'body' => '# Welcome, {{ client.first_name }}

Your {{ company.name }} account is ready.

From the client area you can order services, pay invoices, manage domains and contact our team.

[Open the client area]({{ system.client_area_url }})

{% if signature %}{{ signature }}{% endif %}',
    'action_label' => null,
];
