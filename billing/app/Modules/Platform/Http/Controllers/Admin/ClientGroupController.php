<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ClientGroup;
use App\Modules\Platform\Actions\ClientGroups\SaveClientGroup;
use App\Modules\Platform\Http\Requests\ClientGroupRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ClientGroupController extends Controller
{
    public function index(): Response
    {
        $this->authorize('core.client_groups.manage');

        return Inertia::render('admin/platform/client-groups/index', [
            'groups' => ClientGroup::query()->withCount('clients')->orderBy('name')->orderBy('id')->get()->map(fn (ClientGroup $group): array => [
                ...$this->present($group),
                'clients_count' => $group->clients_count,
            ]),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('core.client_groups.manage');

        return Inertia::render('admin/platform/client-groups/form');
    }

    public function store(ClientGroupRequest $request, SaveClientGroup $save): RedirectResponse
    {
        $group = $save->handle(null, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client group :name created.', ['name' => $group->name])]);

        return to_route('admin.platform.client-groups.index');
    }

    public function edit(ClientGroup $clientGroup): Response
    {
        $this->authorize('core.client_groups.manage');

        return Inertia::render('admin/platform/client-groups/form', ['group' => $this->present($clientGroup)]);
    }

    public function update(ClientGroupRequest $request, ClientGroup $clientGroup, SaveClientGroup $save): RedirectResponse
    {
        $save->handle($clientGroup, $this->payload($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client group :name saved.', ['name' => $clientGroup->name])]);

        return to_route('admin.platform.client-groups.index');
    }

    public function destroy(ClientGroup $clientGroup, SaveClientGroup $save): RedirectResponse
    {
        $this->authorize('core.client_groups.manage');

        $save->delete($clientGroup);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client group :name deleted.', ['name' => $clientGroup->name])]);

        return to_route('admin.platform.client-groups.index');
    }

    /**
     * @return array{name: string, colour: ?string, discount_percent: string, exempt_from_suspension: bool, separate_invoices: bool}
     */
    private function payload(ClientGroupRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'colour' => $request->filled('colour') ? strtolower($request->string('colour')->toString()) : null,
            'discount_percent' => $request->string('discount_percent')->toString(),
            'exempt_from_suspension' => $request->boolean('exempt_from_suspension'),
            'separate_invoices' => $request->boolean('separate_invoices'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ClientGroup $group): array
    {
        return $group->only(['id', 'name', 'colour', 'discount_percent', 'exempt_from_suspension', 'separate_invoices']);
    }
}
