<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Contracts\Services\Provisioning\ListsAccounts;
use App\Contracts\Services\Provisioning\TestsServerConnection;
use App\Data\Integrations\ConnectionCheckStep;
use App\Data\Integrations\SettingField;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Currency;
use App\Modules\Services\Actions\SaveServer;
use App\Modules\Services\Enums\FillType;
use App\Modules\Services\Http\Requests\ServerRequest;
use App\Modules\Services\Http\Resources\ServerResource;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\ServerGroup;
use App\Modules\Services\Services\ProvisioningSnapshots;
use App\Modules\Services\Support\ServerSettings;
use App\Support\Integrations\Redactor;
use App\Support\Services\ProvisioningDriverRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ServerController extends Controller
{
    public function index(ProvisioningDriverRegistry $drivers): Response
    {
        $this->authorize('services.servers.view');

        $servers = Server::query()->with('groups')->withActiveAccounts()->orderBy('name')->orderBy('id')->get();

        return Inertia::render('admin/services/servers/index', [
            'servers' => ServerResource::collection($servers),
            'summary' => $this->summary($servers),
            'groups' => ServerGroup::query()->withCount('servers')->orderBy('name')->orderBy('id')->get()->map(fn (ServerGroup $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'fill_type' => $group->fill_type->value,
                'fill_type_label' => $group->fill_type->label(),
                'servers_count' => (int) $group->getAttribute('servers_count'),
            ]),
            'drivers' => $this->driverOptions($drivers),
            'canManage' => request()->user()?->hasPermission('services.servers.manage') ?? false,
            'canViewModuleLog' => request()->user()?->hasPermission('services.module_log.view') ?? false,
            'importDrivers' => request()->user()?->hasPermission('services.servers.import')
                ? array_values(array_filter($drivers->keys(), fn (string $key): bool => $drivers->get($key) instanceof ListsAccounts))
                : [],
        ]);
    }

    /**
     * The cards above the list: servers in use, accounts against capacity, servers nearly full, and failed module
     * commands in the last 24 hours (read through the `created_at` index of module_commands).
     *
     * @param  Collection<int, Server>  $servers
     * @return array{servers: int, active: int, accounts: int, capacity: int, unlimited: bool, near_full: int, failed_commands: int, failing_servers: int}
     */
    private function summary(Collection $servers): array
    {
        $active = $servers->where('is_active', true);
        $failed = ModuleCommand::query()->whereNotNull('server_id')->where('created_at', '>=', now()->subDay())->where('success', false)
            ->selectRaw('count(*) as failures, count(distinct server_id) as servers')->first();

        return [
            'servers' => $servers->count(),
            'active' => $active->count(),
            'accounts' => (int) $servers->sum(fn (Server $server): int => (int) $server->active_accounts_count),
            'capacity' => (int) $active->sum('max_accounts'),
            'unlimited' => $active->contains(fn (Server $server): bool => $server->max_accounts === 0),
            'near_full' => $active->filter(fn (Server $server): bool => $server->max_accounts > 0 && (int) $server->active_accounts_count >= 0.9 * $server->max_accounts)->count(),
            'failed_commands' => (int) $failed?->getAttribute('failures'),
            'failing_servers' => (int) $failed?->getAttribute('servers'),
        ];
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
        // A certificate offered by an earlier check belongs to the address that was checked, not to an edited one.
        $request->session()->forget(self::offeredPinKey($server));

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

    /**
     * Run the driver's connection check and show its checklist on the server form. A certificate the server presents
     * but that is not trusted is remembered for this session only, so "Trust this certificate" can pin exactly the
     * fingerprint staff were shown.
     */
    public function test(Request $request, Server $server, ProvisioningDriverRegistry $drivers, ProvisioningSnapshots $snapshots, Redactor $redactor, ServerSettings $settings): RedirectResponse
    {
        $this->authorize('services.servers.manage');
        $connection = null;
        $request->session()->forget(self::offeredPinKey($server));

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

        $mask = fn (string $value): string => $redactor->maskValues($value, $connection?->secrets() ?? []);
        $redirect = to_route('admin.services.servers.edit', $server);

        if ($check !== null) {
            $offered = $check->details['certificate_spki_sha256'] ?? null;

            if (is_string($offered) && $settings->declares($server->driver, ServerSettings::CERTIFICATE_PIN)) {
                $request->session()->put(self::offeredPinKey($server), ['fingerprint' => $offered, 'address' => self::address($server)]);
            }

            Inertia::flash('connectionCheck', [
                'ok' => $check->ok,
                'message' => $mask($check->message),
                'steps' => array_map(fn (ConnectionCheckStep $step): array => [
                    'label' => $step->label,
                    'status' => $step->status->value,
                    'detail' => $step->detail === null ? null : $mask($step->detail),
                ], $check->steps),
                'details' => array_map($mask, $check->details),
                'can_trust' => $request->session()->has(self::offeredPinKey($server)),
                'checked_at' => now()->toIso8601String(),
            ]);
        }

        if ($check === null || ! $check->ok) {
            return $redirect->withErrors(['server' => __('Connection to :host failed: :error', ['host' => $server->hostname, 'error' => $mask($check->message ?? __('unexpected error'))])]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $mask($check->message)]);

        return $redirect;
    }

    /**
     * "Trust this certificate": pin the fingerprint Test connection showed (spec §9.3). Only that fingerprint, from
     * this session's last check of this server, can be pinned, and only once; the change is audited.
     */
    public function trustCertificate(Request $request, Server $server, ServerSettings $settings): RedirectResponse
    {
        $this->authorize('services.servers.manage');

        $fingerprint = (string) $request->validate([
            'fingerprint' => ['required', 'string', 'regex:#^[A-Za-z0-9+/]{43}=\z#'],
        ])['fingerprint'];
        $offered = $request->session()->pull(self::offeredPinKey($server));

        if (! $settings->declares($server->driver, ServerSettings::CERTIFICATE_PIN)) {
            throw ValidationException::withMessages(['fingerprint' => __('This server\'s driver does not support trusted certificates.')]);
        }

        // Only the fingerprint this session's last check of this exact address showed, and only once.
        if (! is_array($offered) || ! is_string($offered['fingerprint'] ?? null) || ($offered['address'] ?? null) !== self::address($server) || ! hash_equals($offered['fingerprint'], $fingerprint)) {
            throw ValidationException::withMessages(['fingerprint' => __('The certificate could not be trusted: run Test connection again and trust the certificate it shows.')]);
        }

        $settings->put($server, ServerSettings::CERTIFICATE_PIN, $fingerprint, __('Trusted the certificate shown by Test connection'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Certificate trusted for :name. Test the connection again to confirm.', ['name' => $server->name])]);

        return to_route('admin.services.servers.edit', $server);
    }

    /**
     * "Remove" on a trusted certificate: calls go back to checking the certificate authority and hostname. Audited.
     */
    public function forgetCertificate(Server $server, ServerSettings $settings): RedirectResponse
    {
        $this->authorize('services.servers.manage');

        if ($settings->declares($server->driver, ServerSettings::CERTIFICATE_PIN)) {
            $settings->put($server, ServerSettings::CERTIFICATE_PIN, null, __('Removed the trusted certificate'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Trusted certificate removed for :name; its certificate is checked against its authority again.', ['name' => $server->name])]);

        return to_route('admin.services.servers.edit', $server);
    }

    /**
     * The address a check ran against; an offered certificate is valid for this address only.
     */
    private static function address(Server $server): string
    {
        return strtolower($server->hostname).':'.($server->port ?? '').':'.($server->ip_address ?? '');
    }

    private static function offeredPinKey(Server $server): string
    {
        return "services.servers.{$server->id}.offered_pin";
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(ProvisioningDriverRegistry $drivers): array
    {
        return [
            'drivers' => $this->driverOptions($drivers),
            'driverConnectionFields' => array_combine($drivers->keys(), array_map(
                fn (string $key): ?array => $drivers->metadata($key)->connectionFields,
                $drivers->keys(),
            )),
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
