<?php

namespace App\Modules\Platform;

use App\Events\Core\ExchangeRatesUpdated;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Core\Support\Like;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Drivers\ExchangeRates\EcbRateSource;
use App\Modules\Platform\Drivers\ExchangeRates\NrbRateSource;
use App\Modules\Platform\Http\Middleware\EnforceLoginLockout;
use App\Modules\Platform\Http\Middleware\EnforceStaffSecurity;
use App\Modules\Platform\Http\Middleware\EnsureActiveUser;
use App\Modules\Platform\Http\Middleware\HandleImpersonation;
use App\Modules\Platform\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Modules\Platform\Jobs\ConvertPricesAfterRateChange;
use App\Modules\Platform\Listeners\RecordAuthenticationActivity;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Services\ExchangeRateStatus;
use App\Modules\Platform\Services\Impersonation;
use App\Modules\Platform\Services\PermissionGuard;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Modules\Platform\Support\ExchangeRateTask;
use App\Modules\Platform\Support\PlatformDashboard;
use App\Support\Automation\Automation;
use App\Support\Clients\ClientMergeRegistry;
use App\Support\Currencies\ExchangeRateSources;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class PlatformServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'platform';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(PermissionGuard::class);
        $this->app->singleton(SettingsCatalog::class);
        $this->app->scoped(Impersonation::class);
    }

    protected function bootModule(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('password.recent', RequireRecentPasswordConfirmation::class);

        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', EnsureActiveUser::class);
        $kernel->appendMiddlewareToGroup('web', EnforceLoginLockout::class);
        $kernel->appendMiddlewareToGroup('web', HandleImpersonation::class);
        $kernel->appendMiddlewareToGroup('web', EnforceStaffSecurity::class);

        Event::listen(Login::class, [RecordAuthenticationActivity::class, 'login']);
        Event::listen(Failed::class, [RecordAuthenticationActivity::class, 'failed']);
        Event::listen(Logout::class, [RecordAuthenticationActivity::class, 'logout']);

        $this->registerNavigation();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerSearch();
        $this->registerMergeParticipants();
        $this->registerAutomation();
        $this->registerExchangeRates();
        $this->app->make(PlatformDashboard::class)->register($this->app->make(Dashboard::class));

        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin-create', new NavItem('New client', 'admin.clients.create', 'UserPlus', order: 10, permission: 'core.clients.create'));
        $navigation->add('admin-create', new NavItem('Invite staff', 'admin.platform.staff.create', 'UserCog', order: 90, permission: 'core.staff.manage'));
    }

    /**
     * Rate sources, the daily rate update and its "Needs attention" items (spec §6).
     */
    private function registerExchangeRates(): void
    {
        $sources = $this->app->make(ExchangeRateSources::class);
        $sources->register(EcbRateSource::class);
        $sources->register(NrbRateSource::class);

        // One queued, unique conversion per rate change; modules' daily convert-prices tasks are the backstop.
        Event::listen(ExchangeRatesUpdated::class, fn (ExchangeRatesUpdated $event) => ConvertPricesAfterRateChange::dispatch($event->currencies));

        $this->app->make(Automation::class)->task(ExchangeRateTask::KEY, Automation::EXCHANGE_RATES, fn (): string => $this->app->make(ExchangeRateTask::class)->pipeline());

        $dashboard = $this->app->make(Dashboard::class);
        $dashboard->todo('admin', 'platform.exchange_rates.review', fn (): array => [
            'label' => 'Exchange-rate changes waiting for approval',
            'count' => ExchangeRateReview::query()->pending()->count(),
            'href' => route('admin.platform.currencies.index'),
            'tone' => 'warning',
        ], 'core.currencies.rates', 30);
        $dashboard->todo('admin', 'platform.exchange_rates.stale', fn (): array => [
            'label' => 'Exchange rates not updated recently',
            'count' => $this->app->make(ExchangeRateStatus::class)->staleQuery()->count(),
            'href' => route('admin.platform.currencies.index'),
            'tone' => 'warning',
        ], 'core.currencies.manage', 31);
    }

    /**
     * A separate daily rate fetch when platform.rates_update_time is set (the pipeline task then skips; see
     * ExchangeRateTask). It has its own exchange-rate lock, not the automation:run one.
     */
    protected function schedule(Schedule $schedule): void
    {
        $time = $this->app->make(ExchangeRateTask::class)->separateTime();

        if ($time !== null) {
            $schedule->command('platform:update-exchange-rates')
                ->dailyAt($time)
                ->name(ExchangeRateTask::KEY)
                ->withoutOverlapping()
                ->onOneServer();
        }
    }

    /**
     * Daily housekeeping tasks (spec §13).
     */
    private function registerAutomation(): void
    {
        $automation = $this->app->make(Automation::class);

        $automation->task('platform.prune_invitations', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): string => trans_choice(
            '{0} no invitations pruned|{1} 1 invitation pruned|[2,*] :count invitations pruned',
            StaffInvitation::query()
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->whereNull('accepted_at')->where('expires_at', '<', $today->subDays(30)))
                    ->orWhere(fn ($query) => $query->whereNotNull('accepted_at')->where('accepted_at', '<', $today->subDays(90))))
                ->delete(),
        ));

        $automation->task('platform.prune_activity_log', Automation::HOUSEKEEPING, function (CarbonImmutable $today): string {
            $days = max(30, (int) $this->app->make(Settings::class)->get('platform.activity_log_retention_days', 365));

            // Entries tied to a client are kept until the client is deleted (spec §8).
            $count = ChunkedDelete::run(DB::table('activity_log')->whereNull('client_id')->where('created_at', '<', $today->subDays($days)));

            return trans_choice('{0} no entries pruned|{1} 1 entry pruned|[2,*] :count entries pruned', $count);
        });
    }

    /**
     * Core-owned rows the merge moves itself; other modules register their own participants.
     */
    private function registerMergeParticipants(): void
    {
        $merge = $this->app->make(ClientMergeRegistry::class);
        $merge->participant('core.contacts', fn (int $sourceId): array => [
            'label' => 'Contacts',
            'count' => Contact::query()->where('client_id', $sourceId)->count(),
        ]);
        $merge->participant('core.logins', fn (int $sourceId): array => [
            'label' => 'Logins (become non-owner users)',
            'count' => DB::table('client_user')->where('client_id', $sourceId)->count(),
        ]);
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Activity log', 'admin.platform.activity.index', 'Activity', 'General', 50, 'core.activity_log.view', 'admin.platform.activity.*'));
        $navigation->add('admin', new NavItem('Staff', 'admin.platform.staff.index', 'UserCog', 'Setup', 10, 'core.staff.view', 'admin.platform.staff.*'));
        $navigation->add('admin', new NavItem('Roles', 'admin.platform.roles.index', 'Shield', 'Setup', 20, 'core.roles.manage', 'admin.platform.roles.*'));
        $navigation->add('admin', new NavItem('Currencies', 'admin.platform.currencies.index', 'Coins', 'Setup', 30, 'core.currencies.manage', 'admin.platform.currencies.*'));
        $navigation->add('admin', new NavItem('Client groups', 'admin.platform.client-groups.index', 'Tag', 'Clients', 20, 'core.client_groups.manage', 'admin.platform.client-groups.*'));
        $navigation->add('admin', new NavItem('Settings', 'admin.platform.settings.edit', 'Settings', 'Setup', 80, 'core.settings.view', 'admin.platform.settings.*'));
        $navigation->add('admin', new NavItem('Automation', 'admin.platform.automation.index', 'Timer', 'Setup', 90, 'core.automation.view', 'admin.platform.automation.*'));
    }

    private function registerPermissions(): void
    {
        $permissions = $this->app->make(PermissionRegistry::class);

        $permissions->register('Clients', [
            'core.clients.close' => 'Close and reopen clients',
            'core.clients.merge' => 'Merge clients',
            'core.clients.impersonate' => 'Sign in as a client',
            'core.contacts.manage' => 'Manage client contacts',
            'core.users.manage' => 'Manage client logins',
            'core.users.reset_2fa' => 'Reset a client login\'s two-factor authentication',
        ], sensitive: ['core.clients.impersonate', 'core.clients.merge', 'core.clients.close', 'core.users.*']);

        $permissions->register('General', [
            'core.activity_log.view' => 'View the activity log',
        ]);

        $permissions->register('Setup', [
            'core.staff.view' => 'View staff',
            'core.staff.manage' => 'Invite, edit and deactivate staff',
            'core.roles.manage' => 'Manage roles and permissions',
            'core.currencies.manage' => 'Manage currencies and how amounts are displayed',
            'core.currencies.rates' => 'Change exchange rates, rate sources and converted prices',
            'core.client_groups.manage' => 'Manage client groups',
            'core.settings.view' => 'View settings',
            'core.settings.update' => 'Change general settings',
            'core.settings.security' => 'Change security settings',
            'core.automation.view' => 'View the automation log',
            'core.automation.run' => 'Run automation manually',
        ], sensitive: ['core.staff.*', 'core.roles.*', 'core.settings.security', 'core.currencies.rates']);
    }

    private function registerSettings(): void
    {
        $this->app->make(SettingsRegistry::class)->section('exchange_rates', 'Exchange rates', [
            new SettingDefinition('platform.rates_max_change_percent', 'Largest automatic daily change (%)', 'integer', 'A fetched rate that moved more than this from the rate in force waits for staff approval. 0 turns the check off.', rules: ['min:0', 'max:100']),
            new SettingDefinition('platform.rates_stale_after_days', 'Warn when automatic rates are older than (days)', 'integer', rules: ['min:1', 'max:60']),
            new SettingDefinition('platform.rates_manual_stale_after_days', 'Warn when manual rates are older than (days)', 'integer', 'Manual rates that nobody has changed for this long are flagged for review.', rules: ['min:1', 'max:365']),
            new SettingDefinition('platform.nrb_rate_type', 'Nepal Rastra Bank rate to use', 'select', 'NRB publishes a buying and a selling rate for each currency.', options: NrbRateSource::RATE_TYPES),
            new SettingDefinition('platform.rates_update_time', 'Rate update time', help: 'Server time (HH:MM) to fetch rates each day. Leave empty to fetch with the daily automation run.', rules: ['nullable', 'date_format:H:i']),
        ], 'core.currencies.rates');

        $this->app->make(SettingsRegistry::class)->section('security', 'Security', [
            new SettingDefinition('security.staff_2fa_required', 'Require two-factor authentication for staff', 'boolean', 'Staff without 2FA must set it up before they can use the admin area.'),
            new SettingDefinition('security.login_max_attempts', 'Failed sign-ins before lockout', 'integer', 'Per email address and IP.', rules: ['min:3', 'max:20']),
            new SettingDefinition('security.login_lockout_minutes', 'Lockout duration (minutes)', 'integer', rules: ['min:1', 'max:1440']),
            new SettingDefinition('security.login_ip_allowlist', 'IP addresses exempt from lockout', 'text', 'One IP or CIDR range per line.', rules: ['max:2000']),
            new SettingDefinition('platform.impersonation_minutes', 'Sign-in-as-client time limit (minutes)', 'integer', 'Staff are returned to the admin area after this long.', rules: ['min:5', 'max:240']),
            new SettingDefinition('platform.invitation_expiry_days', 'Staff invitation validity (days)', 'integer', rules: ['min:1', 'max:30']),
            // Shortening retention prunes staff and security events, so it is a security setting.
            new SettingDefinition('platform.activity_log_retention_days', 'Activity log retention (days)', 'integer', 'Entries not tied to a client are pruned after this many days.', rules: ['min:30', 'max:3650']),
        ], 'core.settings.security');
    }

    private function registerSearch(): void
    {
        $search = $this->app->make(GlobalSearch::class);

        $search->provider('Contacts', fn (string $term): array => array_values(Contact::query()
            ->with('client:id,first_name,last_name,company_name')
            ->where(function ($query) use ($term): void {
                Like::where($query, 'email', Like::startsWith($term));
                Like::where($query, 'first_name', Like::startsWith($term), 'or');
                Like::where($query, 'last_name', Like::startsWith($term), 'or');
            })
            ->orderBy('first_name')->orderBy('id')->limit(5)->get()
            ->map(fn (Contact $contact): array => [
                'title' => trim("{$contact->first_name} {$contact->last_name}"),
                'subtitle' => $contact->email.' · '.$contact->client->display_name,
                'href' => route('admin.clients.show', $contact->client_id),
            ])->all()), 'core.clients.view');

        $search->provider('Staff', fn (string $term): array => array_values(User::query()
            ->where('is_staff', true)
            ->where(function ($query) use ($term): void {
                Like::where($query, 'name', Like::startsWith($term));
                Like::where($query, 'email', Like::startsWith($term), 'or');
            })
            ->orderBy('name')->orderBy('id')->limit(5)->get()
            ->map(fn (User $user): array => [
                'title' => $user->name,
                'subtitle' => $user->email,
                'href' => route('admin.platform.staff.edit', $user),
            ])->all()), 'core.staff.manage');
    }
}
