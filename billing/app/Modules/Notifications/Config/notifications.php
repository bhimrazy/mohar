<?php

/*
 * Notifications defaults (docs/product/notifications.md). Every key is read through
 * Settings::get('notifications.<key>'), so registered settings override these per install.
 */
return [
    // §7 global switches
    'mail_disabled' => false,
    'mail_bcc' => '',
    'client_can_manage_preferences' => true,
    'notify_client_changes' => false,
    'daily_summary' => true,

    // §3 rendering
    'default_locale' => 'en',
    'signature' => '',
    'support_email' => '',

    // Sender per category; empty = MAIL_FROM_ADDRESS. The display name is always core.company_name.
    'from' => ['billing' => '', 'default' => ''],
    'footer_help_text' => 'Questions? Reply to this email or write to {{ support.email }}.',
    'footer_address' => '',
    'support_url' => '',

    // §2 retention
    'email_log_body_retention_days' => 365,

    // §7 transport retries: 3 attempts, then failed
    'mail_max_attempts' => 3,
    'mail_backoff_seconds' => [60, 300, 1800],

    // §8 outbound webhooks
    'webhooks_max_attempts' => 8,
    'webhooks_backoff_seconds' => [60, 300, 1800, 7200, 21600, 43200, 86400],
    'webhooks_disable_after_consecutive_failures' => 20,
    'webhooks_delivery_retention_days' => 30,
    'webhooks_api_version' => '2026-10',
    'webhooks_connect_timeout' => 5,
    'webhooks_timeout' => 10,
    'webhooks_secret_grace_hours' => 24,

    // SSRF guard: never a UI setting, ignored in production. Only for local development against a
    // receiver on this machine (override this config value locally).
    'webhooks_allow_private_targets' => false,
];
