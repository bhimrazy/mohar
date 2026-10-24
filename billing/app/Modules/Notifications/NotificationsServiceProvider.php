<?php

namespace App\Modules\Notifications;

use App\Contracts\Auth\AuthMailer;
use App\Contracts\Notifications\EmailTemplates;
use App\Contracts\Notifications\SystemMailer;
use App\Models\User;
use App\Modules\Notifications\Actions\PruneNotificationLogs;
use App\Modules\Notifications\Enums\EmailStatus;
use App\Modules\Notifications\Install\DefaultTemplates;
use App\Modules\Notifications\Listeners\BrandOutgoingMail;
use App\Modules\Notifications\Listeners\PublishEventWebhooks;
use App\Modules\Notifications\Listeners\SendEventEmails;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\WebhookEndpoint;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Notifications\Services\TemplatedAuthMailer;
use App\Modules\Notifications\Services\TemplateDirectory;
use App\Modules\Notifications\Services\TemplatedSystemMailer;
use App\Modules\Notifications\Support\SecretTemplatePolicy;
use App\Modules\Notifications\Webhooks\HostResolver;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use App\Support\Events\WebhookEvents;
use App\Support\Install\ReferenceDataRegistry;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Security\EncryptedModel;
use App\Support\Security\EncryptedStorage;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    /** Permissions that can exfiltrate data or hijack mail (BCC on password resets, arbitrary webhook URLs). */
    public const SENSITIVE_PERMISSIONS = ['notifications.templates.manage', 'notifications.webhooks.manage', 'notifications.delivery.manage'];

    public function moduleKey(): string
    {
        return 'notifications';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(TemplateCatalog::class);
        $this->app->bind(TemplateEngine::class);
        $this->app->singleton(HostResolver::class);
        $this->app->bind(EmailTemplates::class, TemplateDirectory::class);
        $this->app->bind(AuthMailer::class, TemplatedAuthMailer::class);
        $this->app->bind(SystemMailer::class, TemplatedSystemMailer::class);
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('notifications:retry-webhooks')->everyMinute()->withoutOverlapping()->onOneServer();
    }

    protected function bootModule(): void
    {
        // Encrypted casts, for `security:reencrypt` (ADR 0022).
        $encrypted = $this->app->make(EncryptedStorage::class);
        $encrypted->register('notifications_webhook_endpoints', new EncryptedModel(WebhookEndpoint::class));

        // Framework and module notifications (password reset fallback, security notices, invitations) get the
        // company sender name and the support Reply-To too; templated mail sets its own per category.
        Event::listen(MessageSending::class, [BrandOutgoingMail::class, 'handle']);

        foreach (array_keys(SendEventEmails::EVENTS) as $event) {
            Event::listen($event, [SendEventEmails::class, 'handle']);
        }

        // After every provider has booted, so events registered by sibling modules and packages are included.
        $this->app->booted(function (): void {
            foreach ($this->app->make(WebhookEvents::class)->events() as $event) {
                Event::listen($event, [PublishEventWebhooks::class, 'handle']);
            }
        });

        $this->registerNavigation();
        $this->registerDashboard();
        $this->registerPermissions();
        $this->registerSettings();

        $this->app->make(ReferenceDataRegistry::class)->register(DefaultTemplates::KEY, DefaultTemplates::class, order: 100);

        $this->app->make(Automation::class)->task('notifications.prune', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => app(PruneNotificationLogs::class)->handle($today));
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Email templates', 'admin.notifications.templates.index', 'Mail', 'Setup', 40, 'notifications.templates.view', 'admin.notifications.templates.*'));
        $navigation->add('admin', new NavItem('Email log', 'admin.notifications.emails.index', 'Inbox', 'Setup', 91, 'notifications.log.view', 'admin.notifications.emails.*'));
        $navigation->add('admin', new NavItem('Staff email alerts', 'admin.notifications.subscriptions.edit', 'UserCog', 'Setup', 41, 'notifications.subscriptions.manage', 'admin.notifications.subscriptions.*'));
        $navigation->add('admin', new NavItem('Webhooks', 'admin.notifications.webhooks.index', 'Webhook', 'Setup', 42, 'notifications.webhooks.manage', 'admin.notifications.webhooks.*'));
        $navigation->add('admin-client', new NavItem('Emails', 'admin.notifications.emails.index', 'Mail', order: 80, permission: 'notifications.log.view'));

        $navigation->add('client', new NavItem('Email preferences', 'client.notifications.preferences.edit', 'Mail', 'Setup', 91, null, 'client.notifications.preferences.*'));
        $navigation->add('client', new NavItem('Email history', 'client.notifications.emails.index', 'Inbox', 'Setup', 92, null, 'client.notifications.emails.*'));
    }

    private function registerDashboard(): void
    {
        $dashboard = $this->app->make(Dashboard::class);

        $dashboard->todo('admin', 'notifications.failed_emails', fn (?User $user): array => [
            'label' => 'Emails that failed in the last 24 hours',
            'count' => EmailMessage::query()->where('status', EmailStatus::Failed)->where('updated_at', '>=', now()->subDay())->count(),
            'href' => route('admin.notifications.emails.index', ['status' => EmailStatus::Failed->value]),
            'tone' => 'danger',
        ], 'notifications.log.view', 40);

        $dashboard->todo('admin', 'notifications.disabled_webhooks', fn (?User $user): array => [
            'label' => 'Disabled webhook endpoints',
            'count' => WebhookEndpoint::query()->where('is_active', false)->whereNotNull('disabled_reason')->count(),
            'href' => route('admin.notifications.webhooks.index'),
            'tone' => 'warning',
        ], 'notifications.webhooks.manage', 41);

        // Account mail fails closed when APP_URL can't carry account links (e.g. http:// in production): say so.
        $dashboard->health('admin', 'notifications.account_links', function (?User $user): array {
            $problem = SecretTemplatePolicy::accountLinkProblem();

            return [
                'label' => 'Account email',
                'detail' => $problem ?? 'Reset and verification links use '.config('app.url'),
                'value' => $problem === null ? 'OK' : 'Held',
                'tone' => $problem === null ? 'success' : 'danger',
                'icon' => 'Mail',
            ];
        }, 'notifications.log.view', 60);
    }

    private function registerPermissions(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Notifications', [
            'notifications.templates.view' => 'View and preview email templates',
            'notifications.templates.manage' => 'Edit, create and delete email templates',
            'notifications.log.view' => 'View the email log',
            'notifications.log.resend' => 'Resend emails from the log',
            'notifications.send' => 'Send emails to clients',
            'notifications.subscriptions.manage' => 'Choose which staff roles receive which alerts',
            'notifications.webhooks.manage' => 'Manage webhook endpoints, secrets and deliveries',
            'notifications.delivery.manage' => 'Change email senders, Reply-To, global BCC and template CC/BCC',
            'notifications.settings.manage' => 'Manage email & notification settings',
        ], sensitive: self::SENSITIVE_PERMISSIONS);
    }

    private function registerSettings(): void
    {
        $this->app->make(SettingsRegistry::class)->section('notifications', 'Email & notifications', [
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
        $this->app->make(SettingsRegistry::class)->section('email_delivery', 'Email delivery', [
            new SettingDefinition('notifications.from.default', 'Send email from', 'email', 'Sender for client and staff email; empty = MAIL_FROM_ADDRESS. The sender name is always the company name. Use an address on a domain your mail provider is authorised to send for (SPF/DKIM), so DMARC passes.', rules: ['nullable', 'email', 'max:255']),
            new SettingDefinition('notifications.from.billing', 'Send billing email from', 'email', 'Sender for invoices, receipts and reminders, e.g. billing@ your domain. Must be on the same authorised sending domain (DMARC alignment).', rules: ['nullable', 'email', 'max:255']),
            new SettingDefinition('notifications.support_email', 'Support email address', 'email', 'Reply-To for general email and the address named in every footer. Defaults to the billing email address. Never a no-reply mailbox.', rules: ['nullable', 'email', 'max:255']),
            new SettingDefinition('notifications.auth_link_host', 'Other host for account links', help: 'Only if reset and verification pages live on another domain than APP_URL (comma-separated host names). One-time links to any other host are never sent.', rules: ['nullable', 'max:255', 'regex:/^\\s*$|^\\s*[a-z0-9.-]+(\\s*,\\s*[a-z0-9.-]+)*\\s*$/i']),
            new SettingDefinition('notifications.mail_bcc', 'BCC every client email to', help: 'Comma-separated addresses. Never applied to mail carrying a reset, verification or invitation link.', rules: ['max:500', 'regex:/^\s*$|^\s*[^@\s,]+@[^@\s,]+\.[^@\s,]+(\s*,\s*[^@\s,]+@[^@\s,]+\.[^@\s,]+)*\s*$/']),
        ], 'notifications.delivery.manage');
    }
}
