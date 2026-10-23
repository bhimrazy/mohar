<?php

namespace App\Modules\Services;

use App\Contracts\Core\CompanyClock;
use App\Contracts\Notifications\MergeFieldRegistry;
use App\Contracts\Services\CatalogUsage;
use App\Contracts\Services\ClientServiceDirectory;
use App\Contracts\Services\ProvisioningDrivers;
use App\Contracts\Services\ServiceRegistry;
use App\Enums\BillingCycle;
use App\Events\Billing\InvoiceCancelled;
use App\Events\Billing\InvoicePaid;
use App\Events\Orders\OrderPlaced;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Core\Support\RequestMemo;
use App\Modules\Services\Actions\CompleteLimitedServices;
use App\Modules\Services\Actions\ExpireUnpaidChanges;
use App\Modules\Services\Actions\GenerateRenewals;
use App\Modules\Services\Actions\ProcessCancellations;
use App\Modules\Services\Actions\PruneModuleLog;
use App\Modules\Services\Actions\SuspendOverdueServices;
use App\Modules\Services\Actions\SyncUsage;
use App\Modules\Services\Actions\TerminateOverdueServices;
use App\Modules\Services\Drivers\Provisioning\Cpanel\CpanelDriver;
use App\Modules\Services\Drivers\Provisioning\LogDriver;
use App\Modules\Services\Drivers\Provisioning\NoneDriver;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Listeners\ApplyPaidInvoice;
use App\Modules\Services\Listeners\RecordOrderInvoice;
use App\Modules\Services\Listeners\SyncCancelledInvoice;
use App\Modules\Services\Models\CancellationRequest;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerAccountImport;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\EloquentCatalogUsage;
use App\Modules\Services\Services\EloquentClientServiceDirectory;
use App\Modules\Services\Services\EloquentServiceRegistry;
use App\Modules\Services\Services\RegistryProvisioningDrivers;
use App\Modules\Services\Support\ClientNameservers;
use App\Modules\Services\Support\ServicesDashboard;
use App\Support\Automation\Automation;
use App\Support\Clients\ClientCurrencyUsage;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Security\EncryptedModel;
use App\Support\Security\EncryptedStorage;
use App\Support\Services\ProvisioningDriverRegistry;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class ServicesServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'services';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(LogDriver::class);
        $this->app->bind(ServiceRegistry::class, EloquentServiceRegistry::class);
        $this->app->bind(CatalogUsage::class, EloquentCatalogUsage::class);
        $this->app->bind(ProvisioningDrivers::class, RegistryProvisioningDrivers::class);
        $this->app->bind(ClientServiceDirectory::class, EloquentClientServiceDirectory::class);
    }

    protected function bootModule(): void
    {
        // Encrypted casts, for `security:reencrypt` (ADR 0022).
        $encrypted = $this->app->make(EncryptedStorage::class);
        $encrypted->register('services_servers', new EncryptedModel(Server::class));
        $encrypted->register('services_services', new EncryptedModel(Service::class));
        $encrypted->register('services_module_queue', new EncryptedModel(ModuleQueueItem::class));
        $encrypted->register('services_account_imports', new EncryptedModel(ServerAccountImport::class));

        $drivers = $this->app->make(ProvisioningDriverRegistry::class);
        $drivers->register('none', NoneDriver::class);
        $drivers->register('log', LogDriver::class);
        $drivers->register(CpanelDriver::KEY, CpanelDriver::class);

        // A service's prices are stored in its client's currency (client-import spec §6.4); a deleted one still counts.
        $this->app->make(ClientCurrencyUsage::class)->register('services', fn (array $clientIds): array => Service::withTrashed()
            ->whereIn('client_id', $clientIds)->distinct()->pluck('client_id')->all());

        Event::listen(InvoicePaid::class, ApplyPaidInvoice::class);
        Event::listen(InvoiceCancelled::class, SyncCancelledInvoice::class);
        Event::listen(OrderPlaced::class, RecordOrderInvoice::class);

        // Account import: ticking rows and confirming an import count separately, per staff member.
        RateLimiter::for('services-import-select', fn (Request $request): Limit => Limit::perMinute(120)->by('import-select:'.$request->user()?->id));
        RateLimiter::for('services-import-apply', fn (Request $request): Limit => Limit::perMinute(6)->by('import-apply:'.$request->user()?->id));

        $this->registerAutomation();
        $this->registerNavigation();
        $this->registerDashboard();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerSearchAndMergeFields();
    }

    protected function schedule(Schedule $schedule): void
    {
        // Unknown outcomes (a create that timed out) settle themselves when the server shows the command ran.
        $schedule->command('services:verify-unknown')->everyFiveMinutes()->withoutOverlapping(15)->onOneServer();
    }

    private function registerAutomation(): void
    {
        $automation = $this->app->make(Automation::class);
        $automation->task('services.renewals', Automation::RENEWALS, fn (CarbonImmutable $today): int|string => app(GenerateRenewals::class)->handle($today), label: 'Generate renewal invoices', description: 'Invoices services whose next invoice date has come.');
        $automation->task('services.cancellations', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => app(ProcessCancellations::class)->handle($today), label: 'Process cancellations', description: 'Ends services whose cancellation is due, including those set to end at the period end.');
        $automation->task('services.upgrade-expiry', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => app(ExpireUnpaidChanges::class)->handle($today), label: 'Expire unpaid upgrades', description: 'Cancels upgrades and downgrades that were not paid in time, with their invoices.');
        $automation->task('services.suspensions', Automation::SUSPENSIONS, fn (CarbonImmutable $today): int => app(SuspendOverdueServices::class)->handle($today), label: 'Suspend overdue services', description: 'Suspends services whose invoices are overdue past the suspension threshold.');
        $automation->task('services.terminations', Automation::TERMINATIONS, fn (CarbonImmutable $today): int => app(TerminateOverdueServices::class)->handle($today), label: 'Terminate overdue services', description: 'Terminates services that stayed overdue past the termination threshold.');
        $automation->task('services.complete-limited', Automation::TERMINATIONS, fn (CarbonImmutable $today): int => app(CompleteLimitedServices::class)->handle($today), label: 'Complete fixed-term services', description: 'Marks services with a renewal limit as completed after their last paid period.');
        $automation->task('services.usage-sync', Automation::SYNC, fn (CarbonImmutable $today): int => app(SyncUsage::class)->handle($today), label: 'Sync usage from servers', description: 'Records one usage snapshot per service from each server.');
        $automation->task('services.import-prune', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => ServerAccountImport::query()->where('expires_at', '<=', now())->delete(), label: 'Discard expired account imports', description: 'Deletes account lists fetched for import more than a day ago; they hold client emails.');
        $automation->task('services.module-log-prune', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => app(PruneModuleLog::class)->handle($today), label: 'Prune the module log', description: 'Deletes provisioning module log entries older than the retention period.');
    }

    private function registerNavigation(): void
    {
        $navigation = $this->app->make(Navigation::class);
        $navigation->add('admin', new NavItem('Services', 'admin.services.index', 'Server', 'Services', 10, 'services.services.view', 'admin.services.services.*'));
        $navigation->add('admin', new NavItem('Servers', 'admin.services.servers.index', 'Boxes', 'Services', 20, 'services.servers.view', 'admin.services.serve*'));
        $navigation->add('admin', new NavItem('Cancellation requests', 'admin.services.cancellations.index', 'Inbox', 'Services', 30, 'services.cancellations.manage', 'admin.services.cancellations.*',
            badge: fn (?User $user): int => RequestMemo::remember('services.open_cancellations', fn (): int => CancellationRequest::query()->where('status', CancellationStatus::Open->value)->count())));
        $navigation->add('admin', new NavItem('Module log', 'admin.services.module-log.index', 'Activity', 'Services', 40, 'services.module_log.view', 'admin.services.module-log.*'));
        $navigation->add('client', new NavItem('My services', 'client.services.index', 'Server', 'Services', 10, activePattern: 'client.services.*'));
        $navigation->add('admin-client', new NavItem('Services', 'admin.services.index', 'Server', order: 20, permission: 'services.services.view'));
    }

    private function registerDashboard(): void
    {
        $dashboard = $this->app->make(Dashboard::class);

        $dashboard->card('admin', 'services.active', fn (?User $user): array => [
            'label' => 'Active services',
            'value' => Service::query()->where('status', ServiceStatus::Active->value)->count(),
            'href' => route('admin.services.index', ['status' => 'active']),
            'icon' => 'Server',
        ], 'services.services.view', 30);

        $this->app->make(ServicesDashboard::class)->register($dashboard);

        $dashboard->card('admin', 'services.pending', fn (?User $user): array => [
            'label' => 'Pending provisioning',
            'value' => Service::query()->where('status', ServiceStatus::Pending->value)->count(),
            'href' => route('admin.services.index', ['status' => 'pending']),
            'icon' => 'Package',
        ], 'services.services.view', 31, mirrorsTodo: true);

        $dashboard->card('admin', 'services.failed', fn (?User $user): array => [
            'label' => 'Failed module actions',
            'value' => ModuleQueueItem::query()->where('status', QueueStatus::Open->value)->count(),
            'href' => route('admin.services.module-log.index', ['tab' => 'queue']),
            'icon' => 'Activity',
        ], 'services.module_log.view', 32, mirrorsTodo: true);

        $dashboard->todo('admin', 'services.nameservers', fn (?User $user): array => [
            'label' => 'Set your nameservers so clients with their own domain know where to point it.',
            'count' => $this->app->make(ClientNameservers::class)->configured() ? 0 : 1,
            'href' => route('admin.services.servers.index'),
            'tone' => 'warning',
        ], 'services.servers.view', 35);

        $dashboard->todo('admin', 'services.pending', fn (?User $user): array => [
            'label' => 'Services waiting for provisioning',
            'count' => Service::query()->where('status', ServiceStatus::Pending->value)->count(),
            'href' => route('admin.services.index', ['status' => 'pending']),
            'tone' => 'warning',
            'icon' => 'Package',
        ], 'services.services.view', 30);

        $dashboard->todo('admin', 'services.failed', fn (?User $user): array => [
            'label' => 'Failed module actions',
            'count' => ModuleQueueItem::query()->where('status', QueueStatus::Open->value)->count(),
            'href' => route('admin.services.module-log.index', ['tab' => 'queue']),
            'tone' => 'danger',
            'icon' => 'Server',
        ], 'services.module_log.view', 31);

        $dashboard->todo('admin', 'services.cancellations', fn (?User $user): array => [
            'label' => 'Cancellation requests to review',
            'count' => RequestMemo::remember('services.open_cancellations', fn (): int => CancellationRequest::query()->where('status', CancellationStatus::Open->value)->count()),
            'href' => route('admin.services.cancellations.index'),
            'tone' => 'warning',
            'icon' => 'Inbox',
        ], 'services.cancellations.manage', 32);

        $dashboard->widget('admin', 'services.renewals', 'list', function (?User $user): array {
            $today = app(CompanyClock::class)->todayDate();

            return [
                'title' => 'Renewals due in 7 days',
                'items' => Service::query()
                    ->with('client')
                    ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                    ->where('do_not_renew', false)
                    ->whereNotNull('next_due_date')
                    ->whereDate('next_due_date', '>=', $today)
                    ->whereDate('next_due_date', '<=', $today->addDays(7))
                    ->orderBy('next_due_date')
                    ->orderBy('id')
                    ->limit(6)
                    ->get()
                    ->map(fn (Service $service): array => [
                        'title' => $service->label(),
                        'subtitle' => $service->client->display_name,
                        'href' => route('admin.services.services.show', $service),
                        'meta' => $service->next_due_date?->toDateString(),
                        'date' => $service->next_due_date?->toDateString(),
                        'money' => ['amount' => $service->recurring_amount, 'currency' => $service->currency],
                        'status' => $service->status->value,
                    ])->values()->all(),
                'href' => route('admin.services.index', ['status' => 'active']),
                'empty' => 'No renewals due in the next 7 days.',
            ];
        }, 'services.services.view', 54, 'md');

        $canSee = fn (?User $user, Client $client): bool => app(ClientContext::class)->allows($user, $client, 'services');

        $dashboard->card('client', 'services.active', fn (?User $user, Client $client): ?array => $canSee($user, $client) ? [
            'label' => 'Active services',
            'value' => Service::query()->where('client_id', $client->id)->where('status', ServiceStatus::Active->value)->count(),
            'href' => route('client.services.index'),
            'icon' => 'Server',
        ] : null, order: 10);

        $dashboard->card('client', 'services.renewing', fn (?User $user, Client $client): ?array => $canSee($user, $client) ? [
            'label' => 'Renewing in 30 days',
            'value' => Service::query()->where('client_id', $client->id)
                ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->where('do_not_renew', false)
                ->whereNotNull('next_due_date')
                ->whereDate('next_due_date', '<=', app(CompanyClock::class)->todayDate()->addDays(30))
                ->count(),
            'href' => route('client.services.index'),
            'icon' => 'Receipt',
        ] : null, order: 11);

        $dashboard->widget('client', 'services.list', 'list', fn (?User $user, Client $client): ?array => $canSee($user, $client) ? [
            'title' => 'Your services',
            // Suspended and pending first (they need the client), then the next renewal.
            'items' => Service::query()->where('client_id', $client->id)
                ->whereIn('status', [ServiceStatus::Suspended->value, ServiceStatus::Pending->value, ServiceStatus::Active->value])
                ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [ServiceStatus::Suspended->value, ServiceStatus::Pending->value])
                ->orderByRaw('next_due_date is null')->orderBy('next_due_date')->orderBy('id')
                ->limit(4)
                ->get(['id', 'product_name', 'domain', 'status', 'cycle', 'recurring_amount', 'currency', 'next_due_date', 'do_not_renew'])
                ->map(fn (Service $service): array => [
                    'title' => $service->product_name ?? 'Service #'.$service->id,
                    'subtitle' => implode(' · ', array_filter([$service->domain, self::cycleLabel($service->cycle)])),
                    'href' => route('client.services.show', $service->id),
                    ...($service->cycle->isRecurring() ? ['money' => ['amount' => $service->recurring_amount, 'currency' => $service->currency]] : []),
                    'status' => $service->status->value,
                    'date' => $service->cycle->isRecurring() ? $service->next_due_date?->toDateString() : null,
                    'dateLabel' => $service->do_not_renew ? 'Ends' : 'Renews',
                ])->values()->all(),
            'href' => route('client.services.index'),
            'empty' => 'You have no services yet.',
            'emptyAction' => Route::has('store.index') ? ['label' => 'Browse the store', 'href' => route('store.index')] : null,
        ] : null, order: 10);
    }

    private static function cycleLabel(BillingCycle $cycle): string
    {
        return match ($cycle) {
            BillingCycle::Monthly => 'Monthly',
            BillingCycle::Quarterly => 'Quarterly',
            BillingCycle::SemiAnnually => 'Every 6 months',
            BillingCycle::Annually => 'Yearly',
            BillingCycle::Biennially => 'Every 2 years',
            BillingCycle::Triennially => 'Every 3 years',
            BillingCycle::OneTime => 'One-time',
            BillingCycle::Free => 'Free',
        };
    }

    private function registerPermissions(): void
    {
        $this->app->make(PermissionRegistry::class)->register('Services', [
            'services.services.view' => 'View services',
            'services.services.manage' => 'Edit services, change status without module, cancellation flags',
            'services.services.provision' => 'Run module actions (create, suspend, unsuspend, terminate, change package/password)',
            'services.services.credentials' => 'Reveal service passwords',
            'services.servers.view' => 'View servers and server groups',
            'services.servers.manage' => 'Create, edit and delete servers and server groups',
            'services.servers.import' => 'Import existing accounts from servers (reads client emails from the server)',
            'services.cancellations.manage' => 'Review, approve and void cancellation requests',
            'services.module_log.view' => 'View the module log and failed actions',
            'services.module_log.manage' => 'Retry, resolve and dismiss failed module actions',
            'services.settings.manage' => 'Manage services automation settings',
        ], sensitive: ['services.services.credentials', 'services.servers.manage', 'services.servers.import']);
    }

    private function registerSettings(): void
    {
        $integer = ['integer', 'min:0', 'max:3650'];

        $this->app->make(SettingsRegistry::class)->section('services', 'Services', [
            new SettingDefinition('services.renewals.days_before_due', 'Days before due date to generate renewal invoices', 'integer', rules: $integer),
            new SettingDefinition('services.renewals.lead_days.monthly', 'Renewal lead days: monthly (blank = default)', 'integer', rules: ['nullable', ...$integer]),
            new SettingDefinition('services.renewals.lead_days.quarterly', 'Renewal lead days: quarterly (blank = default)', 'integer', rules: ['nullable', ...$integer]),
            new SettingDefinition('services.renewals.lead_days.semiannually', 'Renewal lead days: semi-annually (blank = default)', 'integer', rules: ['nullable', ...$integer]),
            new SettingDefinition('services.renewals.lead_days.annually', 'Renewal lead days: annually (blank = default)', 'integer', rules: ['nullable', ...$integer]),
            new SettingDefinition('services.renewals.lead_days.biennially', 'Renewal lead days: biennially (blank = default)', 'integer', rules: ['nullable', ...$integer]),
            new SettingDefinition('services.renewals.lead_days.triennially', 'Renewal lead days: triennially (blank = default)', 'integer', rules: ['nullable', ...$integer]),
            new SettingDefinition('services.renewals.continuous', 'Continuous invoicing', 'boolean', 'Generate the next renewal even while an earlier one is unpaid (one period per run).'),
            new SettingDefinition('services.suspend.enabled', 'Suspend overdue services', 'boolean'),
            new SettingDefinition('services.suspend.days_overdue', 'Days overdue before suspension', 'integer', rules: $integer),
            new SettingDefinition('services.terminate.enabled', 'Terminate overdue services', 'boolean'),
            new SettingDefinition('services.terminate.days_overdue', 'Days overdue before termination', 'integer', rules: $integer),
            new SettingDefinition('services.unsuspend_on_payment', 'Unsuspend automatically when the overdue invoice is paid', 'boolean'),
            new SettingDefinition('services.cancellation.client_can_request', 'Clients can request cancellation', 'boolean'),
            new SettingDefinition('services.cancellation.cancel_unpaid_invoices', 'Cancel unpaid renewal invoices on cancellation request', 'boolean'),
            new SettingDefinition('services.cancellation.auto_process', 'Process due cancellation requests automatically', 'boolean', 'Off: requests wait for staff approval.'),
            new SettingDefinition('services.upgrades.credit_on_downgrade', 'Credit the prorated difference on downgrades', 'boolean'),
            new SettingDefinition('services.upgrades.unpaid_expiry_days', 'Days before an unpaid upgrade expires', 'integer', rules: $integer),
            new SettingDefinition('services.upgrades.charge_setup_fee', 'Charge setup fees on upgrades', 'boolean'),
            new SettingDefinition('services.module.max_attempts', 'Attempts for retryable module actions', 'integer', rules: ['integer', 'min:1', 'max:10']),
            new SettingDefinition('services.module.lock_wait_seconds', 'Seconds a module action waits for another action on the same service', 'integer', rules: ['integer', 'min:1', 'max:120']),
            new SettingDefinition('services.module_log.retention_days', 'Module log retention (days)', 'integer', rules: ['integer', 'min:1', 'max:3650']),
            new SettingDefinition('services.random_usernames', 'Generate random usernames instead of using the domain', 'boolean'),
        ], 'services.settings.manage');
    }

    private function registerSearchAndMergeFields(): void
    {
        $this->app->make(GlobalSearch::class)->provider('Services', fn (string $term): array => array_values(Service::query()
            ->search($term)->limit(5)->get()
            ->map(fn (Service $service): array => [
                'title' => $service->label(),
                'subtitle' => trim(($service->product_name ?? '').($service->username ? " · {$service->username}" : '').' · '.ucfirst(str_replace('_', ' ', $service->status->value)), ' ·'),
                'href' => route('admin.services.services.show', $service),
            ])->all()), 'services.services.view');

        $this->app->make(MergeFieldRegistry::class)->register('service', function (int $id): array {
            $service = Service::withTrashed()->with('server')->findOrFail($id);

            return [
                'service_id' => $service->id,
                'client_id' => $service->client_id,
                'product_id' => $service->product_id,
                'product_name' => $service->product_name,
                'domain' => $service->domain,
                'username' => $service->username,
                'status' => $service->status->value,
                'cycle' => $service->cycle->value,
                'currency' => $service->currency,
                'recurring_amount' => $service->recurring_amount,
                'first_payment_amount' => $service->first_payment_amount,
                'registration_date' => $service->registration_date->toDateString(),
                'next_due_date' => $service->next_due_date?->toDateString(),
                'suspension_reason' => $service->suspension_reason,
                'addon_ids' => $service->addons()->pluck('id')->all(),
                'dedicated_ip' => $service->dedicated_ip,
                'server_hostname' => $service->server?->hostname,
                'server_ip' => $service->server?->ip_address,
                'nameservers' => $service->server->nameservers ?? [],
            ];
        });
    }
}
