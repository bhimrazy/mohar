<?php

return [
    /** Days a cart lives after its last change before orders:prune-carts deletes it. */
    'cart_ttl_days' => 7,

    /** Require the terms of service checkbox at checkout. */
    'require_tos' => true,

    /** Link shown next to the terms checkbox (empty = no link). */
    'tos_url' => '',

    /** Due date of the first invoice = order date + N days. */
    'initial_invoice_grace_days' => 0,

    /** Where checkout sends the customer: complete_page | invoice | gateway (falls back to the complete page when no invoice page exists). */
    'checkout_redirect' => 'invoice',

    /** Move a pending order to active when its invoice is paid and the fraud check passed. */
    'auto_accept_on_payment' => false,

    /** Refuse a hosting domain that is already attached to a pending/active order item. */
    'block_duplicate_domains' => true,

    /** Require an EPP/authorization code for domain transfers. */
    'transfer_requires_epp' => true,

    /** Whether domain registration/transfer lines are taxable. */
    'domains_taxable' => true,

    /** Let customers leave notes at checkout. */
    'allow_checkout_notes' => true,

    /** Cancel pending orders whose invoice is still unpaid after N days (0 = off). */
    'auto_cancel_unpaid_days' => 0,

    /** When true, items of clients without an active service wait for staff acceptance. */
    'auto_provision_only_existing_clients' => false,

    /** Create the order invoice without emailing it (the confirmation mail still goes out). */
    'suppress_invoice_email_on_order' => false,

    /**
     * Payment gateways offered on the order form (key => label). Billing owns gateway configuration;
     * until it exposes a contract, the order form offers this list.
     */
    'gateways' => [
        'bank_transfer' => 'Bank transfer',
        'card' => 'Credit or debit card',
        'paypal' => 'PayPal',
    ],

    /** Referral code cookie set from ?ref=CODE on public pages. */
    'referral_cookie' => 'orders_ref',
    'referral_cookie_days' => 90,

    'fraud' => [
        /** none | rules */
        'driver' => 'rules',
        'max_pending_orders' => 3,
        'max_orders_per_ip' => 5,
        /** Comma separated, e.g. "mailinator.com, example.org". */
        'banned_email_domains' => '',
        'skip_for_existing_clients' => true,
        /** Treat checker errors as "review" (true) or "fail" (false). */
        'fail_open' => true,
        'timeout_ms' => 5000,
    ],
];
