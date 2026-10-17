<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Services\Actions\OrderServiceChange;
use App\Modules\Services\Actions\QuoteServiceChange;
use App\Modules\Services\Actions\RequestCancellation;
use App\Modules\Services\Actions\SetServiceStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Http\Controllers\Concerns\ConvertsServiceErrors;
use App\Modules\Services\Http\Requests\CancellationRequestForm;
use App\Modules\Services\Http\Requests\RunCommandRequest;
use App\Modules\Services\Http\Requests\ServiceChangeRequest;
use App\Modules\Services\Http\Requests\SetStatusRequest;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Support\CommandOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Staff actions on one service: module commands, status override, upgrades, cancellation.
 */
class ServiceActionController extends Controller
{
    use ConvertsServiceErrors;

    public function command(RunCommandRequest $request, Service $service, ModuleCommandDispatcher $commands): RedirectResponse
    {
        $command = $request->command();
        $outcome = $this->attempt(fn (): CommandOutcome => $commands->runNow($service, $command, $request->commandArgs(), 'staff:'.$request->user()?->id));

        if (in_array($outcome->status, [CommandOutcome::FAILED, CommandOutcome::SKIPPED], true)) {
            throw ValidationException::withMessages(['action' => __(':command failed: :error', ['command' => $command->label(), 'error' => $outcome->message])]);
        }

        Inertia::flash('toast', match ($outcome->status) {
            CommandOutcome::SUCCEEDED => ['type' => 'success', 'message' => __(':command completed for :label.', ['command' => $command->label(), 'label' => $service->label()])],
            default => ['type' => 'info', 'message' => __(':command was queued and will be retried: :error', ['command' => $command->label(), 'error' => $outcome->message])],
        });

        return back();
    }

    public function status(SetStatusRequest $request, Service $service, SetServiceStatus $setStatus): RedirectResponse
    {
        $status = ServiceStatus::from($request->string('status')->toString());
        $this->attempt(fn () => $setStatus->handle($service, $status, $request->input('note')));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Status set to :status. The remote account was not changed.', ['status' => $status->value])]);

        return back();
    }

    public function change(ServiceChangeRequest $request, Service $service, QuoteServiceChange $quotes, OrderServiceChange $order): RedirectResponse
    {
        $this->authorize('services.services.manage');
        $today = CarbonImmutable::today();

        $change = $this->attempt(fn () => $order->handle($service, $quotes->handle($service, $request->integer('product_id'), $request->cycle(), $today), $today));

        Inertia::flash('toast', ['type' => 'success', 'message' => $change->invoice_id !== null
            ? __('Upgrade ordered. It is applied when invoice #:invoice is paid.', ['invoice' => $change->invoice_id])
            : __('Change applied.')]);

        return back();
    }

    public function cancel(CancellationRequestForm $request, Service $service, RequestCancellation $cancellation): RedirectResponse
    {
        $this->authorize('services.cancellations.manage');

        $this->attempt(fn () => $cancellation->handle($service, $request->type(), $request->string('reason')->toString(), $request->user(), true, CarbonImmutable::today()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cancellation request recorded.')]);

        return back();
    }
}
