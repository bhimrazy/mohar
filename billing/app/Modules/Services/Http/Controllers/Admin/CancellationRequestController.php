<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Services\Actions\ProcessCancellations;
use App\Modules\Services\Actions\VoidCancellation;
use App\Modules\Services\Enums\CancellationStatus;
use App\Modules\Services\Http\Controllers\Concerns\ConvertsServiceErrors;
use App\Modules\Services\Models\CancellationRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CancellationRequestController extends Controller
{
    use ConvertsServiceErrors;

    public function index(Request $request): Response
    {
        $this->authorize('services.cancellations.manage');

        $status = $request->string('status', CancellationStatus::Open->value)->toString();
        $requests = CancellationRequest::query()
            ->with(['service.client', 'requestedBy:id,name'])
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->orderBy('process_after')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/services/cancellations/index', [
            'requests' => $requests->through(fn (CancellationRequest $item): array => [
                'id' => $item->id,
                'service_id' => $item->service_id,
                'service_label' => $item->service->label(),
                'service_status' => $item->service->status->value,
                'client' => ['id' => $item->service->client->id, 'name' => $item->service->client->display_name],
                'type' => $item->type->value,
                'type_label' => $item->type->label(),
                'reason' => $item->reason,
                'status' => $item->status->value,
                'by_staff' => $item->by_staff,
                'requested_by' => $item->requestedBy?->name,
                'process_after' => $item->process_after->toDateString(),
                'created_at' => $item->created_at?->toIso8601String(),
            ]),
            'filters' => ['status' => $status],
            'statuses' => array_map(fn (CancellationStatus $value): array => ['value' => $value->value, 'label' => ucfirst($value->value)], CancellationStatus::cases()),
        ]);
    }

    public function approve(CancellationRequest $cancellationRequest, ProcessCancellations $process): RedirectResponse
    {
        $this->authorize('services.cancellations.manage');

        if ($cancellationRequest->status !== CancellationStatus::Open) {
            throw ValidationException::withMessages(['action' => __('Only open requests can be approved.')]);
        }

        $this->attempt(fn () => $process->process($cancellationRequest->load('service')));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cancellation of :label is being processed.', ['label' => $cancellationRequest->service->label()])]);

        return back();
    }

    public function void(CancellationRequest $cancellationRequest, VoidCancellation $void): RedirectResponse
    {
        $this->authorize('services.cancellations.manage');

        $this->attempt(fn () => $void->handle($cancellationRequest));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cancellation request voided; renewals resume.')]);

        return back();
    }
}
