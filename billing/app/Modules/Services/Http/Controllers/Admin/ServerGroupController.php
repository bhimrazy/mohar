<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Services\Actions\SaveServerGroup;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Http\Requests\ServerGroupRequest;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ServerGroupController extends Controller
{
    public function create(): Response
    {
        $this->authorize('services.servers.manage');

        return Inertia::render('admin/services/server-groups/form', $this->formOptions());
    }

    public function store(ServerGroupRequest $request, SaveServerGroup $save): RedirectResponse
    {
        $group = $save->handle(null, $request->safe()->only(['name', 'fill_type']), $request->input('server_ids', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server group :name created.', ['name' => $group->name])]);

        return to_route('admin.services.servers.index');
    }

    public function edit(ServerGroup $serverGroup): Response
    {
        $this->authorize('services.servers.manage');

        return Inertia::render('admin/services/server-groups/form', [
            'group' => [
                'id' => $serverGroup->id,
                'name' => $serverGroup->name,
                'fill_type' => $serverGroup->fill_type->value,
                'server_ids' => $serverGroup->servers()->pluck('servers.id'),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(ServerGroupRequest $request, ServerGroup $serverGroup, SaveServerGroup $save): RedirectResponse
    {
        $save->handle($serverGroup, $request->safe()->only(['name', 'fill_type']), $request->input('server_ids', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server group :name updated.', ['name' => $serverGroup->name])]);

        return to_route('admin.services.servers.index');
    }

    public function destroy(ServerGroup $serverGroup): RedirectResponse
    {
        $this->authorize('services.servers.manage');

        $serverGroup->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server group :name deleted.', ['name' => $serverGroup->name])]);

        return to_route('admin.services.servers.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'fillTypes' => array_map(fn (FillType $type): array => ['value' => $type->value, 'label' => $type->label()], FillType::cases()),
            'servers' => Server::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'hostname'])->map(fn (Server $server): array => ['value' => (string) $server->id, 'label' => "{$server->name} ({$server->hostname})"]),
        ];
    }
}
