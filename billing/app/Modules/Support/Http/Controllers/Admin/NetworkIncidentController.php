<?php

namespace App\Modules\Support\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Support\Actions\ManageIncident;
use App\Modules\Support\Enums\IncidentPriority;
use App\Modules\Support\Enums\IncidentStatus;
use App\Modules\Support\Enums\IncidentType;
use App\Modules\Support\Http\Requests\NetworkIncidentRequest;
use App\Modules\Support\Models\NetworkIncident;
use App\Modules\Support\Models\NetworkIncidentUpdate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NetworkIncidentController extends Controller
{
    public function index(): Response
    {
        $this->authorize('support.network.manage');

        $incidents = NetworkIncident::query()
            ->orderByRaw('case when status = ? then 1 else 0 end', [IncidentStatus::Resolved->value])
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->paginate(25);

        return Inertia::render('admin/support/network/index', [
            'incidents' => $incidents->through(fn (NetworkIncident $incident): array => [
                'id' => $incident->id,
                'title' => $incident->title,
                'type' => $incident->type->value,
                'affected' => $incident->affected,
                'priority' => $incident->priority->value,
                'status' => $incident->status->value,
                'starts_at' => $incident->starts_at->toIso8601String(),
                'ends_at' => $incident->ends_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('support.network.manage');

        return Inertia::render('admin/support/network/form', $this->options());
    }

    public function store(NetworkIncidentRequest $request, ManageIncident $manage): RedirectResponse
    {
        $incident = $manage->open($request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Incident ":title" published.', ['title' => $incident->title])]);

        return to_route('admin.support.network.edit', $incident);
    }

    public function edit(NetworkIncident $incident): Response
    {
        $this->authorize('support.network.manage');

        return Inertia::render('admin/support/network/form', [
            'incident' => [
                ...$incident->only(['id', 'title', 'affected', 'server_id', 'description']),
                'type' => $incident->type->value,
                'priority' => $incident->priority->value,
                'status' => $incident->status->value,
                'starts_at' => $incident->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $incident->ends_at?->format('Y-m-d\TH:i'),
            ],
            'updates' => $incident->updates()->get()->map(fn (NetworkIncidentUpdate $update): array => [
                'id' => $update->id,
                'body' => $update->body,
                'status' => $update->status->value,
                'created_at' => $update->created_at?->toIso8601String(),
            ]),
            ...$this->options(),
        ]);
    }

    public function update(NetworkIncidentRequest $request, NetworkIncident $incident, ManageIncident $manage): RedirectResponse
    {
        $manage->update($incident, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Incident ":title" updated.', ['title' => $incident->title])]);

        return to_route('admin.support.network.edit', $incident);
    }

    public function addUpdate(Request $request, NetworkIncident $incident, ManageIncident $manage): RedirectResponse
    {
        $this->authorize('support.network.manage');
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['required', Rule::enum(IncidentStatus::class)],
        ]);

        $manage->addUpdate($incident, $validated['body'], IncidentStatus::from($validated['status']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Update posted.')]);

        return to_route('admin.support.network.edit', $incident);
    }

    public function resolve(NetworkIncident $incident, ManageIncident $manage): RedirectResponse
    {
        $this->authorize('support.network.manage');
        $manage->resolve($incident);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Incident ":title" resolved.', ['title' => $incident->title])]);

        return back();
    }

    public function destroy(NetworkIncident $incident): RedirectResponse
    {
        $this->authorize('support.network.manage');
        $incident->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Incident ":title" deleted.', ['title' => $incident->title])]);

        return to_route('admin.support.network.index');
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(): array
    {
        return [
            'types' => IncidentType::options(),
            'priorities' => IncidentPriority::options(),
            'statuses' => IncidentStatus::options(),
        ];
    }
}
