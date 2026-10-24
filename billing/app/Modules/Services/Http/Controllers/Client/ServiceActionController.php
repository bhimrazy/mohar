<?php

namespace App\Modules\Services\Http\Controllers\Client;

use App\Contracts\Core\CompanyClock;
use App\Contracts\Services\Provisioning\ChangesPassword;
use App\Http\Controllers\Controller;
use App\Modules\Core\Support\ActivityLogger;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Self-service actions on the client's own services. Every lookup is scoped to the current account.
 */
class ServiceActionController extends Controller
{
    use ConvertsServiceErrors;

    public function __construct(private ClientContext $context) {}

    public function cancel(CancellationRequestForm $request, int $service, RequestCancellation $cancellation, ServiceSettings $settings, CompanyClock $clock): RedirectResponse
    {
        $model = $this->owned($service);

        if (! $settings->clientCanRequestCancellation()) {
            throw ValidationException::withMessages(['reason' => __('Please contact support to cancel this service.')]);
        }

        $this->attempt(fn () => $cancellation->handle($model, $request->type(), $request->string('reason')->toString(), $request->user(), false, $clock->todayDate()), 'reason');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('We received your cancellation request for :label.', ['label' => $model->label()])]);

        return to_route('client.services.show', $model->id);
    }

    public function change(ServiceChangeRequest $request, int $service, OrderServiceChange $order, CompanyClock $clock): RedirectResponse
    {
        $model = $this->owned($service);
        $today = $clock->todayDate();

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

    /** Password reveals allowed per login in REVEAL_WINDOW_SECONDS, across all services (#44). */
    public const REVEALS_PER_WINDOW = 5;

    public const REVEAL_WINDOW_SECONDS = 600;

    /**
     * Show the stored hosting password (#44): welcome emails no longer carry it. Behind a recent password confirmation
     * (route) and a per-login limit, written to the activity log without the value, and sent as JSON that is never
     * cached, so it is in no page prop or history entry.
     *
     * The limit is counted here rather than by route throttling, so only requests past step-up use it up: the 423
     * that opens the confirm dialog does not.
     */
    public function revealPassword(Request $request, int $service, ActivityLogger $activity, ServicePresenter $presenter): JsonResponse
    {
        $key = 'services-password-reveal:'.$request->user()?->id;

        if (RateLimiter::tooManyAttempts($key, self::REVEALS_PER_WINDOW)) {
            return response()->json(['message' => __('The password was shown too many times. Try again later.')], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key))
                ->header('Cache-Control', 'no-store, private');
        }

        RateLimiter::hit($key, self::REVEAL_WINDOW_SECONDS);
        $model = $this->owned($service);

        if (! $presenter->revealsPassword($model)) {
            return response()->json(['message' => __('No password is stored for this service.')], 422)->header('Cache-Control', 'no-store, private');
        }

        $activity->log('Service password revealed in the client area', $model, $model->client_id);

        return response()->json(['password' => $model->password])
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    private function owned(int $serviceId): Service
    {
        $this->context->authorize('services');

        return Service::query()->where('client_id', $this->context->id())->findOrFail($serviceId);
    }
}
