<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\AutomationStatus;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin dashboard: "Needs attention" to-dos, then every widget the staff member may see
 * (stat / list / chart). Heavy widgets are deferred and load after first paint.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, Dashboard $dashboard, AutomationStatus $automation): Response
    {
        $user = $request->user();

        return Inertia::render('admin/dashboard', [
            'todos' => $dashboard->resolveTodos('admin', $user),
            'widgets' => $dashboard->resolveWidgets('admin', $user, false),
            'deferredWidgets' => Inertia::defer(fn (): array => $dashboard->resolveWidgets('admin', $user, true)),
            'automation' => $user?->hasPermission('core.automation.view') ? $automation->summary() : null,
        ]);
    }
}
