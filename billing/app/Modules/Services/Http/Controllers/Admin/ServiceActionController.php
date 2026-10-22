<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Contracts\Core\CompanyClock;
use App\Http\Controllers\Controller;
use App\Modules\Services\Actions\AddServiceAddon;
use App\Modules\Services\Actions\OrderServiceChange;
use App\Modules\Services\Actions\RequestCancellation;
use App\Modules\Services\Actions\SetServiceStatus;
use App\Modules\Services\Enums\ServiceStatus;
use App\Modules\Services\Http\Controllers\Concerns\ConvertsServiceErrors;
use App\Modules\Services\Http\Requests\AddAddonRequest;
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

    public function change(ServiceChangeRequest $request, Service $service, OrderServiceChange $order, CompanyClock $clock): RedirectResponse
    {
        $this->authorize('services.services.manage');
        $today = $clock->today()->shiftTimezone('UTC'); // a date value, as OrderServiceChange expects

        $change = $this->attempt(fn () => $order->handle($service, $request->integer('product_id'), $request->cycle(), $today));

        Inertia::flash('toast', ['type' => 'success', 'message' => $change->invoice_id !== null
            ? __('Upgrade ordered. It is applied when invoice #:invoice is paid.', ['invoice' => $change->invoice_id])
            : __('Change applied.')]);

        return back();
    }

    public function addAddon(AddAddonRequest $request, Service $service, AddServiceAddon $add): RedirectResponse
    {
        /** @var array{name: string, addon_id?: ?int, cycle: string, recurring_amount: int, setup_fee: int, next_due_date?: ?string, status: string, suspend_parent_on_overdue?: bool} $data */
        $data = $request->validated();
        $addon = $this->attempt(fn () => $add->handle($service, $data, CarbonImmutable::today()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Addon :name added.', ['name' => $addon->name])]);

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
