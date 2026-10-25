<?php

namespace App\Modules\Platform\Support;

use App\Models\User;
use App\Modules\Core\Install\CacheLockStore;
use App\Modules\Core\Install\CheckStatus;
use App\Modules\Core\Install\PhpSecurityRelease;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\StaffInvitation;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;
use App\Support\Integrations\Endpoints;
use Illuminate\Support\Facades\DB;

/**
 * Platform's admin dashboard widgets and "Needs attention" to-dos.
 */
class PlatformDashboard
{
    public function __construct(private AutomationStatus $automation, private Settings $settings) {}

    public function register(Dashboard $dashboard): void
    {
        $dashboard->todo('admin', 'platform.automation.failed', fn (): array => [
            'label' => 'Automation tasks failed today',
            'count' => $this->automation->failedToday(),
            'href' => route('admin.platform.automation.index', ['status' => 'failed']),
            'tone' => 'danger',
            'icon' => 'Timer',
        ], 'core.automation.view', 10);

        $dashboard->todo('admin', 'platform.automation.stale', fn (): array => [
            'label' => 'Daily automation has not run for over '.AutomationStatus::STALE_AFTER_HOURS.' hours',
            'count' => app(Automation::class)->ordered() !== [] && $this->automation->isStale() ? 1 : 0,
            'href' => route('admin.platform.automation.index'),
            'tone' => 'danger',
            'icon' => 'Timer',
        ], 'core.automation.view', 11);

        $dashboard->todo('admin', 'platform.staff.no_2fa', fn (): array => [
            'label' => 'Staff without two-factor authentication',
            'count' => (bool) $this->settings->get('security.staff_2fa_required', true)
                ? User::query()->where('is_staff', true)->where('is_active', true)->whereNull('two_factor_confirmed_at')->count()
                : 0,
            'href' => route('admin.platform.staff.index', ['two_factor' => 'off']),
            'tone' => 'warning',
            'icon' => 'Shield',
        ], 'core.staff.view', 20);

        $dashboard->todo('admin', 'platform.invitations.expiring', fn (): array => [
            'label' => 'Staff invitations expiring within 48 hours',
            'count' => StaffInvitation::query()->pending()->where('expires_at', '>', now())->where('expires_at', '<=', now()->addHours(48))->count(),
            'href' => route('admin.platform.staff.index'),
            'tone' => 'warning',
            'icon' => 'Mail',
        ], 'core.staff.view', 21);

        $dashboard->widget('admin', 'platform.activity', 'list', fn (): array => $this->recentActivity(), 'core.activity_log.view', 60, 'md');
        $dashboard->widget('admin', 'platform.staff.logins', 'list', fn (): array => $this->staffLogins(), 'core.staff.view', 61, 'md');

        $dashboard->health('admin', 'platform.scheduler', fn (): array => $this->schedulerHealth(), 'core.automation.view', 10);
        $dashboard->health('admin', 'platform.automation', fn (): array => $this->automationHealth(), 'core.automation.view', 11);
        $dashboard->health('admin', 'platform.queue', fn (): array => $this->queueHealth(), 'core.automation.view', 12);
        $this->registerIntegrationEndpoints($dashboard);
        $this->registerPhpRuntime($dashboard);
        $this->registerCacheLockStore($dashboard);
    }

    /**
     * The cache lock store (CacheLockStore), a health check in production only while locks cannot exclude other
     * processes: red for a per-process store, amber for a single-host one.
     */
    public function registerCacheLockStore(Dashboard $dashboard): void
    {
        $result = (new CacheLockStore)->assess();

        if ($result['status'] === CheckStatus::Ok || ! app()->isProduction()) {
            return;
        }

        $dashboard->health('admin', 'platform.cache_lock_store', fn (): array => [
            'label' => 'Cache lock store',
            'detail' => $result['detail'],
            'value' => $result['status'] === CheckStatus::Failed ? 'Fix the task lock store' : 'One server only',
            'tone' => $result['status'] === CheckStatus::Failed ? 'danger' : 'warning',
            'icon' => 'ShieldAlert',
        ], 'core.settings.view', 14);
    }

    /**
     * A PHP build with a known critical vulnerability (PhpSecurityRelease), a red health check in production only:
     * operators rarely re-run `app:install`, and development machines and CI do not serve public requests.
     */
    public function registerPhpRuntime(Dashboard $dashboard): void
    {
        $php = app(PhpSecurityRelease::class);
        $problem = $php->problem();

        if ($problem === null || ! app()->isProduction()) {
            return;
        }

        $dashboard->health('admin', 'platform.php_runtime', fn (): array => [
            'label' => 'PHP runtime',
            'detail' => $problem,
            'value' => 'Upgrade to '.$php->requiredUpgrade(),
            'tone' => 'danger',
            'icon' => 'ShieldAlert',
        ], 'core.settings.view', 9);
    }

