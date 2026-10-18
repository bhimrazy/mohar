<?php

namespace App\Modules\Services;

use App\Contracts\Notifications\MergeFieldRegistry;
use App\Contracts\Services\CatalogUsage;
use App\Contracts\Services\ClientServiceDirectory;
use App\Contracts\Services\ProvisioningDrivers;
use App\Contracts\Services\ServiceRegistry;
use App\Events\Billing\InvoiceCancelled;
use App\Events\Billing\InvoicePaid;
use App\Events\Orders\OrderPlaced;
use App\Models\User;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\RequestMemo;
use App\Modules\Services\Actions\CompleteLimitedServices;
use App\Modules\Services\Actions\ExpireUnpaidChanges;
use App\Modules\Services\Actions\GenerateRenewals;
use App\Modules\Services\Actions\ProcessCancellations;
use App\Modules\Services\Actions\PruneModuleLog;
use App\Modules\Services\Actions\SuspendOverdueServices;
use App\Modules\Services\Actions\SyncUsage;
use App\Modules\Services\Actions\TerminateOverdueServices;
use App\Modules\Services\Drivers\Provisioning\DriverRegistry;
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
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\EloquentCatalogUsage;
use App\Modules\Services\Services\EloquentClientServiceDirectory;
use App\Modules\Services\Services\EloquentServiceRegistry;
use App\Modules\Services\Services\RegistryProvisioningDrivers;
use App\Modules\Services\Support\ServicesDashboard;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Search\GlobalSearch;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;

class ServicesServiceProvider extends ModuleServiceProvider
{
    public function moduleKey(): string
    {
        return 'services';
    }

    protected function registerModule(): void
    {
        $this->app->singleton(DriverRegistry::class, function ($app): DriverRegistry {
            $registry = new DriverRegistry($app);
            $registry->register('none', NoneDriver::class);
            $registry->register('log', LogDriver::class);

            return $registry;
        });
        $this->app->singleton(LogDriver::class);
        $this->app->bind(ServiceRegistry::class, EloquentServiceRegistry::class);
        $this->app->bind(CatalogUsage::class, EloquentCatalogUsage::class);
        $this->app->bind(ProvisioningDrivers::class, RegistryProvisioningDrivers::class);
        $this->app->bind(ClientServiceDirectory::class, EloquentClientServiceDirectory::class);
    }

    protected function bootModule(): void
    {
        Event::listen(InvoicePaid::class, ApplyPaidInvoice::class);
        Event::listen(InvoiceCancelled::class, SyncCancelledInvoice::class);
        Event::listen(OrderPlaced::class, RecordOrderInvoice::class);

        // Driver commands run on the "provisioning" queue; the dev runner must consume it too.
        DevCommands::artisan('queue:listen --queue=provisioning,default --tries=1 --timeout=0', 'queue');

        $this->registerAutomation();
        $this->registerNavigation();
        $this->registerDashboard();
        $this->registerPermissions();
        $this->registerSettings();
        $this->registerSearchAndMergeFields();
    }

    private function registerAutomation(): void
    {
        $automation = $this->app->make(Automation::class);
        $automation->task('services.renewals', Automation::RENEWALS, fn (CarbonImmutable $today): int|string => app(GenerateRenewals::class)->handle($today));
        $automation->task('services.cancellations', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => app(ProcessCancellations::class)->handle($today));
        $automation->task('services.upgrade-expiry', Automation::CANCELLATIONS, fn (CarbonImmutable $today): int => app(ExpireUnpaidChanges::class)->handle($today));
        $automation->task('services.suspensions', Automation::SUSPENSIONS, fn (CarbonImmutable $today): int => app(SuspendOverdueServices::class)->handle($today));
        $automation->task('services.terminations', Automation::TERMINATIONS, fn (CarbonImmutable $today): int => app(TerminateOverdueServices::class)->handle($today));
        $automation->task('services.complete-limited', Automation::TERMINATIONS, fn (CarbonImmutable $today): int => app(CompleteLimitedServices::class)->handle($today));
        $automation->task('services.usage-sync', Automation::SYNC, fn (CarbonImmutable $today): int => app(SyncUsage::class)->handle($today));
        $automation->task('services.module-log-prune', Automation::HOUSEKEEPING, fn (CarbonImmutable $today): int => app(PruneModuleLog::class)->handle($today));
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
            $today = CarbonImmutable::today();

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

        $dashboard->card('client', 'services.active', fn (?User $user, Client $client): array => [
            'label' => 'Active services',
            'value' => Service::query()->where('client_id', $client->id)->where('status', ServiceStatus::Active->value)->count(),
            'href' => route('client.services.index'),
            'icon' => 'Server',
        ], order: 10);

        $dashboard->card('client', 'services.renewing', fn (?User $user, Client $client): array => [
            'label' => 'Renewing in 30 days',
            'value' => Service::query()->where('client_id', $client->id)
                ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->where('do_not_renew', false)
                ->whereNotNull('next_due_date')
                ->whereDate('next_due_date', '<=', CarbonImmutable::today()->addDays(30))
                ->count(),
            'href' => route('client.services.index'),
            'icon' => 'Receipt',
        ], order: 11);
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
            'services.cancellations.manage' => 'Review, approve and void cancellation requests',
            'services.module_log.view' => 'View the module log and failed actions',
            'services.module_log.manage' => 'Retry, resolve and dismiss failed module actions',
            'services.settings.manage' => 'Manage services automation settings',
        ], sensitive: ['services.services.credentials', 'services.servers.manage']);
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
                'subtitle' => trim(($service->product_name ?? '').($service->username ? " · {$service->username}" : '').' · '.$service->status->value, ' ·'),
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
                'dedicated_ip' => $service->dedicated_ip,
                'server_hostname' => $service->server?->hostname,
                'server_ip' => $service->server?->ip_address,
                'nameservers' => $service->server->nameservers ?? [],
            ];
        });
    }
}
