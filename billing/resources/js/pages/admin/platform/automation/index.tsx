import { Head } from '@inertiajs/react';
import { Play, Timer } from 'lucide-react';
import { useState } from 'react';
import AutomationController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/AutomationController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { LaravelPaginated } from '@/types';

type Run = {
    id: number;
    run_date: string;
    task: string;
    status: string;
    summary: string | null;
    duration_ms: number;
};
type Task = {
    key: string;
    stage: number;
    last_status: string | null;
    last_run_date: string | null;
};

type Props = {
    runs: LaravelPaginated<Run>;
    tasks: Task[];
    filters: Record<string, string | undefined>;
    scheduledAt: string;
    can: { run: boolean };
};

const STAGES: Record<number, string> = {
    10: 'Renewals',
    20: 'Late fees',
    30: 'Auto-charge',
    40: 'Reminders',
    50: 'Cancellations',
    60: 'Suspensions',
    70: 'Terminations',
    80: 'Sync',
    90: 'Housekeeping',
};

const ONLY = ['runs', 'filters'];

const runColumns: DataTableColumn<Run>[] = [
    {
        key: 'date',
        header: 'Run date',
        className: 'w-36',
        cell: (run) => <DateTime value={run.run_date} />,
    },
    {
        key: 'task',
        header: 'Task',
        primary: true,
        cell: (run) => <span className="font-mono text-xs">{run.task}</span>,
    },
    {
        key: 'status',
        header: 'Status',
        cell: (run) => <StatusBadge status={run.status} />,
    },
    {
        key: 'summary',
        header: 'Summary',
        cell: (run) => (
            <span className="text-muted-foreground">{run.summary ?? '—'}</span>
        ),
    },
    {
        key: 'duration',
        header: 'Duration',
        align: 'right',
        hideOnMobile: true,
        cell: (run) => (
            <span className="text-muted-foreground tabular-nums">
                {run.duration_ms.toLocaleString()} ms
            </span>
        ),
    },
];

export default function AutomationIndex({
    runs,
    tasks,
    filters,
    scheduledAt,
    can,
}: Props) {
    // null = closed; '' = whole pipeline; otherwise one task key.
    const [running, setRunning] = useState<string | null>(null);
    const [force, setForce] = useState(false);
    const runTask = (task: string) => {
        setForce(false);
        setRunning(task);
    };

    const taskColumns: DataTableColumn<Task>[] = [
        {
            key: 'task',
            header: 'Task',
            primary: true,
            cell: (task) => (
                <span className="font-mono text-xs">{task.key}</span>
            ),
        },
        {
            key: 'stage',
            header: 'Stage',
            cell: (task) => STAGES[task.stage] ?? task.stage,
        },
        {
            key: 'last',
            header: 'Last run',
            cell: (task) =>
                task.last_status ? (
                    <span className="flex items-center gap-2">
                        <StatusBadge status={task.last_status} />
                        <DateTime
                            value={task.last_run_date}
                            className="text-muted-foreground"
                        />
                    </span>
                ) : (
                    <span className="text-muted-foreground">Never</span>
                ),
        },
        ...(can.run
            ? [
                  {
                      key: 'actions',
                      header: <span className="sr-only">Actions</span>,
                      align: 'right' as const,
                      hideOnMobile: true,
                      cell: (task: Task) => (
                          <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => runTask(task.key)}
                          >
                              <Play />
                              Run now
                          </Button>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Automation" />
            <Page>
                <PageHeader
                    title="Automation"
                    description={`The daily pipeline runs at ${scheduledAt} server time, in stage order. Each task runs once per day.`}
                    actions={
                        can.run && (
                            <Button onClick={() => runTask('')}>
                                <Play />
                                Run today's pipeline
                            </Button>
                        )
                    }
                />

                <SectionCard
                    title="Tasks"
                    description="Registered by the enabled modules."
                    flush
                >
                    <DataTable
                        className="rounded-none border-0 shadow-none"
                        caption="Automation tasks"
                        columns={taskColumns}
                        rows={tasks}
                        getRowKey={(task) => task.key}
                        empty={
                            <EmptyState
                                icon={Timer}
                                title="No tasks registered"
                                description="Modules such as Billing and Services add their daily tasks here."
                            />
                        }
                    />
                </SectionCard>

                <div className="flex flex-col gap-3">
                    <h2 className="text-base font-semibold">Run log</h2>
                    <FilterBar
                        searchParam={null}
                        filterKeys={['task', 'status']}
                        only={ONLY}
                        filters={
                            <>
                                <FilterSelect
                                    name="task"
                                    label="Tasks"
                                    allLabel="All tasks"
                                    only={ONLY}
                                    options={tasks.map((task) => ({
                                        value: task.key,
                                        label: task.key,
                                    }))}
                                />
                                <FilterSelect
                                    name="status"
                                    label="Status"
                                    allLabel="All statuses"
                                    only={ONLY}
                                    options={[
                                        { value: 'success', label: 'Success' },
                                        { value: 'failed', label: 'Failed' },
                                    ]}
                                />
                            </>
                        }
                    />
                    <DataTable
                        caption="Automation runs"
                        columns={runColumns}
                        rows={runs.data}
                        getRowKey={(run) => run.id}
                        empty={
                            <EmptyState
                                icon={Timer}
                                title={
                                    filters.task || filters.status
                                        ? 'No runs match these filters'
                                        : 'No runs yet'
                                }
                                description="Runs are recorded each day after the scheduler starts the pipeline."
                            />
                        }
                        footer={
                            <Pagination
                                paginator={runs}
                                itemLabel="runs"
                                only={ONLY}
                            />
                        }
                    />
                </div>
            </Page>

            <ConfirmDialog
                open={running !== null}
                onOpenChange={(open) => !open && setRunning(null)}
                title={
                    running
                        ? `Run ${running} now?`
                        : "Run today's automation pipeline?"
                }
                description={
                    <span className="flex flex-col gap-3">
                        <span>
                            The run is queued and uses the same lock as the
                            scheduled run. Tasks that already succeeded today
                            are skipped unless you re-run them.
                        </span>
                        <span className="flex items-start gap-2 text-foreground">
                            <Checkbox
                                id="automation-force"
                                checked={force}
                                onCheckedChange={(checked) =>
                                    setForce(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <Label
                                htmlFor="automation-force"
                                className="font-normal"
                            >
                                Re-run even if it already succeeded today (may
                                repeat charges, emails or renewals)
                            </Label>
                        </span>
                    </span>
                }
                confirmLabel={running ? 'Run task' : 'Run pipeline'}
                destructive={force}
                method="post"
                action={AutomationController.run()}
                data={{ ...(running ? { task: running } : {}), force }}
            />
        </>
    );
}

AutomationIndex.layout = {
    breadcrumbs: [{ title: 'Automation', href: AutomationController.index() }],
};
