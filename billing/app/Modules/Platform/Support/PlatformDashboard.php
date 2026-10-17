<?php

namespace App\Modules\Platform\Support;

use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\AutomationStatus;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Models\StaffInvitation;
use App\Support\Automation\Automation;
use App\Support\Dashboard\Dashboard;

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
        ], 'core.automation.view', 10);

        $dashboard->todo('admin', 'platform.automation.stale', fn (): array => [
            'label' => 'Daily automation has not run for over '.AutomationStatus::STALE_AFTER_HOURS.' hours',
            'count' => app(Automation::class)->ordered() !== [] && $this->automation->isStale() ? 1 : 0,
            'href' => route('admin.platform.automation.index'),
            'tone' => 'danger',
        ], 'core.automation.view', 11);

        $dashboard->todo('admin', 'platform.staff.no_2fa', fn (): array => [
            'label' => 'Staff without two-factor authentication',
            'count' => (bool) $this->settings->get('security.staff_2fa_required', true)
                ? User::query()->where('is_staff', true)->where('is_active', true)->whereNull('two_factor_confirmed_at')->count()
                : 0,
            'href' => route('admin.platform.staff.index', ['two_factor' => 'off']),
            'tone' => 'warning',
        ], 'core.staff.view', 20);

        $dashboard->todo('admin', 'platform.invitations.expiring', fn (): array => [
            'label' => 'Staff invitations expiring within 48 hours',
            'count' => StaffInvitation::query()->pending()->where('expires_at', '>', now())->where('expires_at', '<=', now()->addHours(48))->count(),
            'href' => route('admin.platform.staff.index'),
            'tone' => 'warning',
        ], 'core.staff.view', 21);

        $dashboard->widget('admin', 'platform.automation', 'list', fn (): array => $this->automationHealth(), 'core.automation.view', 60, 'md');
        $dashboard->widget('admin', 'platform.activity', 'list', fn (): array => $this->recentActivity(), 'core.activity_log.view', 50, 'md');
        $dashboard->widget('admin', 'platform.staff.logins', 'list', fn (): array => $this->staffLogins(), 'core.staff.view', 70, 'md');
    }

    /**
     * @return array<string, mixed>
     */
    private function automationHealth(): array
    {
        $last = AutomationRun::query()
            ->whereIn('id', AutomationRun::query()->selectRaw('max(id)')->groupBy('task'))
            ->get()
            ->keyBy('task');

        return [
            'title' => 'Automation',
            'href' => route('admin.platform.automation.index'),
            'empty' => 'Modules add their daily tasks here.',
            'items' => array_map(fn (string $task): array => [
                'title' => $task,
                'subtitle' => $last->has($task) ? 'Last run '.$last[$task]->run_date->toFormattedDateString() : 'Not run yet',
                'status' => $last[$task]->status ?? 'pending',
                'href' => route('admin.platform.automation.index', ['task' => $task]),
            ], array_keys(app(Automation::class)->ordered())),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recentActivity(): array
    {
        return [
            'title' => 'Recent activity',
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
