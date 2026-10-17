<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Drivers\Provisioning\DriverRegistry;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Http\Requests\ServerRequest;
use App\Modules\Services\Http\Resources\ServerResource;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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

    public function store(ServerRequest $request): RedirectResponse
    {
        $server = DB::transaction(function () use ($request): Server {
            $server = Server::query()->create($request->serverData());
            $this->keepSingleDefault($server);

            return $server;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Server :name created.', ['name' => $server->name])]);

        return to_route('admin.services.servers.index');
    }

    public function edit(Server $server, DriverRegistry $drivers): Response
    {
        $this->authorize('services.servers.manage');

        return Inertia::render('admin/services/servers/form', [
            'server' => new ServerResource($server->loadCount(['services as active_accounts_count'])),
            ...$this->formOptions($drivers),
        ]);
    }

    public function update(ServerRequest $request, Server $server): RedirectResponse
    {
        DB::transaction(function () use ($request, $server): void {
            $server->update($request->serverData());
            $this->keepSingleDefault($server);
        });

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

    public function test(Server $server, DriverRegistry $drivers): RedirectResponse
    {
        $this->authorize('services.servers.manage');

        try {
            $result = $drivers->get($server->driver)->testConnection($server);
        } catch (Throwable $exception) {
            report($exception);
            $result = null;
        }

        if ($result === null || ! $result->success) {
            throw ValidationException::withMessages(['server' => __('Connection to :host failed: :error', ['host' => $server->hostname, 'error' => $result->message ?? __('unexpected error')])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $result->message ?? __('Connection succeeded.')]);

        return back();
    }

    private function keepSingleDefault(Server $server): void
    {
        if ($server->is_default) {
            Server::query()->where('driver', $server->driver)->whereKeyNot($server->id)->update(['is_default' => false]);
        }
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
