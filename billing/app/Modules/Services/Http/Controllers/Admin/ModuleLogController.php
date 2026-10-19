<?php

namespace App\Modules\Services\Http\Controllers\Admin;

use App\Enums\ReconcileStatus;
use App\Http\Controllers\Controller;
use App\Modules\Services\Actions\ExecuteModuleCommand;
use App\Modules\Services\Actions\VerifyUnknownOutcomes;
use App\Modules\Services\Enums\ModuleCommandName;
use App\Modules\Services\Enums\QueueStatus;
use App\Modules\Services\Http\Controllers\Concerns\ConvertsServiceErrors;
use App\Modules\Services\Http\Resources\ModuleCommandResource;
use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Models\ModuleQueueItem;
use App\Modules\Services\Services\ModuleCommandDispatcher;
use App\Modules\Services\Support\CommandOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module command log and the queue of failed commands awaiting staff.
 */
class ModuleLogController extends Controller
{
    use ConvertsServiceErrors;

    public function index(Request $request, VerifyUnknownOutcomes $verify): Response
    {
        $this->authorize('services.module_log.view');

        $tab = $request->string('tab', 'log')->toString() === 'queue' ? 'queue' : 'log';

        return Inertia::render('admin/services/module-log/index', [
            'tab' => $tab,
            'filters' => $request->only(['search', 'result', 'command', 'tab']),
            'commands' => array_map(fn (ModuleCommandName $command): array => ['value' => $command->value, 'label' => $command->label()], ModuleCommandName::cases()),
            'openCount' => ModuleQueueItem::query()->where('status', QueueStatus::Open->value)->count(),
            'log' => $tab === 'log' ? ModuleCommandResource::collection(ModuleCommand::query()
                ->with(['service', 'server'])
                ->when($request->filled('result'), fn (Builder $query) => $query->where('success', $request->string('result')->toString() === 'success'))
                ->when($request->filled('command'), fn (Builder $query) => $query->where('command', $request->string('command')->toString()))
                ->when($request->filled('search'), fn (Builder $query) => $query->whereHas('service', fn (Builder $services) => $services->search($request->string('search')->toString())))
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()) : null,
            'queue' => $tab === 'queue' ? ModuleQueueItem::query()
                ->with('service.server')
                // When the command was last tried (its latest module log row), not when the entry was last touched.
                ->addSelect(['attempted_at' => ModuleCommand::query()
                    ->select('created_at')
                    ->whereColumn('service_id', 'module_queue.subject_id')
                    ->whereColumn('command', 'module_queue.command')
                    ->orderByDesc('id')
                    ->limit(1),
                ])
                ->where('status', QueueStatus::Open->value)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (ModuleQueueItem $item): array => [
                    'id' => $item->id,
                    'service_id' => $item->subject_id,
                    'service_label' => $item->service?->label(),
                    'command' => $item->command,
                    'kind' => $item->kind,
                    'last_error' => $item->last_error,
                    'attempts' => $item->attempts,
                    'status' => $item->status->value,
                    'updated_at' => $item->updated_at?->toIso8601String(),
                    'attempted_at' => $item->getAttribute('attempted_at') !== null ? CarbonImmutable::parse((string) $item->getAttribute('attempted_at'))->toIso8601String() : $item->updated_at?->toIso8601String(),
                    'can_check' => $verify->canCheck($item),
                    'lookup_status' => $item->lookup_status?->value,
                    'lookup_message' => $item->lookup_message,
                    'looked_up_at' => $item->looked_up_at?->toIso8601String(),
                    'retry_blocked' => $verify->runAgainBlockedReason($item),
                ]) : null,
            'canManage' => $request->user()?->hasPermission('services.module_log.manage') ?? false,
        ]);
    }

    public function retry(Request $request, ModuleQueueItem $item, ModuleCommandDispatcher $commands, VerifyUnknownOutcomes $verify): RedirectResponse
    {
        $this->authorize('services.module_log.manage');

        if ($item->status !== QueueStatus::Open || $item->service === null) {
            throw ValidationException::withMessages(['action' => __('This entry is no longer open.')]);
        }

        $blocked = $verify->runAgainBlockedReason($item);

        if ($blocked !== null) {
            throw ValidationException::withMessages(['action' => $blocked]);
        }

        $command = ModuleCommandName::from($item->command);
        // Retrying a "verify" item is the staff statement that the server was checked.
        $args = [...($item->args ?? []), ...($item->kind === ModuleQueueItem::KIND_VERIFY ? ['verified' => true] : [])];
        $outcome = $this->attempt(fn (): CommandOutcome => $commands->runNow($item->service, $command, $args, 'staff:'.$request->user()?->id));

        if (! $outcome->succeededNow()) {
            throw ValidationException::withMessages(['action' => __('Retry failed: :error', ['error' => $outcome->message])]);
        }

        $item->refresh()->update(['resolved_by' => $request->user()?->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':command succeeded for :label.', ['command' => $command->label(), 'label' => $item->service->label()])]);

        return back();
    }

    /**
     * "Check now": ask the server whether the command with the unknown outcome ran.
     */
    public function check(Request $request, ModuleQueueItem $item, VerifyUnknownOutcomes $verify): RedirectResponse
    {
        $this->authorize('services.module_log.manage');

        $status = $verify->check($item, 'staff:'.$request->user()?->id);

        if ($status === null) {
            throw ValidationException::withMessages(['action' => $item->refresh()->status === QueueStatus::Open
                ? __('This entry cannot be checked on the server.')
                : __('This entry was already settled.')]);
        }

        // The entry shows what the check found; the toast says it in one line.
        Inertia::flash('toast', ['type' => $status === ReconcileStatus::Executed ? 'success' : 'info', 'message' => $status === ReconcileStatus::Executed
            ? __('Confirmed on the server; the action is complete.')
            : ($item->refresh()->lookup_message ?? __('Checked; see the entry.'))]);

        return back();
    }

    public function resolve(Request $request, ModuleQueueItem $item): RedirectResponse
    {
        return $this->close($request, $item, QueueStatus::Resolved, __('Marked fixed.'));
    }

    public function dismiss(Request $request, ModuleQueueItem $item): RedirectResponse
    {
        return $this->close($request, $item, QueueStatus::Dismissed, __('Ignored; the entry is closed.'));
    }

    private function close(Request $request, ModuleQueueItem $item, QueueStatus $status, string $message): RedirectResponse
    {
        $this->authorize('services.module_log.manage');

        $item->update(['status' => $status, 'resolved_by' => $request->user()?->id]);

        if ($item->kind === ModuleQueueItem::KIND_VERIFY) {
            app(ExecuteModuleCommand::class)->acknowledgeUnknown($item->subject_id, ModuleCommandName::from($item->command));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