    /**
     * Integration endpoint overrides (ADR 0016 §3), a health check only while INTEGRATION_ENDPOINTS is set: red outside a
     * development environment, where they are ignored, and amber in development, where requests go to simulators.
     * Keys only; the URLs are never shown.
     */
    public function registerIntegrationEndpoints(Dashboard $dashboard): void
    {
        $keys = app(Endpoints::class)->configuredKeys();

        if ($keys === []) {
            return;
        }

        $development = Endpoints::developmentEnvironment(app());

        $dashboard->health('admin', 'platform.integration_endpoints', fn (): array => [
            'label' => 'Integration endpoints',
            'detail' => $development
                ? 'Requests for '.implode(', ', $keys).' go to the addresses in INTEGRATION_ENDPOINTS.'
                : 'INTEGRATION_ENDPOINTS is set ('.implode(', ', $keys).') and ignored here. Remove it and INTEGRATION_CA_BUNDLE from the environment.',
            'value' => $development ? 'Overridden' : 'Remove overrides',
            'tone' => $development ? 'warning' : 'danger',
            'icon' => 'Globe',
        ], 'core.settings.view', 13);
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulerHealth(): array
    {
        $heartbeat = $this->automation->heartbeatAt();
        $minutes = $heartbeat?->diffInMinutes(now());
        [$value, $tone] = match (true) {
            $minutes === null => ['No heartbeat', 'warning'],
            $minutes < AutomationInsights::LATE_AFTER_MINUTES => ['OK', 'success'],
            $minutes < AutomationInsights::STOPPED_AFTER_MINUTES => ['Late', 'warning'],
            default => ['Stopped', 'danger'],
        };

        return [
            'label' => 'Scheduler',
            'detail' => $heartbeat === null ? 'The scheduler has not reported in yet' : 'Last heartbeat '.$heartbeat->diffForHumans(),
            'value' => $value,
            'tone' => $tone,
            'icon' => 'Clock',
            'href' => route('admin.platform.automation.index'),
        ];
    }

    /**
     * The daily automation pipeline summarised from each registered task's latest run (the per-task list is on the
     * automation page).
     *
     * @return array<string, mixed>
     */
    private function automationHealth(): array
    {
        $tasks = array_keys(app(Automation::class)->ordered());
        $last = AutomationRun::query()
            ->whereIn('id', AutomationRun::query()->selectRaw('max(id)')->groupBy('task'))
            ->pluck('status', 'task');
        $failed = count(array_filter($tasks, fn (string $task): bool => $last->get($task) === 'failed'));
        $succeeded = count(array_filter($tasks, fn (string $task): bool => $last->get($task) === 'success'));
        $notRun = count($tasks) - $failed - $succeeded;
        $lastRun = $this->automation->lastRunAt();
        $stale = $tasks !== [] && ($lastRun === null || $lastRun->lt(now()->subHours(AutomationStatus::STALE_AFTER_HOURS)));
        $failedNames = array_map(fn (string $task): string => app(Automation::class)->label($task), array_values(array_filter($tasks, fn (string $task): bool => $last->get($task) === 'failed')));
        $parts = array_filter([
            $succeeded > 0 ? $succeeded.' succeeded' : null,
            $failed > 0 ? $failed.' failed ('.implode(', ', array_slice($failedNames, 0, 2)).(count($failedNames) > 2 ? ', …' : '').')' : null,
            $notRun > 0 ? $notRun.' not run yet' : null,
        ]);

        return [
            'label' => 'Daily automation',
            'detail' => $tasks === []
                ? 'No daily tasks are registered'
                : trans_choice('{1} 1 task|[2,*] :count tasks', count($tasks)).': '.implode(', ', $parts).($lastRun === null ? '' : ' · last run '.$lastRun->diffForHumans()),
            'value' => match (true) {
                $failed > 0 => $failed.' failed',
                $stale && $lastRun !== null => 'Stale',
                $succeeded === 0 => 'Not run yet',
                $notRun > 0 => $succeeded.' / '.count($tasks),
                default => 'OK',
            },
            'tone' => match (true) {
                $failed > 0, $stale && $lastRun !== null => 'danger',
                $succeeded === 0 => 'neutral',
                $notRun > 0 => 'warning',
                default => 'success',
            },
            'icon' => 'Timer',
            'href' => route('admin.platform.automation.index'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueHealth(): array
    {
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $waiting = config('queue.default') === 'database' ? DB::table('jobs')->count() : null;

        return [
            'label' => 'Queue',
            'detail' => collect([
                $waiting === null ? 'Driver: '.config('queue.default') : $waiting.' waiting',
                trans_choice('{0} no failed jobs|{1} 1 failed job|[2,*] :count failed jobs', $failed).' in 24 hours',
            ])->implode(' · '),
            'value' => $failed > 0 ? $failed.' failed' : 'OK',
            'tone' => $failed > 0 ? 'danger' : 'success',
            'icon' => 'Layers',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recentActivity(): array
    {
        return [
            'title' => 'Recent activity',
            'group' => 'activity',
            'groupTitle' => 'Activity',
            'href' => route('admin.platform.activity.index'),
            'empty' => 'Changes by staff, clients and automation appear here.',
            'items' => ActivityLog::query()->with(['user:id,name', 'client:id,first_name,last_name,company_name'])->latest('id')->limit(6)->get()
                ->map(fn (ActivityLog $entry): array => [
                    'title' => $entry->description,
                    'subtitle' => collect([$entry->user->name ?? 'System', $entry->client?->display_name])->filter()->implode(' · '),
                    'meta' => $entry->created_at?->toIso8601String(),
                    'href' => $entry->client_id ? route('admin.clients.show', $entry->client_id) : null,
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function staffLogins(): array
    {
        return [
            'title' => 'Recent staff sign-ins',
            'tabTitle' => 'Staff sign-ins',
            'group' => 'activity',
            'groupTitle' => 'Activity',
            'href' => route('admin.platform.staff.index'),
            'empty' => 'No staff have signed in yet.',
            'items' => User::query()->where('is_staff', true)->whereNotNull('last_login_at')->with('role:id,name')
                ->orderByDesc('last_login_at')->orderBy('id')->limit(5)->get()
                ->map(fn (User $user): array => [
                    'title' => $user->name,
                    'subtitle' => $user->role->name ?? '',
                    'meta' => $user->last_login_at?->toIso8601String(),
                    'status' => $user->is_active ? null : 'inactive',
                ])->all(),
        ];
    }
}
