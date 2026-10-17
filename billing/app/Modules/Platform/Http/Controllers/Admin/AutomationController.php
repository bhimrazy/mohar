<?php

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\AutomationRun;
use App\Modules\Core\Support\ActivityLogger;
use App\Support\Automation\Automation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
    public function index(Request $request, Automation $automation): Response
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

        $lastRuns = AutomationRun::query()->orderByDesc('run_date')->orderByDesc('id')->get()->unique('task')->keyBy('task');

        return Inertia::render('admin/platform/automation/index', [
            'runs' => $runs->through(fn (AutomationRun $run): array => [
                'id' => $run->id,
                'run_date' => $run->run_date->toDateString(),
                'task' => $run->task,
                'status' => $run->status,
                'summary' => $run->summary,
                'duration_ms' => $run->duration_ms,
            ]),
            'tasks' => array_map(fn (string $key, array $task): array => [
                'key' => $key,
                'stage' => $task['stage'],
                'last_status' => $lastRuns->get($key)?->status,
                'last_run_date' => $lastRuns->get($key)?->run_date->toDateString(),
            ], array_keys($automation->ordered()), array_values($automation->ordered())),
            'filters' => $filters,
            'scheduledAt' => (string) config('core.automation_time'),
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

        $exitCode = Artisan::call('automation:run', array_filter([
            '--only' => $data['task'] ?? null,
            '--force' => (bool) ($data['force'] ?? false),
        ]));

        $activity->log('Automation run started manually'.(isset($data['task']) ? " ({$data['task']})" : ''), properties: [
            'task' => $data['task'] ?? null,
            'force' => (bool) ($data['force'] ?? false),
            'exit_code' => $exitCode,
        ], event: 'automation.run');

        Inertia::flash('toast', $exitCode === 0
            ? ['type' => 'success', 'message' => __('Automation run finished.')]
            : ['type' => 'error', 'message' => __('Automation run finished with failures. Check the run log.')]);

        return to_route('admin.platform.automation.index');
    }
}
