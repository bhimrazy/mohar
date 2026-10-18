<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin dashboard props from the kernel registry: `todos` (Needs attention) and eager `widgets` on first paint,
 * then `deferredWidgets` and `health` in one deferred request. Every prop is a closure, so a partial reload
 * evaluates only the props it asks for.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, Dashboard $dashboard): Response
    {
        $user = $request->user();

        return Inertia::render('admin/dashboard', [
            'todos' => fn (): array => $dashboard->resolveTodos('admin', $user),
            'widgets' => fn (): array => $dashboard->resolveWidgets('admin', $user, false),
            'deferredWidgets' => Inertia::defer(fn (): array => $dashboard->resolveWidgets('admin', $user, true)),
            'health' => Inertia::defer(fn (): array => $dashboard->resolveHealth('admin', $user)),
        ]);
    }
}
