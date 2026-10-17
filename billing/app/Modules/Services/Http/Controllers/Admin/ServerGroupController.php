<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Http\Requests\ServerGroupRequest;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ServerGroupController extends Controller
{
    public function create(): Response
    {
        $this->authorize('services.servers.manage');

        return Inertia::render('admin/services/server-groups/form', $this->formOptions());
    }

    public function store(ServerGroupRequest $request): RedirectResponse
    {
        $group = DB::transaction(function () use ($request): ServerGroup {
            $group = ServerGroup::query()->create($request->safe()->only(['name', 'fill_type']));
            $this->syncServers($group, $request->input('server_ids', []));

            return $group;
        });

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

    public function update(ServerGroupRequest $request, ServerGroup $serverGroup): RedirectResponse
    {
        DB::transaction(function () use ($request, $serverGroup): void {
            $serverGroup->update($request->safe()->only(['name', 'fill_type']));
            $this->syncServers($serverGroup, $request->input('server_ids', []));
        });

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
     * @param  array<int, int|string>  $serverIds  in fill order
     */
    private function syncServers(ServerGroup $group, array $serverIds): void
    {
        $group->servers()->sync(collect(array_values($serverIds))->mapWithKeys(fn ($id, int $index): array => [(int) $id => ['sort_order' => $index]])->all());
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
