<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Console\Commands\RunAutomation;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\ActivityLogger;
use App\Modules\Core\Support\Settings;
use App\Modules\Platform\Jobs\RunAutomationJob;
use App\Modules\Platform\Support\AutomationInsights;
use App\Support\Automation\Automation;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AutomationController extends Controller
{
    public function index(Request $request, Automation $automation, Settings $settings, AutomationInsights $insights): Response
    {
        $this->authorize('core.automation.view');

        $filters = $request->validate([
            'task' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['success', 'failed'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $runs = AutomationRun::query()
            ->when($filters['task'] ?? null, fn ($query, string $task) => $query->where('task', $task))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['date'] ?? null, fn ($query, string $date) => $query->whereDate('run_date', $date))
            ->orderByDesc('run_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $lastRuns = AutomationRun::query()
            ->whereIn('id', AutomationRun::query()->selectRaw('max(id)')->groupBy('task'))
            ->get()
            ->keyBy('task');

        return Inertia::render('admin/platform/automation/index', [
            'runs' => $runs->through(fn (AutomationRun $run): array => [
                'id' => $run->id,
                'run_date' => $run->run_date->toDateString(),
                'task' => $run->task,
                'task_label' => $automation->label($run->task),
                'status' => $run->status,
                'summary' => $run->summary,
                'duration_ms' => $run->duration_ms,
            ]),
            'tasks' => array_map(fn (string $key, array $task): array => [
                'key' => $key,
                'label' => $task['label'],
                'description' => $task['description'],
                'stage' => $task['stage'],
                'last_status' => $lastRuns->get($key)?->status,
                'last_run_date' => $lastRuns->get($key)?->run_date->toDateString(),
            ], array_keys($automation->ordered()), array_values($automation->ordered())),
            'filters' => $filters,
            'insights' => fn (): array => $insights->summary(),
            'scheduledAt' => (string) $settings->get('core.automation_time', config('core.automation_time')),
            'can' => ['run' => $request->user()?->hasPermission('core.automation.run') ?? false],
        ]);
    }

    public function run(Request $request, Automation $automation, ActivityLogger $activity): RedirectResponse
    {
        $this->authorize('core.automation.run');

        $data = $request->validate([
            'task' => ['nullable', 'string', Rule::in(array_keys($automation->ordered()))],
            'force' => ['boolean'],
        ]);

        $force = (bool) ($data['force'] ?? false);
        $job = new RunAutomationJob($data['task'] ?? null, $force);

        if (RunAutomation::inProgress()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('An automation run is in progress. Try again when it finishes; results appear in the run log.')]);

            return to_route('admin.platform.automation.index');
        }

        // The job's unique lock is taken here rather than by dispatch(), so the page can say that a run is already
        // waiting or running instead of reporting a run it skipped. The worker releases it when the job finishes.
        $lock = new UniqueLock(Cache::store());

        if (! $lock->acquire($job)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('An automation run is already queued or running. Results appear in the run log.')]);

            return to_route('admin.platform.automation.index');
        }

        try {
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            $lock->release($job);

            throw $exception;
        }

        $activity->log('Automation run requested', properties: [
            'task' => $data['task'] ?? null,
            'force' => $force,
        ], event: 'automation.run');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Automation run queued. Results appear in the run log.')]);

        return to_route('admin.platform.automation.index');
    }
}
