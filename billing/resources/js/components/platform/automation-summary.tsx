import {
    AlertTriangle,
    CalendarClock,
    Check,
    Clock,
    Copy,
    Gauge,
    Timer,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { BreakdownCard } from '@/components/app/dashboard/breakdown-card';
import { ChartCard } from '@/components/app/dashboard/chart-card';
import { Panel } from '@/components/app/dashboard/panel';
import type { ChartWidget } from '@/components/app/dashboard/types';
import { DateTime } from '@/components/app/date';
import { StatCard } from '@/components/app/stat-card';
import type { StatTrend } from '@/components/app/stat-card';
import { cn } from '@/lib/utils';

/** Matches App\Modules\Platform\Support\AutomationInsights::summary(). */
export type AutomationInsights = {
    hasRuns: boolean;
    scheduler: {
        state: 'ok' | 'late' | 'stopped' | 'never';
        heartbeatAt: string | null;
    };
    successRate: {
        value: number | null;
        succeeded: number;
        total: number;
        trend: StatTrend | null;
    };
    failed24h: number;
    nextRunAt: string;
    duration: {
        averageMs: number | null;
        trend: StatTrend | null;
        sparkline: number[];
    };
    perDay: { x: string; success: number; failed: number; missing: number }[];
    processedToday: { stage: number; count: number; tasks: number }[];
    stageDurations: { stage: number; averageMs: number }[];
    cronCommand: string;
};

export const STAGES: Record<number, string> = {
    5: 'Exchange rates',
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

const SCHEDULER_LABEL = {
    ok: 'Running',
    late: 'Late',
    stopped: 'Stopped',
    never: 'Not running',
} as const;

function formatDuration(ms: number): string {
    return ms >= 1000
        ? `${(ms / 1000).toLocaleString('en-US', { maximumFractionDigits: 1 })} s`
        : `${ms.toLocaleString('en-US')} ms`;
}

/** Next run as a figure ("03:00") and a context line ("Tomorrow · in 21 hours" comes from the caller). */
function nextRunParts(iso: string): { time: string; day: string } {
    const date = new Date(iso);
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(today.getDate() + 1);

    return {
        time: date.toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        }),
        day:
            date.toDateString() === today.toDateString()
                ? 'Today'
                : date.toDateString() === tomorrow.toDateString()
                  ? 'Tomorrow'
                  : date.toLocaleDateString('en-US', {
                        month: 'short',
                        day: 'numeric',
                    }),
    };
}

function CronCommand({ command }: { command: string }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(command);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="flex items-stretch overflow-hidden rounded-md border bg-background">
            <code className="min-w-0 flex-1 overflow-x-auto px-3 py-2 font-mono text-xs whitespace-nowrap text-foreground">
                {command}
            </code>
            <button
                type="button"
                onClick={copy}
                className="flex shrink-0 items-center gap-1.5 border-l px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                {copied ? (
                    <Check className="size-3.5" aria-hidden="true" />
                ) : (
                    <Copy className="size-3.5" aria-hidden="true" />
                )}
                <span aria-live="polite">{copied ? 'Copied' : 'Copy'}</span>
            </button>
        </div>
    );
}

/** Shown when the scheduler is late or stopped: what it means and the fix. */
export function SchedulerBanner({
    insights,
}: {
    insights: AutomationInsights;
}) {
    const { state, heartbeatAt } = insights.scheduler;

    if (state === 'ok' || (state === 'never' && !insights.hasRuns)) {
        return null;
    }

    const danger = state !== 'late';

    return (
        <div
            role="alert"
            className={cn(
                'flex gap-3 rounded-xl border p-4',
                danger
                    ? 'border-danger/30 bg-danger-subtle'
                    : 'border-warning/30 bg-warning-subtle',
            )}
        >
            <AlertTriangle
                className={cn(
                    'mt-0.5 size-5 shrink-0',
                    danger ? 'text-danger-text' : 'text-warning-text',
                )}
                aria-hidden="true"
            />
            <div className="min-w-0 flex-1 space-y-2">
                <p
                    className={cn(
                        'font-medium',
                        danger ? 'text-danger-text' : 'text-warning-text',
                    )}
                >
                    {state === 'late'
                        ? 'The scheduler is running late'
                        : state === 'stopped'
                          ? 'The scheduler has stopped'
                          : 'The scheduler has never run on this server'}
                </p>
                <p className="text-sm text-foreground">
                    {heartbeatAt ? (
                        <>
                            Last tick <DateTime value={heartbeatAt} relative />
                            .{' '}
                        </>
                    ) : null}
                    Renewals, reminders, late fees and suspensions only run
                    while it does. Check that this cron entry exists for the web
                    server user (run{' '}
                    <code className="font-mono">crontab -e</code>), then reload
                    this page:
                </p>
                <CronCommand command={insights.cronCommand} />
            </div>
        </div>
    );
}

/** First run: no automation has ever been recorded. */
export function AutomationEmptyState({
    insights,
    scheduledAt,
    action,
}: {
    insights: AutomationInsights;
    scheduledAt: string;
    action?: ReactNode;
}) {
    return (
        <Panel
            title="Set up the daily automation"
            description="Nothing has run yet. Automation generates renewal invoices, sends reminders, applies late fees and suspends overdue services."
        >
            <ol className="space-y-4 text-sm">
                <li className="space-y-2">
                    <p>
                        <span className="font-medium">
                            1. Add the scheduler to cron.
                        </span>{' '}
                        <span className="text-muted-foreground">
                            It ticks every minute; run{' '}
                            <code className="font-mono">crontab -e</code> as the
                            web server user and add:
                        </span>
                    </p>
                    <CronCommand command={insights.cronCommand} />
                </li>
                <li>
                    <span className="font-medium">2. Check the time.</span>{' '}
                    <span className="text-muted-foreground">
                        The pipeline runs daily at {scheduledAt} company time
                        (Settings → Company).
                    </span>
                </li>
                <li className="flex flex-wrap items-center gap-3">
                    <span>
                        <span className="font-medium">3. Run it once now</span>{' '}
                        <span className="text-muted-foreground">
                            to confirm everything works.
                        </span>
                    </span>
                    {action}
                </li>
            </ol>
        </Panel>
    );
}

