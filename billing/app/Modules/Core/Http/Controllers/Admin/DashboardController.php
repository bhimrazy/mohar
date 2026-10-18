<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ActivityLog;
use App\Modules\Core\Support\AutomationStatus;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin dashboard props: stat `cards` and recent `activity` (current page), plus the full kernel
 * registry output for the dashboard UI — `widgets` (stat / list / chart), `deferredWidgets` (heavy,
 * loaded after first paint), `todos` ("Needs attention") and the automation health summary.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, Dashboard $dashboard, AutomationStatus $automation): Response
    {
        $user = $request->user();

        $canViewActivity = $user?->hasPermission('core.activity_log.view') ?? false;

        return Inertia::render('admin/dashboard', [
            'cards' => $dashboard->resolve('admin', $user),
            'activity' => $canViewActivity
                ? ActivityLog::query()->with(['user:id,name', 'client:id,first_name,last_name,company_name'])->latest('id')->limit(10)->get()
                    ->map(fn (ActivityLog $entry): array => [
                        'id' => $entry->id,
                        'description' => $entry->description,
                        'actor' => $entry->user?->name,
                        'client' => $entry->client ? ['id' => $entry->client->id, 'name' => $entry->client->display_name] : null,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ])
                : null,
            'todos' => $dashboard->resolveTodos('admin', $user),
            'widgets' => $dashboard->resolveWidgets('admin', $user, false),
            'deferredWidgets' => Inertia::defer(fn (): array => $dashboard->resolveWidgets('admin', $user, true)),
            'automation' => $user?->hasPermission('core.automation.view') ? $automation->summary() : null,
        ]);
    }
}
