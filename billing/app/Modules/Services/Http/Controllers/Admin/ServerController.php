<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\SaveServer;
use App\Modules\Services\Drivers\Provisioning\DriverRegistry;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Http\Requests\ServerRequest;
use App\Modules\Services\Http\Resources\ServerResource;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Support\Integrations\Redactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ServerController extends Controller
{
    public function index(DriverRegistry $drivers): Response
    {
        $this->authorize('services.servers.view');

        return Inertia::render('admin/services/servers/index', [
            'servers' => ServerResource::collection(Server::query()->with('groups')->withActiveAccounts()->orderBy('name')->orderBy('id')->get()),
            'groups' => ServerGroup::query()->withCount('servers')->orderBy('name')->orderBy('id')->get()->map(fn (ServerGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'fill_type' => $group->fill_type->value,
                'fill_type_label' => $group->fill_type->label(),
                'servers_count' => (int) $group->getAttribute('servers_count'),
            ]),
            'drivers' => $drivers->options(),
            'canManage' => request()->user()?->hasPermission('services.servers.manage') ?? false,
        ]);
    }

    public function create(DriverRegistry $drivers): Response
    {
        $this->authorize('services.servers.manage');

        return Inertia::render('admin/services/servers/form', $this->formOptions($drivers));
    }

    public function store(ServerRequest $request, SaveServer $save): RedirectResponse
    {
        $server = $save->handle(null, $request->serverData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server :name created.', ['name' => $server->name])]);

        return to_route('admin.services.servers.index');
    }

    public function edit(Server $server, DriverRegistry $drivers): Response
    {
        $this->authorize('services.servers.manage');

        return Inertia::render('admin/services/servers/form', [
            'server' => new ServerResource(Server::query()->withActiveAccounts()->findOrFail($server->id)),
            ...$this->formOptions($drivers),
        ]);
    }

    public function update(ServerRequest $request, Server $server, SaveServer $save): RedirectResponse
    {
        $save->handle($server, $request->serverData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server :name updated.', ['name' => $server->name])]);

        return to_route('admin.services.servers.index');
    }

    public function destroy(Server $server): RedirectResponse
    {
        $this->authorize('services.servers.manage');

        if ($server->services()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['server' => __('Move the services on :name to another server before deleting it.', ['name' => $server->name])]);
        }

        $server->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server :name deleted.', ['name' => $server->name])]);

        return to_route('admin.services.servers.index');
    }

    public function test(Server $server, DriverRegistry $drivers, Redactor $redactor): RedirectResponse
    {
        $this->authorize('services.servers.manage');

        try {
            $result = $drivers->get($server->driver)->testConnection($server);
        } catch (Throwable $exception) {
            report(new \RuntimeException($redactor->maskValues($exception->getMessage(), $server->secrets())));
            $result = null;
        }

        if ($result === null || ! $result->success) {
            $error = $redactor->maskValues((string) ($result->message ?? __('unexpected error')), $server->secrets());

            throw ValidationException::withMessages(['server' => __('Connection to :host failed: :error', ['host' => $server->hostname, 'error' => $error])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result->message ?? __('Connection succeeded.')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(DriverRegistry $drivers): array
    {
        return [
            'drivers' => $drivers->options(),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code'),
            'fillTypes' => array_map(fn (FillType $type): array => ['value' => $type->value, 'label' => $type->label()], FillType::cases()),
        ];
    }
}