/** Summary cards, runs per day, items processed today and time per stage. */
export function AutomationSummary({
    insights,
    failedHref,
}: {
    insights: AutomationInsights;
    failedHref: string;
}) {
    const { scheduler, successRate, duration } = insights;
    const nextRun = nextRunParts(insights.nextRunAt);
    const schedulerTone =
        scheduler.state === 'ok'
            ? 'default'
            : scheduler.state === 'late'
              ? 'warning'
              : 'danger';
    const runsChart: ChartWidget = {
        key: 'automation.per_day',
        size: 'lg',
        type: 'chart',
        title: 'Runs per day',
        description: `Last ${insights.perDay.length} days · tasks that succeeded, failed or did not run`,
        kind: 'bar',
        stacked: true,
        xFormat: 'day',
        empty: 'No runs in the last 30 days.',
        series: [
            {
                name: 'Succeeded',
                tone: 'success',
                points: insights.perDay.map((day) => ({
                    x: day.x,
                    y: day.success,
                })),
            },
            {
                name: 'Failed',
                tone: 'danger',
                points: insights.perDay.map((day) => ({
                    x: day.x,
                    y: day.failed,
                })),
            },
            {
                name: 'Not run',
                tone: 'neutral',
                points: insights.perDay.map((day) => ({
                    x: day.x,
                    y: day.missing,
                })),
            },
        ],
    };
    const slowest = Math.max(
        1,
        ...insights.stageDurations.map((stage) => stage.averageMs),
    );

    return (
        <>
            <div className="grid grid-cols-2 gap-(--page-gap) md:grid-cols-3 xl:grid-cols-5">
                <StatCard
                    label="Scheduler"
                    value={SCHEDULER_LABEL[scheduler.state]}
                    icon={Clock}
                    tone={schedulerTone}
                    hint={
                        scheduler.heartbeatAt ? (
                            <>
                                Last tick{' '}
                                <DateTime
                                    value={scheduler.heartbeatAt}
                                    relative
                                />
                            </>
                        ) : (
                            'No tick recorded yet'
                        )
                    }
                />
                <StatCard
                    label="Success rate, 7 days"
                    value={
                        successRate.value === null
                            ? '—'
                            : `${successRate.value}%`
                    }
                    icon={Gauge}
                    trend={successRate.trend ?? undefined}
                    hint={`${successRate.succeeded} of ${successRate.total} runs`}
                />
                <StatCard
                    label="Failed, last 24 hours"
                    value={insights.failed24h}
                    icon={XCircle}
                    tone={insights.failed24h > 0 ? 'danger' : 'default'}
                    hint={
                        insights.failed24h > 0
                            ? 'Open the failed runs'
                            : 'No failures'
                    }
                    href={insights.failed24h > 0 ? failedHref : undefined}
                />
                <StatCard
                    label="Next scheduled run"
                    value={nextRun.time}
                    icon={CalendarClock}
                    hint={
                        <>
                            {nextRun.day} ·{' '}
                            <DateTime value={insights.nextRunAt} relative />
                        </>
                    }
                />
                <StatCard
                    label="Average run time"
                    value={
                        duration.averageMs === null
                            ? '—'
                            : formatDuration(duration.averageMs)
                    }
                    icon={Timer}
                    trend={duration.trend ?? undefined}
                    hint="per day, last 7 days"
                    sparkline={duration.sparkline}
                    className="col-span-2 md:col-span-1"
                />
            </div>

            <div className="grid gap-(--page-gap) xl:grid-cols-3">
                <ChartCard widget={runsChart} className="xl:col-span-2" />
                <Panel
                    title="Processed today"
                    description="Items reported by each stage's tasks"
                >
                    {insights.processedToday.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">
                            Today's pipeline has not run yet.
                        </p>
                    ) : (
                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                            {insights.processedToday.map((stage) => (
                                <div key={stage.stage} className="contents">
                                    <dt className="text-muted-foreground">
                                        {STAGES[stage.stage] ??
                                            `Stage ${stage.stage}`}
                                        <span className="text-xs">
                                            {' '}
                                            · {stage.tasks}{' '}
                                            {stage.tasks === 1
                                                ? 'task'
                                                : 'tasks'}
                                        </span>
                                    </dt>
                                    <dd className="text-right font-medium tabular-nums">
                                        {stage.count.toLocaleString()}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </Panel>
            </div>

            <BreakdownCard
                widget={{
                    key: 'automation.stages',
                    size: 'md',
                    type: 'breakdown',
                    title: 'Time per stage',
                    description: 'Average per day, last 7 days',
                    empty: 'No runs in the last 7 days.',
                    rows: insights.stageDurations.map((stage) => ({
                        key: String(stage.stage),
                        label: STAGES[stage.stage] ?? `Stage ${stage.stage}`,
                        display: formatDuration(stage.averageMs),
                        share: Math.round((stage.averageMs / slowest) * 100),
                    })),
                }}
                shareLabel="of the slowest stage"
                columns
            />
        </>
    );
}
