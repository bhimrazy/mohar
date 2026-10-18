<?php

namespace App\Modules\Services\Http\Controllers\Client;

use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Services\Enums\CancellationType;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Http\Resources\ServiceResource;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ServicePresenter;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function __construct(private ClientContext $context) {}

    public function index(Request $request): Response
    {
        $this->context->authorize('services');

        $services = Service::query()
            ->with('server')
            ->where('client_id', $this->context->id())
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->orderByRaw('case when status in (?, ?, ?) then 0 else 1 end', [ServiceStatus::Active->value, ServiceStatus::Suspended->value, ServiceStatus::Pending->value])
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('client/services/index', [
            'services' => ServiceResource::collection($services),
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(int $service, ServicePresenter $presenter, ServiceSettings $settings): Response
    {
        $this->context->authorize('services');

        $model = Service::query()
            ->with(['server', 'openCancellationRequest', 'latestUsage'])
            ->where('client_id', $this->context->id())
            ->findOrFail($service);
        $driver = $presenter->driver($model);
        $live = in_array($model->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);

        return Inertia::render('client/services/show', [
            'service' => new ServiceResource($model),
            'addons' => $model->addons()->whereIn('status', ServiceStatus::occupyingValues())->orderBy('id')->get()->map(fn ($addon): array => [
                'id' => $addon->id,
                'name' => $addon->name,
                'status' => $addon->status->value,
                'cycle' => $addon->cycle->value,
                'recurring_amount' => $addon->recurring_amount,
                'currency' => $addon->currency,
                'next_due_date' => $addon->next_due_date?->toDateString(),
            ]),
            'usage' => $model->latestUsage?->only(['disk_used_mb', 'disk_limit_mb', 'bw_used_mb', 'bw_limit_mb', 'captured_at']),
            'cancellation' => $model->openCancellationRequest?->only(['id', 'type', 'process_after']),
            'cancellationTypes' => array_map(fn (CancellationType $type): array => ['value' => $type->value, 'label' => $type->label()], CancellationType::cases()),
            'changeOptions' => Inertia::defer(fn (): array => $presenter->changeOptions($model, CarbonImmutable::today())),
            'creditOnDowngrade' => $settings->creditOnDowngrade(),
            'can' => [
                'cancel' => $live && $settings->clientCanRequestCancellation() && $model->openCancellationRequest === null,
                'changePassword' => $live && $driver instanceof ChangesPassword && $model->username !== null,
            ],
        ]);
    }
}
