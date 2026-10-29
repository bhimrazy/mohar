<?php

namespace App\Modules\Platform;

use App\Contracts\Auth\ClientLoginInvitations;
use App\Contracts\Core\CompanyClock;
use App\Events\Core\ExchangeRatesUpdated;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Core\Support\Like;
use App\Modules\Core\Support\RetentionRunDate;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Actions\Housekeeping\PruneSystemTables;
use App\Modules\Platform\Drivers\ExchangeRates\EcbRateSource;
use App\Modules\Platform\Drivers\ExchangeRates\NrbRateSource;
use App\Modules\Platform\Http\Middleware\EnforceLoginLockout;
use App\Modules\Platform\Http\Middleware\EnforceStaffSecurity;
use App\Modules\Platform\Http\Middleware\EnsureActiveUser;
use App\Modules\Platform\Http\Middleware\HandleImpersonation;
use App\Modules\Platform\Jobs\ConvertPricesAfterRateChange;
use App\Modules\Platform\Listeners\RecordAuthenticationActivity;
use App\Modules\Platform\Models\ExchangeRateReview;
use App\Modules\Platform\Models\StaffInvitation;
use App\Modules\Platform\Services\ExchangeRateStatus;
use App\Modules\Platform\Services\Impersonation;
use App\Modules\Platform\Services\PermissionGuard;
use App\Modules\Platform\Services\SettingsCatalog;
use App\Modules\Platform\Support\AccountMail;
use App\Modules\Platform\Support\ExchangeRateTask;
use App\Modules\Platform\Support\MailServerSettings;
use App\Modules\Platform\Support\PlatformClientLoginInvitations;
use App\Modules\Platform\Support\PlatformDashboard;
use App\Support\Automation\Automation;
use App\Support\CalendarDate;
use App\Support\Clients\ClientMergeRegistry;
use App\Support\Currencies\ExchangeRateSources;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Queues;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\DevCommands;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobProcessing;
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
        $this->app->scoped(AccountMail::class);
        $this->app->singleton(MailServerSettings::class);
        $this->app->bind(ClientLoginInvitations::class, PlatformClientLoginInvitations::class);
    }

    protected function bootModule(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', EnsureActiveUser::class);
        $kernel->appendMiddlewareToGroup('web', EnforceLoginLockout::class);
        $kernel->appendMiddlewareToGroup('web', HandleImpersonation::class);
        $kernel->appendMiddlewareToGroup('web', EnforceStaffSecurity::class);

        Event::listen(Login::class, [RecordAuthenticationActivity::class, 'login']);
        Event::listen(Failed::class, [RecordAuthenticationActivity::class, 'failed']);
        Event::listen(Logout::class, [RecordAuthenticationActivity::class, 'logout']);

        // "Run now" jobs run on their own connection (App\Support\Queues); the dev runner must consume it too.
        DevCommands::artisan('queue:listen '.Queues::AUTOMATION.' --queue='.Queues::AUTOMATION.' --tries=1 --timeout=0', 'automation-queue');
        // Without it a local copy reports the scheduler as stopped and the daily run never starts.
        DevCommands::artisan('schedule:work', 'scheduler');

        $this->registerNavigation();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerMailServer();
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

        $this->app->make(Automation::class)->task(ExchangeRateTask::KEY, Automation::EXCHANGE_RATES, fn (): string => $this->app->make(ExchangeRateTask::class)->pipeline(),
            label: 'Update exchange rates', description: 'Fetches the day\'s exchange rates from the configured provider, or records that they are fetched at their own time.');

        $dashboard = $this->app->make(Dashboard::class);
        $dashboard->todo('admin', 'platform.exchange_rates.review', fn (): array => [
            'label' => 'Rate changes to approve',
            'count' => ExchangeRateReview::query()->pending()->count(),
            'href' => route('admin.platform.currencies.index'),
            'tone' => 'warning',
            'icon' => 'Coins',
        ], 'core.currencies.rates', 30);
        $dashboard->todo('admin', 'platform.exchange_rates.stale', fn (): array => [
            'label' => 'Exchange rates out of date',
            'count' => $this->app->make(ExchangeRateStatus::class)->staleQuery()->count(),
            'href' => route('admin.platform.currencies.index'),
            'tone' => 'warning',
            'icon' => 'Coins',
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
                ->timezone((string) rescue(fn (): string => $this->app->make(CompanyClock::class)->timezone(), 'UTC', report: false))
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
        // Retention cutoffs compare timestamps, so they start at the company midnight; a future run date counts from today.
        $cutoff = fn (CalendarDate $today, int $days): CarbonImmutable => $this->app->make(CompanyClock::class)->dayBounds(RetentionRunDate::of($today)->addDays(-$days))[0];

        $automation->task('platform.prune_invitations', Automation::HOUSEKEEPING, fn (CalendarDate $today): string => trans_choice(
            '{0} no invitations pruned|{1} 1 invitation pruned|[2,*] :count invitations pruned',
            StaffInvitation::query()
                ->where(fn ($query) => $query
                    ->where(fn ($query) => $query->whereNull('accepted_at')->where('expires_at', '<', $cutoff($today, 30)))
                    ->orWhere(fn ($query) => $query->whereNotNull('accepted_at')->where('accepted_at', '<', $cutoff($today, 90))))
                ->delete(),
        ), label: 'Delete old staff invitations', description: 'Removes expired and used staff invitations.');

        $automation->task('platform.prune_activity_log', Automation::HOUSEKEEPING, function (CalendarDate $today) use ($cutoff): string {
            $days = max(30, (int) $this->app->make(Settings::class)->get('platform.activity_log_retention_days', 365));

            // Entries tied to a client are kept until the client is deleted (spec §8).
            $count = ChunkedDelete::run(DB::table('activity_log')->whereNull('client_id')->where('created_at', '<', $cutoff($today, $days)));

            return trans_choice('{0} no entries pruned|{1} 1 entry pruned|[2,*] :count entries pruned', $count);
        }, label: 'Prune the activity log', description: 'Deletes activity log entries older than the retention period (client entries are kept).');

        $system = fn (): PruneSystemTables => $this->app->make(PruneSystemTables::class);
        $automation->task('platform.prune_automation_log', Automation::HOUSEKEEPING, fn (CalendarDate $today): int => $system()->automationLog(RetentionRunDate::of($today)),
            label: 'Prune the automation log', description: 'Deletes automation runs older than the retention period; each task keeps its latest run.');
        $automation->task('platform.prune_queue_tables', Automation::HOUSEKEEPING, fn (CalendarDate $today): int => $system()->queueTables(RetentionRunDate::of($today)),
            label: 'Delete old failed jobs', description: 'Deletes failed background jobs, and finished job batches, older than the retention period.');
        $automation->task('platform.prune_cache', Automation::HOUSEKEEPING, fn (): int => $system()->expiredCache(),
            label: 'Delete expired cache entries', description: 'Removes expired entries and locks from the database cache.');
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
        $navigation->add('admin', new NavItem('Roles', 'admin.platform.roles.index', 'Shield', 'Setup', 11, 'core.roles.manage', 'admin.platform.roles.*'));
        $navigation->add('admin', new NavItem('Currencies', 'admin.platform.currencies.index', 'Coins', 'Setup', 30, 'core.currencies.manage', 'admin.platform.currencies.*'));
        $navigation->add('admin', new NavItem('Client groups', 'admin.platform.client-groups.index', 'Tag', 'Clients', 20, 'core.client_groups.manage', 'admin.platform.client-groups.*'));
        $navigation->add('admin', new NavItem('Settings', 'admin.platform.settings.edit', 'Settings', 'Setup', 5, 'core.settings.view', 'admin.platform.settings.*'));
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
            'core.settings.mail' => 'Change the mail server and send test email',
            'core.automation.view' => 'View the automation log',
            'core.automation.run' => 'Run automation manually',
        ], sensitive: ['core.staff.*', 'core.roles.*', 'core.settings.security', 'core.settings.mail', 'core.currencies.rates']);
    }

    private function registerSettings(): void
    {
        $this->app->make(SettingsRegistry::class)->section('exchange_rates', 'Exchange rates', [
            new SettingDefinition('platform.rates_max_change_percent', 'Largest automatic daily change (%)', 'integer', 'A fetched rate that moved more than this from the rate in force waits for staff approval. 0 turns the check off.', rules: ['min:0', 'max:100']),
            new SettingDefinition('platform.rates_stale_after_days', 'Warn when automatic rates are older than (days)', 'integer', rules: ['min:1', 'max:60']),
            new SettingDefinition('platform.rates_manual_stale_after_days', 'Warn when manual rates are older than (days)', 'integer', 'Manual rates that nobody has changed for this long are flagged for review.', rules: ['min:1', 'max:365']),
            new SettingDefinition('platform.nrb_rate_type', 'Nepal Rastra Bank rate to use', 'select', 'NRB publishes a buying and a selling rate for each currency.', options: NrbRateSource::RATE_TYPES),
            new SettingDefinition('platform.rates_update_time', 'Rate update time', help: 'Time of day (24-hour HH:MM, company time) to fetch rates, e.g. 17:00 after ECB publishes. Leave empty to fetch with the daily automation run.', rules: ['nullable', 'date_format:H:i']),
        ], 'core.currencies.rates');

        $this->app->make(SettingsRegistry::class)->section('security', 'Security', [
            new SettingDefinition('security.staff_2fa_required', 'Require two-factor authentication for staff', 'boolean', 'Staff without 2FA must set it up before they can use the admin area.'),
            new SettingDefinition('security.login_max_attempts', 'Failed sign-ins before lockout', 'integer', 'Per email address and IP.', rules: ['min:3', 'max:20']),
            new SettingDefinition('security.login_lockout_minutes', 'Lockout duration (minutes)', 'integer', rules: ['min:1', 'max:1440']),
            new SettingDefinition('security.login_ip_allowlist', 'IP addresses exempt from lockout', 'text', 'One IP or CIDR range per line.', rules: ['max:2000']),
            new SettingDefinition('platform.impersonation_minutes', 'Sign-in-as-client time limit (minutes)', 'integer', 'Staff are returned to the admin area after this long.', rules: ['min:5', 'max:240']),
            new SettingDefinition('platform.invitation_expiry_days', 'Invitation links work for (days)', 'integer', 'Staff and client-login invitations. A new invitation replaces the old link.', rules: ['min:1', 'max:30']),
            // Shortening retention prunes staff and security events, so it is a security setting.
            new SettingDefinition('platform.activity_log_retention_days', 'Activity log retention (days)', 'integer', 'Entries not tied to a client are pruned after this many days.', rules: ['min:30', 'max:3650']),
            new SettingDefinition('platform.automation_log_retention_days', 'Automation log retention (days)', 'integer', 'Daily automation runs are deleted after this many days; each task keeps its latest run (90 to 3,650).', rules: ['min:90', 'max:3650']),
            new SettingDefinition('platform.failed_jobs_retention_days', 'Failed background jobs retention (days)', 'integer', 'Failed jobs can no longer be retried once deleted (7 to 365).', rules: ['min:7', 'max:365']),
        ], 'core.settings.security');
    }

    /**
     * Mail transport settings (they can redirect every reset link, so a sensitive permission) and their use at runtime:
     * when the mail manager is built, and before every queued job so long-running workers follow a change.
     */
    private function registerMailServer(): void
    {
        $smtp = fn (string $field): string => "mail.mailers.smtp.{$field}";
        $required = fn (string $mailer): string => 'required_if:mail.default,'.$mailer;

        $this->app->make(SettingsRegistry::class)->section(MailServerSettings::SECTION, 'Mail server', [
            new SettingDefinition('mail.default', 'Send email with', 'select', 'Until this page is saved, the server environment (.env) settings are used. Log only writes each message to the application log.', options: MailServerSettings::MAILERS),
            new SettingDefinition($smtp('host'), 'SMTP host', help: 'For example smtp.yourdomain.com.', rules: [$required('smtp'), 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]*$/']),
            new SettingDefinition($smtp('port'), 'SMTP port', 'integer', 'Usually 587 for STARTTLS or 465 for TLS.', rules: ['min:1', 'max:65535']),
            new SettingDefinition($smtp('encryption'), 'Encryption', 'select', 'STARTTLS, required, refuses a server that does not offer it, so the password is never sent unencrypted.', options: MailServerSettings::ENCRYPTION),
            new SettingDefinition($smtp('username'), 'SMTP username', help: 'Leave empty if the server does not ask for a sign-in.', rules: ['max:255']),
            new SettingDefinition($smtp('password'), 'SMTP password', secret: true, rules: ['max:255']),
            new SettingDefinition('services.ses.key', 'AWS access key ID', secret: true, rules: ['max:128']),
            new SettingDefinition('services.ses.secret', 'AWS secret access key', secret: true, rules: ['max:255']),
            new SettingDefinition('services.ses.region', 'AWS region', help: 'The SES region your sending domain is verified in, for example ap-south-1.', rules: [$required('ses'), 'max:32', 'regex:/^$|^[a-z]{2}(-[a-z]+)+-\d$/']),
        ], 'core.settings.mail');

        $this->app->afterResolving('mail.manager', fn (MailManager $manager) => $this->app->make(MailServerSettings::class)->apply($manager));
        Event::listen(JobProcessing::class, function (): void {
            if ($this->app->resolved('mail.manager')) {
                $this->app->make(MailServerSettings::class)->apply();
            }
        });
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
