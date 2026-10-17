<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Dashboard $dashboard): Response
    {
        $user = $request->user();

        return Inertia::render('admin/dashboard', [
            // Stat cards (kept for simple consumers) and every eager widget in order.
            'cards' => $dashboard->resolve('admin', $user),
            'widgets' => $dashboard->resolveWidgets('admin', $user, false),
            'deferredWidgets' => Inertia::defer(fn (): array => $dashboard->resolveWidgets('admin', $user, true)),
            'todos' => $dashboard->resolveTodos('admin', $user),
        ]);
    }
}
