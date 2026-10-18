<?php

namespace App\Modules\Support\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Models\NetworkIncident;
use App\Modules\Support\Models\NetworkIncidentUpdate;
use App\Modules\Support\Services\MarkdownRenderer;
use App\Modules\Support\Services\SupportSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Network status: open incidents first (most recently updated), then incidents resolved in the
 * last 30 days. Login required when support.network_status_requires_login is on.
 */
class NetworkStatusController extends Controller
{
    public function __invoke(Request $request, SupportSettings $settings, MarkdownRenderer $markdown): Response|RedirectResponse
    {
        if ($request->user() === null && $settings->bool('network_status_requires_login', true)) {
            return redirect()->guest(route('login'));
        }

        $present = fn (NetworkIncident $incident): array => [
            'id' => $incident->id,
            'title' => $incident->title,
            'type' => $incident->type->value,
            'affected' => $incident->affected,
            'priority' => $incident->priority->value,
            'status' => $incident->status->value,
            'description_html' => $markdown->render($incident->description),
            'starts_at' => $incident->starts_at->toIso8601String(),
            'ends_at' => $incident->ends_at?->toIso8601String(),
            'updated_at' => $incident->updated_at?->toIso8601String(),
            'updates' => $incident->updates->map(fn (NetworkIncidentUpdate $update): array => [
                'id' => $update->id,
                'body_html' => $markdown->render($update->body),
                'status' => $update->status->value,
                'created_at' => $update->created_at?->toIso8601String(),
            ])->all(),
        ];

        return Inertia::render('public/support/network', [
            'open' => NetworkIncident::query()->with('updates')->where('status', '!=', IncidentStatus::Resolved->value)
                ->orderByDesc('updated_at')->orderByDesc('id')->get()->map($present),
            'resolved' => NetworkIncident::query()->with('updates')->where('status', IncidentStatus::Resolved->value)
                ->where('ends_at', '>=', now()->subDays(30))
                ->orderByDesc('ends_at')->orderByDesc('id')->get()->map($present),
        ]);
    }
}
