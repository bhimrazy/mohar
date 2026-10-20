<?php

namespace App\Modules\Domains;

use App\Contracts\Domains\DomainPricing;
use App\Contracts\Domains\DomainRegistry;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Events\Billing\InvoiceCancelled;
use App\Events\Billing\InvoicePaid;
use App\Events\Services\ServiceActivated;
use App\Events\Services\ServiceRenewed;
use App\Events\Services\ServiceSuspended;
use App\Events\Services\ServiceTerminated;
use App\Events\Services\ServiceUnsuspended;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ChunkedDelete;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Actions\ApplyExpiryLifecycle;
use App\Modules\Domains\Actions\ConvertTldPrices;
use App\Modules\Domains\Actions\IssueRenewalInvoices;
use App\Modules\Domains\Actions\SendExpiryReminders;
use App\Modules\Domains\Drivers\Registrars\LogRegistrar;
use App\Modules\Domains\Drivers\Registrars\NpManualRegistrar;
use App\Modules\Domains\Drivers\Registrars\RegistrarManager;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Jobs\SyncRegistrars;
use App\Modules\Domains\Listeners\HandleInvoiceCancelled;
use App\Modules\Domains\Listeners\HandleInvoicePaid;
use App\Modules\Domains\Listeners\MirrorLinkedServiceState;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\NpRegistration;
use App\Modules\Domains\Models\RegistrarCommandLog;
use App\Modules\Domains\Services\DomainPricingService;
use App\Modules\Domains\Services\DomainRegistryService;
use App\Modules\Domains\Services\NpWorkflow;
use App\Modules\Domains\Services\RenewalInvoices;
use App\Support\Automation\Automation;
use App\Support\Currencies\PriceConverters;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Domains module. Registrar sync is scheduled hourly (the SyncRegistrars job on the registrar queue, also runnable as
 * `domains:sync`) rather than in the daily automation pipeline because pending transfers and needs_review domains
 * need sub-daily checks; it has no SYNC task.
 */
class DomainsServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'domains';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(RegistrarManager::class, function (): RegistrarManager {
            $manager = new RegistrarManager;
            $manager->extend('log', LogRegistrar::class);
            $manager->extend(NpWorkflow::DRIVER, NpManualRegistrar::class);

            return $manager;
        });

        $this->app->scoped(DomainPricingService::class);
        $this->app->scoped(DomainPricing::class, fn ($app) => $app->make(DomainPricingService::class));
        $this->app->bind(DomainRegistry::class, DomainRegistryService::class);
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new SyncRegistrars)->name('domains:sync')->hourly()->withoutOverlapping()->onOneServer();
    }

    protected function bootModule(): void
    {
        $this->registerListeners();
        $this->registerAutomation();
        $this->registerNavigation();
        $this->registerDashboardCards();
        $this->registerPermissionsAndSettings();
        $this->registerSearchAndMergeFields();

        RateLimiter::for('domain-lookup', fn (Request $request): Limit => Limit::perMinute((int) $this->app->make(Settings::class)->get('domains.lookup_rate_limit', 30))->by($request->ip()));
    }

    private function registerListeners(): void
    {
        Event::listen(InvoicePaid::class, HandleInvoicePaid::class);
        Event::listen(InvoiceCancelled::class, HandleInvoiceCancelled::class);

        foreach ([ServiceActivated::class, ServiceRenewed::class, ServiceSuspended::class, ServiceUnsuspended::class, ServiceTerminated::class] as $event) {
            Event::listen($event, MirrorLinkedServiceState::class);
        }
    }

    private function registerAutomation(): void
    {
        $automation = $this->app->make(Automation::class);
        $invoicesAvailable = fn (): bool => $this->app->make(RenewalInvoices::class)->available();

        $automation->task('domains.renewals', Automation::RENEWALS, fn (CarbonImmutable $today): int|string => $invoicesAvailable()
            ? $this->app->make(IssueRenewalInvoices::class)->handle($today)
            : 'skipped: no invoicer', label: 'Generate domain renewal invoices', description: 'Invoices domains that are coming up for renewal.');

        $automation->task('domains.reminders', Automation::REMINDERS, fn (CarbonImmutable $today): int => $this->app->make(SendExpiryReminders::class)->handle($today), label: 'Send domain expiry reminders', description: 'Emails clients about domains that are about to expire.');

        $automation->task('domains.lifecycle', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => $this->app->make(ApplyExpiryLifecycle::class)->handle($today), label: 'Move expired domains through grace and redemption', description: 'Moves expired domains into grace, redemption and expired, swapping their renewal invoices.');

        $this->app->make(PriceConverters::class)->register(ConvertTldPrices::class);
        $automation->task('domains.convert-prices', Automation::SYNC, fn (): int|string => $this->app->make(ConvertTldPrices::class)->automatic()
            ? $this->app->make(ConvertTldPrices::class)->apply()
            : 'disabled', label: 'Convert domain prices', description: 'Recalculates TLD prices in other currencies from the default currency at current rates (when automatic conversion is on).');

        $automation->task('domains.np_review_due', Automation::SYNC, fn (CarbonImmutable $today): int => $this->app->make(NpWorkflow::class)->flagOverdueReviews($today), label: 'Flag overdue .np status checks', description: 'Flags .np domains whose registry status check date passed without staff confirming it.');

        $automation->task('domains.np_documents_prune', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => $this->app->make(NpWorkflow::class)->pruneDocuments($today), label: 'Delete old .np documents', description: 'Deletes registrant identity documents once their retention period after approval or rejection ends.');

        $automation->task('domains.command_log_prune', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => ChunkedDelete::run(RegistrarCommandLog::query()
            ->where('created_at', '<', $today->subDays((int) $this->app->make(Settings::class)->get('domains.command_log_retention_days', 90)))), label: 'Prune the registrar log', description: 'Deletes registrar command log entries older than the retention period.');
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Domains', 'admin.domains.index', 'Globe', 'Domains', 10, 'domains.domains.view', 'admin.domains.show'));
        $navigation->add('admin', new NavItem('TLD pricing', 'admin.domains.tlds.index', 'Tag', 'Domains', 20, 'domains.tlds.view', 'admin.domains.tlds.*'));
        $navigation->add('admin', new NavItem('Registrars', 'admin.domains.registrars.index', 'Server', 'Domains', 30, 'domains.registrars.manage', 'admin.domains.registrars.*'));
        $navigation->add('client', new NavItem('My domains', 'client.domains.index', 'Globe', 'Domains', 10, null, 'client.domains.*'));
        $navigation->add('client', new NavItem('Register a domain', 'domains.search', 'Search', 'Domains', 20));
        $navigation->add('admin-client', new NavItem('Domains', 'admin.domains.index', 'Globe', order: 30, permission: 'domains.domains.view'));
    }

    /**
     * Registrar command success rate over the last 7 days, for the dashboard's system health card.
     *
     * @return array<string, mixed>
     */
    private function registrarHealth(): array
    {
        $results = DB::table('registrar_command_log')->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('success, count(*) as total')->groupBy('success')->pluck('total', 'success');
        $succeeded = (int) ($results[1] ?? 0);
        $total = $succeeded + (int) ($results[0] ?? 0);
        $rate = $total === 0 ? null : round($succeeded / $total * 100, 1);

        return [
            'label' => 'Registrar commands',
            'detail' => $total === 0 ? 'No registrar commands in the last 7 days' : sprintf('%d of %d succeeded (7 days)', $succeeded, $total),
            'value' => $rate === null ? 'Idle' : $rate.'%',
            'tone' => $rate === null ? 'neutral' : ($rate < 98 ? 'warning' : 'success'),
            'icon' => 'RefreshCw',
            'href' => route('admin.domains.index', ['attention' => 1]),
        ];
    }

    private function registerDashboardCards(): void
    {
        $dashboard = $this->app->make(Dashboard::class);

        $dashboard->card('admin', 'domains.expiring', fn (?User $user): array => [
            'label' => 'Domains expiring in 30 days',
            'value' => Domain::query()->expiringWithin(30)->count(),
            'href' => route('admin.domains.index', ['expiring' => 30]),
            'icon' => 'Globe',
        ], 'domains.domains.view', 40, mirrorsTodo: true);

        $dashboard->card('admin', 'domains.pending', fn (?User $user): array => [
            'label' => 'Pending registrations',
            'value' => Domain::query()->withStatus([DomainStatus::Pending, DomainStatus::PendingRegistration, DomainStatus::PendingTransfer])->count(),
            'href' => route('admin.domains.index', ['status' => DomainStatus::Pending->value]),
            'icon' => 'Globe',
        ], 'domains.domains.view', 41);

        $dashboard->todo('admin', 'domains.expiring', fn (?User $user): array => [
            'label' => 'Domains expiring in 30 days',
            'count' => Domain::query()->expiringWithin(30)->count(),
            'href' => route('admin.domains.index', ['expiring' => 30]),
            'tone' => 'warning',
            'icon' => 'Globe',
        ], 'domains.domains.view', 60);

        $dashboard->todo('admin', 'domains.attention', fn (?User $user): array => [
            'label' => 'Domains with failed or unverified registrar actions',
            'count' => Domain::query()->needingAttention()->count(),
            'href' => route('admin.domains.index', ['attention' => 1]),
            'tone' => 'danger',
            'icon' => 'Globe',
        ], 'domains.domains.view', 61);

        $dashboard->todo('admin', 'domains.np_waiting', fn (?User $user): array => [
            'label' => '.np requests waiting for staff',
            'count' => $this->app->make(NpWorkflow::class)->queue('waiting')->count(),
            'href' => route('admin.domains.index', ['np' => 'waiting']),
            'tone' => 'warning',
            'icon' => 'Globe',
        ], 'domains.np.review', 62);

        $dashboard->todo('admin', 'domains.np_overdue', fn (?User $user): array => [
            'label' => '.np requests overdue at the registry',
            'count' => $this->app->make(NpWorkflow::class)->queue('overdue')->count(),
            'href' => route('admin.domains.index', ['np' => 'overdue']),
            'tone' => 'danger',
            'icon' => 'Globe',
        ], 'domains.np.review', 63);

        $dashboard->health('admin', 'domains.registrars', fn (?User $user): array => $this->registrarHealth(), 'domains.domains.view', 50);

        $dashboard->todo('client', 'domains.renew', fn (?User $user, Client $client): array => [
            'label' => 'Domains to renew',
            'count' => Domain::query()->where('client_id', $client->id)
                ->where(fn ($query) => $query->expiringWithin(30)->orWhere(fn ($query) => $query->withStatus([DomainStatus::Grace, DomainStatus::Redemption])))
                ->count(),
            'href' => route('client.domains.index'),
            'tone' => 'warning',
        ], null, 30);

        $dashboard->todo('client', 'domains.np_documents', fn (?User $user, Client $client): array => [
            'label' => 'Documents needed for your .np domain',
            'count' => NpRegistration::query()->where('stage', 'awaiting_documents')->whereHas('domain', fn ($query) => $query->where('client_id', $client->id))->count(),
            'href' => route('client.domains.index'),
            'tone' => 'warning',
        ], null, 31);

        $dashboard->card('client', 'domains.expiring', fn (?User $user, Client $client): array => [
            'label' => 'Domains expiring soon',
            'value' => Domain::query()->where('client_id', $client->id)->expiringWithin(30)->count(),
            'href' => route('client.domains.index'),
            'icon' => 'Globe',
        ], null, 30);
    }

    private function registerPermissionsAndSettings(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Domains', [
            'domains.domains.view' => 'View domains',
            'domains.domains.update' => 'Edit domains, force status, move to another client',
            'domains.domains.commands' => 'Run registrar commands (register, transfer, renew, sync, EPP code)',
            'domains.domains.delete' => 'Delete domain records (never calls the registrar)',
            'domains.tlds.view' => 'View TLDs and pricing',
            'domains.tlds.manage' => 'Manage TLDs and pricing',
            'domains.registrars.manage' => 'Configure registrars',
            'domains.np.review' => 'Review .np registrant documents and record registry steps',
            'domains.settings.manage' => 'Manage domain settings',
        ], sensitive: ['domains.registrars.manage']);

        $this->app->make(SettingsRegistry::class)->section('domains', 'Domains', [
            new SettingDefinition('domains.default_auto_renew', 'Auto-renew new domains by default', 'boolean'),
            new SettingDefinition('domains.invoice_lead_days', 'Days before the due date to issue renewal invoices', 'integer', 'Empty uses billing.invoice_lead_days.', rules: ['nullable', 'min:0', 'max:365']),
            new SettingDefinition('domains.next_due_offset_days', 'Days before expiry that renewals are due', 'integer', rules: ['min:0', 'max:90']),
            new SettingDefinition('domains.free_renewal_requires_service', 'Free domains renew only with an active linked service', 'boolean'),
            new SettingDefinition('domains.renew_on_payment', 'Renew at the registrar when the renewal invoice is paid', 'boolean'),
            new SettingDefinition('domains.enable_renewal_orders', 'Allow clients to order renewals', 'boolean'),
            new SettingDefinition('domains.grace_redemption_enabled', 'Use grace and redemption periods', 'boolean'),
            new SettingDefinition('domains.default_nameservers', 'Default nameservers', 'text', 'One hostname per line (2–5).'),
            new SettingDefinition('domains.use_client_contacts', 'Use the client profile for admin, tech and billing contacts', 'boolean'),
            new SettingDefinition('domains.default_contact', 'Default contact (JSON)', 'text', 'Used for admin/tech/billing contacts when client contacts are off.'),
            new SettingDefinition('domains.lock_by_default', 'Lock domains after registration or transfer', 'boolean'),
            new SettingDefinition('domains.sync_enabled', 'Sync domains with registrars', 'boolean'),
            new SettingDefinition('domains.sync_notify_only', 'Report sync differences without changing anything', 'boolean'),
            new SettingDefinition('domains.sync_batch_size', 'Domains per sync run', 'integer', rules: ['min:1', 'max:1000']),
            new SettingDefinition('domains.sync_interval_hours', 'Hours between syncs of the same domain', 'integer', rules: ['min:1', 'max:720']),
            new SettingDefinition('domains.transfer_sync_interval_hours', 'Hours between transfer status checks', 'integer', rules: ['min:1', 'max:168']),
            new SettingDefinition('domains.transfer_stale_days', 'Notify staff about transfers pending longer than (days)', 'integer', rules: ['min:1', 'max:90']),
            new SettingDefinition('domains.command_log_retention_days', 'Keep registrar command log for (days)', 'integer', rules: ['min:1', 'max:3650']),
            new SettingDefinition('domains.np_document_retention_days', 'Delete .np registrant documents after approval or rejection (days)', 'integer', rules: ['min:1', 'max:365']),
            new SettingDefinition('domains.reminder_offsets', 'Expiry reminder days (negative = after expiry)', 'string', 'Comma-separated, at most 5, e.g. 30, 7, -3.'),
            new SettingDefinition('domains.remind_when_auto_renew_off', 'Send reminders when auto-renew is off', 'boolean'),
            new SettingDefinition('domains.remind_free_domains', 'Send reminders for free domains', 'boolean'),
            new SettingDefinition('domains.allow_idn', 'Allow internationalised domain names', 'boolean'),
            new SettingDefinition('domains.lookup_registrar', 'Registrar used for availability lookups', 'string'),
            new SettingDefinition('domains.lookup_tlds', 'Extensions checked when no TLD is entered', 'string', 'Comma-separated, e.g. .com, .net'),
            new SettingDefinition('domains.lookup_cache_seconds', 'Cache lookup results for (seconds)', 'integer', rules: ['min:0', 'max:86400']),
            new SettingDefinition('domains.suggestion_tlds', 'Extensions used for suggestions', 'string', 'Comma-separated.'),
            new SettingDefinition('domains.suggestion_limit', 'Maximum suggestions', 'integer', rules: ['min:0', 'max:50']),
            new SettingDefinition('domains.lookup_rate_limit', 'Lookups per minute per IP', 'integer', rules: ['min:1', 'max:600']),
            new SettingDefinition('domains.premium_enabled', 'Sell premium names (at the standard price until premium pricing exists)', 'boolean'),
            new SettingDefinition('domains.order_route', 'Cart route used by "Add to cart" on the domain search', 'string'),
        ], 'domains.settings.manage');

        // Converting prices rewrites them from exchange rates, so it sits with the sensitive rate permission.
        $this->app->make(SettingsRegistry::class)->section('domains_conversion', 'Converted TLD prices', [
            new SettingDefinition('domains.auto_convert_prices', 'Convert TLD prices from the default currency automatically', 'boolean', 'After exchange rates change, TLD prices in other currencies are recalculated from the default-currency price with each currency\'s buffer and rounding. Prices marked "set manually" are kept.'),
        ], 'core.currencies.rates');
    }

    private function registerSearchAndMergeFields(): void
    {
        $this->app->make(GlobalSearch::class)->provider('Domains', fn (string $term): array => array_values(Domain::query()
            ->with('client:id,first_name,last_name,company_name')
            ->search($term)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (Domain $domain): array => [
                'title' => $domain->name_unicode,
                'subtitle' => $domain->client->display_name.' · '.$domain->status->label(),
                'href' => route('admin.domains.show', $domain),
            ])
            ->all()), 'domains.domains.view');

        $this->app->make(MergeFieldRegistry::class)->register('domain', function (int $id): array {
            $domain = Domain::query()->withTrashed()->findOrFail($id);

            return [
                'domain_id' => $domain->id,
                'client_id' => $domain->client_id,
                'domain' => $domain->name_unicode,
                'domain_ascii' => $domain->name,
                'status' => $domain->status->value,
                'registration_date' => $domain->registration_date?->toDateString(),
                'expiry_date' => $domain->expiry_date?->toDateString(),
                'next_due_date' => $domain->next_due_date->toDateString(),
                'days_until_expiry' => $domain->expiry_date === null ? null : (int) CarbonImmutable::today()->diffInDays($domain->expiry_date, false),
                'recurring_amount' => $domain->recurring_amount,
                'currency' => $domain->currency,
                'period_years' => $domain->period_years,
                'auto_renew' => $domain->auto_renew,
                'nameservers' => $domain->nameservers ?? [],
            ];
        });
    }
}
