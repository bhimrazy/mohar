<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Services\Actions\UpdateService;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Http\Requests\ServiceRequest;
use App\Modules\Services\Http\Resources\ModuleCommandResource;
use App\Modules\Services\Http\Resources\ServiceResource;
use App\Modules\Services\Models\Server;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ServicePresenter;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('services.services.view');

        $today = CarbonImmutable::today();
        $services = Service::query()
            ->with(['client', 'server'])
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('product'), fn (Builder $query) => $query->where('product_id', $request->integer('product')))
            ->when($request->filled('server'), fn (Builder $query) => $query->where('server_id', $request->integer('server')))
            ->when($request->filled('client'), fn (Builder $query) => $query->where('client_id', $request->integer('client')))
            ->when($request->boolean('overdue'), fn (Builder $query) => $query
                ->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
                ->whereDate('next_due_date', '<', $today))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/services/index', [
            'services' => ServiceResource::collection($services),
            'filters' => $request->only(['search', 'status', 'product', 'server', 'client', 'overdue']),
            'statuses' => array_map(fn (ServiceStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)], ServiceStatus::cases()),
            'products' => Service::query()->select(['product_id', 'product_name'])->distinct()->orderBy('product_name')->orderBy('product_id')->get()
                ->map(fn (Service $service): array => ['value' => (string) $service->product_id, 'label' => $service->product_name ?? "Product #{$service->product_id}"])->unique('value')->values(),
            'servers' => Server::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->map(fn (Server $server): array => ['value' => (string) $server->id, 'label' => $server->name]),
            'client' => $request->filled('client') ? Client::query()->find($request->integer('client'))?->only(['id', 'display_name']) : null,
        ]);
    }

    public function show(Request $request, Service $service, ServicePresenter $presenter, ServiceSettings $settings): Response
    {
        $this->authorize('services.services.view');

        $service->load(['client', 'server', 'openCancellationRequest', 'latestUsage']);
        $user = $request->user();
        $today = CarbonImmutable::today();

        return Inertia::render('admin/services/show', [
            'service' => new ServiceResource($service),
            'addons' => $service->addons()->orderBy('id')->get()->map(fn ($addon): array => [
                'id' => $addon->id,
                'name' => $addon->name,
                'status' => $addon->status->value,
                'cycle' => $addon->cycle->value,
                'recurring_amount' => $addon->recurring_amount,
                'currency' => $addon->currency,
                'next_due_date' => $addon->next_due_date?->toDateString(),
                'suspend_parent_on_overdue' => $addon->suspend_parent_on_overdue,
            ]),
            'invoices' => $service->invoiceLinks()->orderByDesc('period_start')->orderByDesc('id')->limit(12)->get()->map(fn ($link): array => [
                'id' => $link->id,
                'invoice_id' => $link->invoice_id,
                'kind' => $link->kind->value,
                'period_start' => $link->period_start?->toDateString(),
                'period_end' => $link->period_end?->toDateString(),
                'amount' => $link->amount,
                'status' => $link->status->value,
            ]),
            'changes' => $service->changes()->orderByDesc('id')->limit(10)->get()->map(fn ($change): array => [
                'id' => $change->id,
                'kind' => $change->kind->value,
                'from' => $change->from_snapshot['product_name'] ?? null,
                'to' => $change->to_snapshot['product_name'] ?? null,
                'to_cycle' => $change->to_snapshot['cycle'] ?? null,
                'amount_due' => $change->amount_due,
                'currency' => $change->currency,
                'status' => $change->status->value,
                'invoice_id' => $change->invoice_id,
                'created_at' => $change->created_at?->toIso8601String(),
            ]),
            'cancellation' => $service->openCancellationRequest?->only(['id', 'type', 'reason', 'process_after']),
            'moduleLog' => $user?->hasPermission('services.module_log.view')
                ? ModuleCommandResource::collection($service->moduleCommands()->orderByDesc('id')->limit(15)->get())
                : [],
            'usage' => $service->latestUsage?->only(['disk_used_mb', 'disk_limit_mb', 'bw_used_mb', 'bw_limit_mb', 'captured_at']),
            'driver' => $presenter->driverProps($service),
            'commands' => $presenter->commands($service),
            'statusTargets' => $presenter->statusTargets($service),
            'changeOptions' => Inertia::defer(fn (): array => $presenter->changeOptions($service, $today)),
            'creditOnDowngrade' => $settings->creditOnDowngrade(),
            'can' => [
                'manage' => $user?->hasPermission('services.services.manage') ?? false,
                'provision' => $user?->hasPermission('services.services.provision') ?? false,
                'credentials' => $user?->hasPermission('services.services.credentials') ?? false,
                'moduleLog' => $user?->hasPermission('services.module_log.view') ?? false,
            ],
        ]);
    }

    /**
     * Reveal the service password. POST (never cached or kept in history state), permission-gated
     * and written to the activity log.
     */
    public function credentials(Request $request, Service $service, ActivityLogger $activity): JsonResponse
    {
        $this->authorize('services.services.credentials');

        $activity->log('Service credentials revealed', $service, $service->client_id, ['staff_id' => $request->user()?->id]);

        return response()
            ->json(['username' => $service->username, 'password' => $service->password])
            ->header('Cache-Control', 'no-store, private');
    }

    public function edit(Service $service): Response
    {
        $this->authorize('services.services.manage');

        return Inertia::render('admin/services/edit', [
            'service' => new ServiceResource($service->load(['client', 'server'])),
            'cycles' => array_map(fn (BillingCycle $cycle): array => ['value' => $cycle->value, 'label' => ucfirst($cycle->value)], BillingCycle::cases()),
            'servers' => Server::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'hostname'])->map(fn (Server $server): array => ['value' => (string) $server->id, 'label' => "{$server->name} ({$server->hostname})"]),
        ]);
    }

    public function update(ServiceRequest $request, Service $service, UpdateService $update): RedirectResponse
    {
        $update->handle($service, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service :label updated.', ['label' => $service->label()])]);

        return to_route('admin.services.services.show', $service);
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->authorize('services.services.manage');

        $service->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Service :label deleted. The remote account was not touched.', ['label' => $service->label()])]);

        return to_route('admin.services.index');
    }
}
