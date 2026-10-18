<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Contracts\Services\Provisioning\TestsServerConnection;
use App\Data\Integrations\SettingField;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\SaveServer;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Http\Requests\ServerRequest;
use App\Modules\Services\Http\Resources\ServerResource;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ServerController extends Controller
{
    public function index(ProvisioningDriverRegistry $drivers): Response
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
            'drivers' => $this->driverOptions($drivers),
            'canManage' => request()->user()?->hasPermission('services.servers.manage') ?? false,
        ]);
    }

    public function create(ProvisioningDriverRegistry $drivers): Response
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

    public function edit(Server $server, ProvisioningDriverRegistry $drivers): Response
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

    public function test(Server $server, ProvisioningDriverRegistry $drivers, ProvisioningSnapshots $snapshots, Redactor $redactor): RedirectResponse
    {
        $this->authorize('services.servers.manage');
        $connection = null;

        try {
            $connection = $snapshots->connection($server);
            $driver = $drivers->get($server->driver);

            if (! $driver instanceof TestsServerConnection) {
                Inertia::flash('toast', ['type' => 'success', 'message' => __(':driver servers have nothing to connect to.', ['driver' => $driver->name()])]);

                return back();
            }

            $check = $driver->testConnection($connection);
        } catch (Throwable $exception) {
            report(new \RuntimeException($redactor->maskValues($exception->getMessage(), $connection?->secrets() ?? [])));
            $check = null;
        }

        if ($check === null || ! $check->ok) {
            $error = $redactor->maskValues($check->message ?? __('unexpected error'), $connection?->secrets() ?? []);

            throw ValidationException::withMessages(['server' => __('Connection to :host failed: :error', ['host' => $server->hostname, 'error' => $error])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $redactor->maskValues($check->message, $connection->secrets())]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(ProvisioningDriverRegistry $drivers): array
    {
        return [
            'drivers' => $this->driverOptions($drivers),
            'driverFields' => array_combine($drivers->keys(), array_map(
                fn (string $key): array => array_map(fn (SettingField $field): array => $field->toArray(), $drivers->metadata($key)->serverFields),
                $drivers->keys(),
            )),
            'currencies' => Currency::query()->orderByDesc('is_default')->orderBy('code')->pluck('code'),
            'fillTypes' => array_map(fn (FillType $type): array => ['value' => $type->value, 'label' => $type->label()], FillType::cases()),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function driverOptions(ProvisioningDriverRegistry $drivers): array
    {
        return array_map(fn (string $key): array => ['value' => $key, 'label' => $drivers->get($key)->name()], $drivers->keys());
    }
}
