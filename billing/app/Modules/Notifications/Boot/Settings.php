<?php

namespace App\Modules\Notifications\Boot;

use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Contracts\Container\Container;

final class Settings
{
    public function __invoke(Container $app): void
    {
        $app->make(SettingsRegistry::class)->section('notifications', 'Email & notifications', [
            new SettingDefinition('notifications.mail_disabled', 'Stop sending email', 'boolean', 'Messages are still logged, with status "suppressed".'),
            new SettingDefinition('notifications.signature', 'Email signature', 'text', 'Inserted where templates use {{ signature }}.', rules: ['max:2000']),
            new SettingDefinition('notifications.footer_help_text', 'Footer help line', help: 'Shown in every email footer. {{ support.email }} is replaced by the support address.', rules: ['max:300']),
            new SettingDefinition('notifications.footer_address', 'Address in the email footer', 'text', 'Defaults to the seller address on invoices.', rules: ['max:500']),
            new SettingDefinition('notifications.support_url', 'Help link in the email footer', help: 'Defaults to the client area ticket list.', rules: ['nullable', 'url', 'max:500']),
            new SettingDefinition('notifications.default_locale', 'Default email language', help: 'Locale code used when a client has no translation, e.g. en.', rules: ['required', 'max:10', 'alpha_dash']),
            new SettingDefinition('notifications.client_can_manage_preferences', 'Clients can manage their email preferences', 'boolean'),
            new SettingDefinition('notifications.notify_client_changes', 'Email staff when clients change their details', 'boolean'),
            new SettingDefinition('notifications.daily_summary', 'Email the daily automation summary', 'boolean'),
            new SettingDefinition('notifications.email_log_body_retention_days', 'Keep email bodies for (days)', 'integer', rules: ['min:1', 'max:3650']),
            new SettingDefinition('notifications.webhooks_max_attempts', 'Webhook attempts before giving up', 'integer', rules: ['min:1', 'max:8']),
            new SettingDefinition('notifications.webhooks_disable_after_consecutive_failures', 'Disable a webhook endpoint after (consecutive failures)', 'integer', rules: ['min:1', 'max:1000']),
            new SettingDefinition('notifications.webhooks_delivery_retention_days', 'Keep webhook deliveries for (days)', 'integer', rules: ['min:1', 'max:365']),
        ], 'notifications.settings.manage');

        // Who mail comes from, where replies go and who gets copies: account-takeover relevant (B1), so a
        // sensitive permission (password confirmation, never granted by wildcards) and audit-logged changes.
        $app->make(SettingsRegistry::class)->section('email_delivery', 'Email delivery', [
            new SettingDefinition('notifications.from.default', 'Send email from', 'email', 'Sender for client and staff email; empty = MAIL_FROM_ADDRESS. The sender name is always the company name. Use an address on a domain your mail provider is authorised to send for (SPF/DKIM), so DMARC passes.', rules: ['nullable', 'email', 'max:255']),
            new SettingDefinition('notifications.from.billing', 'Send billing email from', 'email', 'Sender for invoices, receipts and reminders, e.g. billing@ your domain. Must be on the same authorised sending domain (DMARC alignment).', rules: ['nullable', 'email', 'max:255']),
            new SettingDefinition('notifications.support_email', 'Support email address', 'email', 'Reply-To for general email and the address named in every footer. Defaults to the billing email address. Never a no-reply mailbox.', rules: ['nullable', 'email', 'max:255']),
            new SettingDefinition('notifications.auth_link_host', 'Other host for account links', help: 'Only if reset and verification pages live on another domain than APP_URL (comma-separated host names). One-time links to any other host are never sent.', rules: ['nullable', 'max:255', 'regex:/^\\s*$|^\\s*[a-z0-9.-]+(\\s*,\\s*[a-z0-9.-]+)*\\s*$/i']),
            new SettingDefinition('notifications.mail_bcc', 'BCC every client email to', help: 'Comma-separated addresses. Never applied to mail carrying a reset, verification or invitation link.', rules: ['max:500', 'regex:/^\s*$|^\s*[^@\s,]+@[^@\s,]+\.[^@\s,]+(\s*,\s*[^@\s,]+@[^@\s,]+\.[^@\s,]+)*\s*$/']),
        ], 'notifications.delivery.manage');
    }
}
