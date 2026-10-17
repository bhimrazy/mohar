<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('core.activity_log.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'event' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'integer'],
            'client' => ['nullable', 'integer'],
            'ip' => ['nullable', 'ip'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $entries = ActivityLog::query()
            ->with(['user:id,name,email', 'impersonator:id,name', 'client:id,first_name,last_name,company_name'])
            ->when($filters['search'] ?? null, fn ($query, string $term) => $query->whereLike('description', '%'.$term.'%'))
            ->when($filters['event'] ?? null, fn ($query, string $event) => $query->where('event', $event))
            ->when($filters['user'] ?? null, fn ($query, $user) => $query->where('user_id', (int) $user))
            ->when($filters['client'] ?? null, fn ($query, $client) => $query->where('client_id', (int) $client))
            ->when($filters['ip'] ?? null, fn ($query, string $ip) => $query->where('ip_address', $ip))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/platform/activity/index', [
            'entries' => $entries->through(fn (ActivityLog $entry): array => [
                'id' => $entry->id,
                'description' => $entry->description,
                'event' => $entry->event,
                'actor_type' => $entry->actor_type,
                'actor' => $entry->user ? ['id' => $entry->user->id, 'name' => $entry->user->name] : null,
                'impersonator' => $entry->impersonator?->name,
                'client' => $entry->client ? ['id' => $entry->client->id, 'name' => $entry->client->display_name] : null,
                'ip_address' => $entry->ip_address,
                'properties' => $entry->properties,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]),
            'filters' => $filters,
            'events' => ActivityLog::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event'),
            'staff' => User::query()->where('is_staff', true)->orderBy('name')->orderBy('id')->get(['id', 'name']),
        ]);
    }
}
