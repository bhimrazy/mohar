<?php

/*
| Billing defaults. Every value can be overridden at runtime through Settings
| (app(Settings::class)->get('billing.<key>')). Money values are integer minor units.
*/

return [
    // Numbering (§3)
    'number_format' => '{NUMBER}',
    'number_padding' => 0,
    'number_increment' => 1,
    'number_reset' => 'never', // never|yearly|monthly
    'sequential_paid_numbering' => false,
    'proforma_format' => 'PF-{NUMBER}',
    'set_issue_date_on_payment' => true,
    'quote_number_format' => 'Q-{NUMBER}',
    'invoice_days_before_due' => 14,

    // Credit & add funds (§4.4, §4.5)
    'credit' => [
        'auto_apply_on_generate' => true,
        'tax_add_funds' => false,
        'min_deposit' => 1000,
        'max_deposit' => 100000,
        'max_balance' => 200000,
        'require_accepted_order' => true,
    ],
    'mass_pay_enabled' => true,
    'clients_choose_gateway' => true,
    'allow_pay_method_removal' => true,

    // Hosted gateways (payments-nepal.md §3): lookup-driven reconciliation
    'gateways' => [
        'lookup_interval_minutes' => 5,
        'lookup_grace_minutes' => 120,
        'http_timeout_seconds' => 15,
        'ambiguous_alert_minutes' => 30,
    ],

    // Refunds & reversals (§4.7, §4.8)
    'reversal' => [
        'set_collections' => true,
        'rollback_due_dates' => true,
    ],

    // Tax (§5)
    'tax' => [
        'enabled' => false,
        'mode' => 'exclusive', // exclusive|inclusive
        'compound' => false,
        'calculation' => 'per_line', // per_line|per_invoice
        'rounding' => 'half_up', // half_up|half_even|down|up
        'apply_to' => ['service', 'addon', 'domain', 'setup', 'late_fee', 'manual'],
        // Seller location for intra/inter-state rules (India GST: CGST+SGST in the home state, IGST elsewhere).
        'home_country' => 'IN',
        'home_state' => 'MH',
    ],
    'vat' => [
        'enabled' => false,
        'home_country' => 'DE',
        'auto_exempt_on_valid_id' => true,
    ],

    // Automation (§7)
    'late_fee' => [
        'type' => 'percent', // percent|fixed
        'amount' => 0, // percent value, or minor units when fixed; 0 = off
        'minimum' => 0,
        'days_overdue' => 7,
        'base' => 'balance', // balance|total
    ],
    'capture' => [
        'days_before_due' => 1,
        'retry_days' => [3, 7],
        'max_failures_per_pay_method' => 3,
    ],
    'reminders' => [
        'before_due_days' => 7,
        'overdue_days' => [1, 4, 7],
    ],
    'auto_cancel' => [
        'days_overdue' => 0,
    ],

    // Quotes (§8)
    'quotes' => [
        'require_tos' => false,
        'auto_invoice_on_accept' => true,
        'valid_days' => 30,
    ],

    // PDFs (§9)
    'pdf' => [
        'attach_to_emails' => true,
        'paper' => 'A4',
    ],
];
