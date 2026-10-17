<?php

/**
 * Domains module defaults. Every value can be overridden at runtime through Settings ("domains.<key>").
 */
return [
    // Renewal invoicing (§4.3)
    'default_auto_renew' => true,
    'invoice_lead_days' => 30,
    'next_due_offset_days' => 0,
    'free_renewal_requires_service' => true,
    'renew_on_payment' => true,
    'enable_renewal_orders' => true,

    // Expiry lifecycle (§4.5)
    'grace_redemption_enabled' => true,

    // Provisioning (§4.2)
    'default_nameservers' => ['ns1.example-host.com', 'ns2.example-host.com'],
    'use_client_contacts' => true,
    'default_contact' => [
        'first_name' => 'Domain',
        'last_name' => 'Admin',
        'company' => 'Hosting Co',
        'email' => 'domains@example.com',
        'phone' => '+1.5555550100',
        'address_1' => '1 Example Way',
        'city' => 'Springfield',
        'state' => 'CA',
        'postcode' => '90210',
        'country' => 'US',
    ],
    'lock_by_default' => true,

    // Registrar sync (§9)
    'sync_enabled' => true,
    'sync_notify_only' => false,
    'sync_batch_size' => 50,
    'sync_interval_hours' => 4,
    'transfer_sync_interval_hours' => 1,
    'transfer_stale_days' => 14,
    'command_log_retention_days' => 90,

    // Reminders (§9)
    'reminder_offsets' => [30, 7, -3],
    'remind_when_auto_renew_off' => true,
    'remind_free_domains' => false,

    // Lookup (§6)
    'allow_idn' => true,
    'lookup_registrar' => 'log',
    'lookup_tlds' => ['.com', '.net', '.org'],
    'lookup_cache_seconds' => 300,
    'suggestion_tlds' => ['.com', '.net', '.org', '.io'],
    'suggestion_limit' => 10,
    'lookup_rate_limit' => 30,

    // Premium names (spec §4.1, COULD): not sold until premium pricing is implemented.
    'premium_enabled' => false,

    // Route name of the Orders cart "add domain" action, used by the public search when present.
    'order_route' => 'store.cart.domains.add',
];
