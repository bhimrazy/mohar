<?php

namespace App\Modules\Core;

use App\Contracts\Auth\AuthMailer;
use App\Contracts\Billing\GatewayDirectory;
use App\Contracts\Billing\InvoiceQueries;
use App\Contracts\Branding\BrandProvider;
use App\Contracts\Currencies\CurrencyDirectory;
use App\Contracts\Notifications\EmailTemplates;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Contracts\Notifications\SystemMailer;
use App\Contracts\Orders\PaymentGuard;
use App\Contracts\Orders\ShopperSession;
use App\Contracts\Services\CatalogUsage;
use App\Contracts\Services\ClientServiceDirectory;
use App\Contracts\Services\ProvisioningDrivers;
use App\Contracts\Support\PublicHelpDirectory;
use App\Models\User;
use App\Modules\Core\Import\ClientImporter;
use App\Modules\Core\Import\LoginImporter;
use App\Modules\Core\Install\DefaultCurrencies;
use App\Modules\Core\Install\RegionalDefaults;
use App\Modules\Core\Install\RegionalSettings;
use App\Modules\Core\Install\SystemRoles;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\ClientInvitation;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Core\Support\CachedCurrencyDirectory;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\CoreDashboard;
use App\Modules\Core\Support\CurrencyRollup;
use App\Modules\Core\Support\DateFormats;
use App\Modules\Core\Support\DefaultBrandProvider;
use App\Modules\Core\Support\InMemoryMergeFieldRegistry;
use App\Modules\Core\Support\LaravelAuthMailer;
use App\Modules\Core\Support\NullCatalogUsage;
use App\Modules\Core\Support\NullClientServiceDirectory;
use App\Modules\Core\Support\NullEmailTemplates;
use App\Modules\Core\Support\NullGatewayDirectory;
use App\Modules\Core\Support\NullInvoiceQueries;
use App\Modules\Core\Support\NullPaymentGuard;
use App\Modules\Core\Support\NullProvisioningDrivers;
use App\Modules\Core\Support\NullPublicHelpDirectory;
use App\Modules\Core\Support\NullShopperSession;
use App\Modules\Core\Support\NullSystemMailer;
use App\Modules\Core\Support\Settings;
use App\Support\Dashboard\Dashboard;
use App\Support\Install\ReferenceDataRegistry;
use App\Support\Security\EncryptedColumn;
use App\Support\Security\EncryptedStorage;
use App\Support\Migration\ImportRegistry;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Queues;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\DevCommands;

class CoreServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'core';
    }

    protected function registerModule(): void
    {
        // One currency-table read per request for every dashboard widget that converts or formats money.
        $this->app->scoped(CurrencyRollup::class);

        $this->app->singleton(Settings::class);
        $this->app->singleton(RegionalDefaults::class);
        // The company time zone (ADR 0013) follows the application's until staff set one; the settings form needs a value.
        config(['system.timezone' => config('system.timezone', config('app.timezone', 'UTC'))]);
        $this->app->singleton(ActivityLogger::class);
        $this->app->scoped(ClientContext::class);
        $this->app->singleton(MergeFieldRegistry::class, InMemoryMergeFieldRegistry::class);
        $this->app->scoped(CurrencyDirectory::class, CachedCurrencyDirectory::class);

        // Fallback bindings for optional modules; the owning module re-binds the real implementation.
        $this->app->bindIf(CatalogUsage::class, NullCatalogUsage::class);
        $this->app->bindIf(ProvisioningDrivers::class, NullProvisioningDrivers::class);
        $this->app->bindIf(ClientServiceDirectory::class, NullClientServiceDirectory::class);
        $this->app->bindIf(PaymentGuard::class, NullPaymentGuard::class);
        $this->app->bindIf(GatewayDirectory::class, NullGatewayDirectory::class);
        $this->app->bindIf(InvoiceQueries::class, NullInvoiceQueries::class);
        $this->app->bindIf(BrandProvider::class, DefaultBrandProvider::class);
        $this->app->bindIf(EmailTemplates::class, NullEmailTemplates::class);
        $this->app->bindIf(AuthMailer::class, LaravelAuthMailer::class);
        $this->app->bindIf(SystemMailer::class, NullSystemMailer::class);
        $this->app->bindIf(ShopperSession::class, NullShopperSession::class);
        $this->app->bindIf(PublicHelpDirectory::class, NullPublicHelpDirectory::class);
    }

    protected function schedule(Schedule $schedule): void
    {
        $time = (string) rescue(fn (): mixed => $this->app->make(Settings::class)->get('core.automation_time'), config('core.automation_time', '03:00'), report: false);

        $schedule->command('automation:run')
            ->dailyAt(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : '03:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Scheduler heartbeat for the dashboard (spec §13).
        $schedule->call(fn () => $this->app->make(AutomationStatus::class)->beat())
            ->everyMinute()
            ->name('core.heartbeat')
            ->onOneServer();
    }

    protected function bootModule(): void
    {
        // Encrypted values without an encrypted cast, for `security:reencrypt`: secret settings (a JSON string with the
        // "enc:v1:" prefix) and Fortify's two-factor secret and recovery codes (encrypted by Fortify itself).
        $encrypted = $this->app->make(EncryptedStorage::class);
        $encrypted->register('settings.value', new EncryptedColumn('settings', 'value', 'key', intKey: false, prefix: Settings::ENCRYPTED_PREFIX, json: true));
        $encrypted->register('users.two_factor_secret', new EncryptedColumn('users', 'two_factor_secret'));
        $encrypted->register('users.two_factor_recovery_codes', new EncryptedColumn('users', 'two_factor_recovery_codes'));

        // One dev worker (composer dev) for every queue, in priority order, from the Queues constants (ADR 0014). The
        // automation connection keeps its own worker in Platform.
        DevCommands::artisan('queue:listen --queue='.implode(',', [Queues::PAYMENTS, Queues::PROVISIONING, Queues::REGISTRAR, Queues::NOTIFICATIONS, Queues::WEBHOOKS, Queues::DEFAULT, Queues::IMPORTS]).' --tries=1 --timeout=0', 'queue');

        // Client import (client-import spec §16): Core writes its own records for every import source.
        $imports = $this->app->make(ImportRegistry::class);
        $imports->register('client', ClientImporter::class);
        $imports->register('user', LoginImporter::class, dependsOn: ['client']);

        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Dashboard', 'admin.dashboard', 'LayoutGrid', 'General', 0));
        $navigation->add('admin', new NavItem('Clients', 'admin.clients.index', 'Users', 'Clients', 0, 'core.clients.view', 'admin.clients.*'));
        $navigation->add('client', new NavItem('Dashboard', 'client.dashboard', 'LayoutGrid', 'General', 0));
        $navigation->add('client', new NavItem('Account details', 'client.account.edit', 'UserRound', 'Setup', 90));

        $this->app->make(CoreDashboard::class)->register($this->app->make(Dashboard::class));

        // An open invitation only ever activates a never-used login: once the login gets a password, another email
        // address or two-factor authentication some other way (reset, profile, security settings), its link is void.
        User::saved(function (User $user): void {
            if ($user->wasChanged(['password', 'email', 'two_factor_secret', 'two_factor_confirmed_at'])) {
                ClientInvitation::query()->where('user_id', $user->id)->whereNull('accepted_at')->delete();
            }
        });

        $reference = $this->app->make(ReferenceDataRegistry::class);
        $reference->register(RegionalSettings::KEY, RegionalSettings::class, order: 5);
        $reference->register(DefaultCurrencies::KEY, DefaultCurrencies::class, order: 10);
        $reference->register(SystemRoles::KEY, SystemRoles::class, order: 20);

        $this->app->make(PermissionRegistry::class)->register('Clients', [
            'core.clients.view' => 'View clients',
            'core.clients.create' => 'Create clients',
            'core.clients.update' => 'Edit client profiles',
        ]);

        $this->app->make(SettingsRegistry::class)->section('company', 'Company', [
            new SettingDefinition('core.company_name', 'Company name', rules: ['required', 'max:150']),
            new SettingDefinition('core.company_email', 'Billing email address', 'email', 'Shown on invoices and used as the reply-to for client email.', rules: ['required']),
            new SettingDefinition('core.default_country', 'Default country', help: 'Two-letter ISO code pre-selected for new clients.', rules: ['required', 'size:2', 'alpha']),
            new SettingDefinition('system.timezone', 'Company time zone', 'select', 'What "today" means for due dates and overdue days, and the time zone dates and times are shown in. The daily run still starts at the server time set below. Changing it moves the day boundary once.', options: array_combine(RegionalDefaults::timezones(), RegionalDefaults::timezones()), rules: ['timezone:all']),
            new SettingDefinition('core.date_format', 'Date format', 'select', options: DateFormats::OPTIONS),
            new SettingDefinition('core.automation_time', 'Daily automation time', help: 'Server time (HH:MM) when the daily automation pipeline runs.', rules: ['required', 'date_format:H:i']),
            // Shown in the public site footer (Storefront); legal name, address, phone and tax numbers are the invoice seller details.
            new SettingDefinition('core.support_email', 'Support email address', 'email', 'Shown on the website for questions and "Contact us". Empty uses the billing address.', rules: ['nullable', 'email', 'max:150']),
            new SettingDefinition('core.support_hours', 'Support hours', help: 'Shown in the website footer, e.g. "Sunday–Friday, 9:00–18:00 (Nepal time). Tickets every day."', rules: ['nullable', 'string', 'max:160']),
            new SettingDefinition('core.whatsapp', 'WhatsApp number', help: 'International format, e.g. +977 9800000000. Shown in the website footer as a chat link.', rules: ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{6,30}$/']),
            new SettingDefinition('core.registration_number', 'Company registration number', help: 'Shown in the website footer next to your tax numbers.', rules: ['nullable', 'string', 'max:60']),
            new SettingDefinition('core.social_facebook', 'Facebook page', help: 'An https:// link, shown in the website footer.', rules: ['nullable', 'url:https', 'max:255']),
            new SettingDefinition('core.social_instagram', 'Instagram profile', help: 'An https:// link, shown in the website footer.', rules: ['nullable', 'url:https', 'max:255']),
            new SettingDefinition('core.social_linkedin', 'LinkedIn page', help: 'An https:// link, shown in the website footer.', rules: ['nullable', 'url:https', 'max:255']),
            new SettingDefinition('core.social_x', 'X (Twitter) profile', help: 'An https:// link, shown in the website footer.', rules: ['nullable', 'url:https', 'max:255']),
        ], 'core.settings.update');

        $this->app->make(GlobalSearch::class)->provider('Clients', fn (string $term): array => array_values(Client::query()
            ->search($term)->limit(5)->get()
            ->map(fn (Client $client): array => ['title' => $client->display_name, 'subtitle' => $client->email, 'href' => route('admin.clients.show', $client)])
            ->all()), 'core.clients.view');

        $this->app->make(MergeFieldRegistry::class)->register('client', function (int $id): array {
            $client = Client::query()->findOrFail($id);

            return [
                'client_id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'full_name' => trim($client->first_name.' '.$client->last_name),
                'company_name' => $client->company_name,
                'email' => $client->email,
                'currency' => $client->currency,
                'country' => $client->country,
            ];
        });
    }
}
