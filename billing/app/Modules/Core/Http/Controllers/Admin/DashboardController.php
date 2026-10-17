<?php

namespace App\Modules\Core\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ActivityLog;
use App\Support\Dashboard\Dashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Dashboard $dashboard): Response
    {
        $canViewActivity = $request->user()?->hasPermission('core.activity_log.view') ?? false;

        return Inertia::render('admin/dashboard', [
            'cards' => $dashboard->resolve('admin', $request->user()),
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
        ]);
    }
}
