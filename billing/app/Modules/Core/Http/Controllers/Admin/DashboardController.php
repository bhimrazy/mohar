<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Contracts\Core\CompanyClock;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\StaffNotice;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin dashboard props from the kernel registry: `todos` (Needs attention) and eager `widgets` on first paint,
 * then `deferredWidgets` and `health` in one deferred request. Every prop is a closure, so a partial reload
 * evaluates only the props it asks for. `notices` are one-time messages the signed-in person has not dismissed.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly CompanyClock $clock) {}

    public function __invoke(Request $request, Dashboard $dashboard): Response
    {
        $user = $request->user();

        return Inertia::render('admin/dashboard', [
            'notices' => fn (): array => $this->notices($request),
            'todos' => fn (): array => $dashboard->resolveTodos('admin', $user),
            'widgets' => fn (): array => $dashboard->resolveWidgets('admin', $user, false),
            'deferredWidgets' => Inertia::defer(fn (): array => $dashboard->resolveWidgets('admin', $user, true)),
            'health' => Inertia::defer(fn (): array => $dashboard->resolveHealth('admin', $user)),
        ]);
    }

    /**
     * @return list<array{key: string, title: string, body: string, links: list<array{label: string, href: string}>}>
     */
    private function notices(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        $notices = [];

        foreach (StaffNotice::query()->where('user_id', $user->id)->whereNull('dismissed_at')->pluck('notice') as $notice) {
            if (! StaffNotice::exists($notice) || ! $user->hasPermission((string) StaffNotice::permissionFor($notice))) {
                continue;
            }

            if ($notice === StaffNotice::COMPANY_TIME_SETTINGS) {
                $notices[] = $this->companyTimeNotice($user);
            }
        }

        return $notices;
    }

    /**
     * @return array{key: string, title: string, body: string, links: list<array{label: string, href: string}>}
     */
    private function companyTimeNotice(User $user): array
    {
        $links = [];

        if (Route::has('admin.platform.settings.edit')) {
            $links[] = ['label' => __('Automation time'), 'href' => route('admin.platform.settings.edit', 'company')];

            if ($user->hasPermission('core.currencies.rates')) {
                $links[] = ['label' => __('Rate update time'), 'href' => route('admin.platform.settings.edit', 'exchange_rates')];
            }
        }

        return [
            'key' => StaffNotice::COMPANY_TIME_SETTINGS,
            'title' => __('Check your automation times'),
            'body' => __('Daily automation time and Rate update time now use your company time zone (:zone). We did not change either value. If you set them before this update, check they still run when you expect.', ['zone' => $this->clock->timezone()]),
            'links' => $links,
        ];
    }
}
