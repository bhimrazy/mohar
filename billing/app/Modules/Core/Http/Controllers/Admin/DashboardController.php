<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
     * @return list<array{key: string, title: string, body: string, href: string|null}>
     */
    private function notices(Request $request): array
    {
        $user = $request->user();

        if ($user === null || ! $user->hasPermission('core.settings.update')) {
            return [];
        }

        $pending = StaffNotice::query()
            ->where('user_id', $user->id)
            ->where('notice', StaffNotice::COMPANY_TIME_SETTINGS)
            ->whereNull('dismissed_at')
            ->exists();

        if (! $pending) {
            return [];
        }

        return [[
            'key' => StaffNotice::COMPANY_TIME_SETTINGS,
            'title' => __('Check your automation times'),
            'body' => __('"Daily automation time" and "Rate update time" now mean your company time, not UTC. We did not change any value. If you set one while the server clock was UTC, open Settings and check that it still runs when you want.'),
            'href' => Route::has('admin.platform.settings.edit') ? route('admin.platform.settings.edit', 'company') : null,
        ]];
    }
}
