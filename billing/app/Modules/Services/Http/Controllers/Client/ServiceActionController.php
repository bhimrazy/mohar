<?php

namespace App\Modules\Services\Http\Controllers\Client;

use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ClientContext;
use App\Modules\Services\Actions\OrderServiceChange;
use App\Modules\Services\Actions\RequestCancellation;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Http\Controllers\Concerns\ConvertsServiceErrors;
use App\Modules\Services\Http\Requests\CancellationRequestForm;
use App\Modules\Services\Http\Requests\ChangePasswordRequest;
use App\Modules\Services\Http\Requests\ServiceChangeRequest;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Services\ServicePresenter;
use App\Modules\Services\Support\CommandOutcome;
use App\Modules\Services\Support\ServiceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Self-service actions on the client's own services. Every lookup is scoped to the current account.
 */
class ServiceActionController extends Controller
{
    use ConvertsServiceErrors;

    public function __construct(private ClientContext $context) {}

    public function cancel(CancellationRequestForm $request, int $service, RequestCancellation $cancellation, ServiceSettings $settings): RedirectResponse
    {
        $model = $this->owned($service);

        if (! $settings->clientCanRequestCancellation()) {
            throw ValidationException::withMessages(['reason' => __('Please contact support to cancel this service.')]);
        }

        $this->attempt(fn () => $cancellation->handle($model, $request->type(), $request->string('reason')->toString(), $request->user(), false, CarbonImmutable::today()), 'reason');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('We received your cancellation request for :label.', ['label' => $model->label()])]);

        return to_route('client.services.show', $model->id);
    }

    public function change(ServiceChangeRequest $request, int $service, OrderServiceChange $order): RedirectResponse
    {
        $model = $this->owned($service);
        $today = CarbonImmutable::today();

        $change = $this->attempt(fn () => $order->handle($model, $request->integer('product_id'), $request->cycle(), $today), 'product_id');

        Inertia::flash('toast', ['type' => 'success', 'message' => $change->invoice_id !== null
            ? __('Your upgrade is ready. Pay invoice #:invoice to apply it.', ['invoice' => $change->invoice_id])
            : __('Your plan was changed.')]);

        return to_route('client.services.show', $model->id);
    }

    public function password(ChangePasswordRequest $request, int $service, ModuleCommandDispatcher $commands, ServicePresenter $presenter): RedirectResponse
    {
        $model = $this->owned($service);

        if (! in_array($model->status, [ServiceStatus::Active, ServiceStatus::Suspended], true) || ! $presenter->driver($model) instanceof ChangesPassword) {
            throw ValidationException::withMessages(['password' => __('The password of this service cannot be changed here.')]);
        }

        $outcome = $this->attempt(fn (): CommandOutcome => $commands->runNow($model, ModuleCommandName::ChangePassword, ['password' => $request->string('password')->toString()], 'client:'.$request->user()?->id), 'password');

        if (in_array($outcome->status, [CommandOutcome::FAILED, CommandOutcome::SKIPPED], true)) {
            throw ValidationException::withMessages(['password' => __('We could not change the password right now. Please try again later or contact support.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $outcome->succeededNow() ? __('Password changed.') : __('Your password change is being processed.')]);

        return to_route('client.services.show', $model->id);
    }

    private function owned(int $serviceId): Service
    {
        $this->context->authorize('services');

        return Service::query()->where('client_id', $this->context->id())->findOrFail($serviceId);
    }
}
