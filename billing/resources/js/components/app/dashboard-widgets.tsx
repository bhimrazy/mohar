import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import { ListWidget } from '@/components/app/list-widget';
import type { ListWidgetItem } from '@/components/app/list-widget';
import { NeedsAttention } from '@/components/app/needs-attention';
import type { TodoItem } from '@/components/app/needs-attention';
import { StatCard } from '@/components/app/stat-card';
import type { StatTrend } from '@/components/app/stat-card';
import { statusTone, TONE_DOT } from '@/components/app/status-badge';
import type { StatusTone } from '@/components/app/status-badge';
import { TimeSeriesChart } from '@/components/app/time-series-chart';
import type { ChartSeries } from '@/components/app/time-series-chart';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatMoney } from '@/lib/money';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';

/** Shapes produced by App\Support\Dashboard\Dashboard::resolveWidgets(). */
type WidgetBase = { key: string; size: 'sm' | 'md' | 'lg' };

export type StatWidget = WidgetBase & {
    type: 'stat';
    label: string;
    /** Plain value, or money in minor units. */
    value: string | number | { amount: number; currency: string };
    href?: string;
    icon?: string;
    hint?: string;
    trend?: StatTrend;
    sparkline?: number[];
    tone?: 'default' | 'danger' | 'warning';
};

export type ListWidgetData = WidgetBase & {
    type: 'list';
    title: string;
    items: ListWidgetItem[];
    href?: string;
    empty?: string;
};

export type ChartWidget = WidgetBase & {
    type: 'chart';
    title: string;
    kind?: 'line' | 'bar';
    currency?: string;
    series: ChartSeries[];
    /** Optional per-currency series; enables the currency switch. */
    currencies?: Record<string, ChartSeries[]>;
    href?: string;
};

export type DashboardWidget = StatWidget | ListWidgetData | ChartWidget;

/** Stat cards shown full size; the rest become compact tiles in one row. */
const PRIMARY_STATS = 4;

/** Lists longer than this collapse into a summary + the most urgent rows. */
const LIST_ROWS = 5;

function statValue(value: StatWidget['value']): string | number {
    return typeof value === 'object' && value !== null
        ? formatMoney(value.amount, value.currency)
        : value;
}

function StatTile({ widget }: { widget: StatWidget }) {
    const Icon = widget.icon ? navIcon(widget.icon) : null;
    const body = (
        <>
            {Icon && (
                <Icon
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            )}
            <span
                className="min-w-0 flex-1 truncate text-sm text-muted-foreground"
                title={widget.label}
            >
                {widget.label}
            </span>
            <span className="font-numeric text-base font-semibold">
                {statValue(widget.value)}
            </span>
        </>
    );
    const classes =
        'flex items-center gap-2.5 rounded-lg border bg-card px-3 py-2.5 text-card-foreground elevation-card';

    return widget.href ? (
        <Link
            href={widget.href}
            className={cn(
                classes,
                'transition-[border-color,box-shadow] hover:border-primary/35 hover:elevation-hover focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
            )}
        >
            {body}
        </Link>
    ) : (
        <div className={classes}>{body}</div>
    );
}

export function StatWidgets({ widgets }: { widgets: StatWidget[] }) {
    if (widgets.length === 0) {
        return null;
    }

    const primary = widgets.slice(0, PRIMARY_STATS);
    const more = widgets.slice(PRIMARY_STATS);

    return (
        <div className="flex flex-col gap-3">
            <div className="grid grid-cols-2 gap-(--page-gap) xl:grid-cols-4">
                {primary.map((widget) => (
                    <StatCard
                        key={widget.key}
                        label={widget.label}
                        value={statValue(widget.value)}
                        href={widget.href}
                        icon={widget.icon ? navIcon(widget.icon) : undefined}
                        hint={widget.hint}
                        trend={widget.trend}
                        sparkline={widget.sparkline}
                        tone={widget.tone}
                    />
                ))}
            </div>
            {more.length > 0 && (
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                    {more.map((widget) => (
                        <StatTile key={widget.key} widget={widget} />
                    ))}
                </div>
            )}
        </div>
    );
}

function periodCaption(series: ChartSeries[], currency?: string): string {
    const points = series[0]?.points ?? [];

    if (points.length < 2) {
        return '';
    }

    const day = (x: string) => new Date(`${x.slice(0, 10)}T00:00:00`).getTime();
    const gap = (day(points[1].x) - day(points[0].x)) / 86_400_000;
    const unit = gap >= 27 ? 'months' : gap >= 6 ? 'weeks' : 'days';
    const total = points.reduce((sum, point) => sum + point.y, 0);
    const totalLabel = currency
        ? formatMoney(total, currency)
        : total.toLocaleString();

    return `Last ${points.length} ${unit} · ${totalLabel} total`;
}

function ChartCard({
    widget,
    prominent = false,
    className,
}: {
    widget: ChartWidget;
    prominent?: boolean;
    className?: string;
}) {
    const codes = Object.keys(widget.currencies ?? {});
    const [currency, setCurrency] = useState(widget.currency ?? codes[0]);
    const series = (currency && widget.currencies?.[currency]) || widget.series;
    // Money over time reads best as a smooth area; counts keep the backend's choice.
    const kind = widget.currency ? 'line' : widget.kind;

    return (
        <section
            className={cn(
                'flex min-w-0 flex-col rounded-xl border bg-card p-(--stat-p) text-card-foreground elevation-card',
                className,
            )}
        >
            <div className="mb-3 flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                <div className="min-w-0">
                    <h2 className="font-display text-base">{widget.title}</h2>
                    <p className="text-xs text-muted-foreground">
                        {periodCaption(series, currency)}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {codes.length > 1 && (
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={currency}
                            onValueChange={(value) =>
                                value && setCurrency(value)
                            }
                            aria-label="Currency"
                        >
                            {codes.map((code) => (
                                <ToggleGroupItem
                                    key={code}
                                    value={code}
                                    className="px-2.5 text-xs"
                                >
                                    {code}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    )}
                    {widget.href && (
                        <Link
                            href={widget.href}
                            className="inline-flex items-center gap-1 rounded-sm text-sm font-medium text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            View all
                            <ArrowRight
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        </Link>
                    )}
                </div>
            </div>
            <TimeSeriesChart
                label={`${widget.title}${currency ? ` in ${currency}` : ''}`}
                kind={kind}
                currency={currency}
                series={series}
                showLegend={series.length > 1}
                className={prominent ? 'h-72' : 'h-56'}
            />
        </section>
    );
}

const SEVERITY: StatusTone[] = [
    'danger',
    'warning',
    'info',
    'neutral',
    'outline',
    'success',
];

/** Long status lists (e.g. 17 automation tasks): counts per tone, then the most urgent rows. */
function CompactList({ widget }: { widget: ListWidgetData }) {
    const withStatus = widget.items.filter((item) => item.status);

    if (widget.items.length <= LIST_ROWS || withStatus.length === 0) {
        return (
            <ListWidget
                title={widget.title}
                items={widget.items.slice(0, LIST_ROWS + 1)}
                href={widget.href}
                empty={widget.empty}
            />
        );
    }

    const rank = (status?: string) =>
        status ? SEVERITY.indexOf(statusTone(status)) : SEVERITY.length;
    const sorted = [...widget.items].sort(
        (a, b) => rank(a.status) - rank(b.status),
    );
    const counts = new Map<string, { tone: StatusTone; count: number }>();

    for (const item of withStatus) {
        const label = item.status as string;
        const entry = counts.get(label) ?? {
            tone: statusTone(label),
            count: 0,
        };
        entry.count += 1;
        counts.set(label, entry);
    }

    const summary = [...counts.entries()].sort(
        (a, b) => SEVERITY.indexOf(a[1].tone) - SEVERITY.indexOf(b[1].tone),
    );
    const hidden = widget.items.length - LIST_ROWS;

    return (
        <ListWidget
            title={widget.title}
            href={widget.href}
            empty={widget.empty}
            items={sorted.slice(0, LIST_ROWS)}
            summary={
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    {summary.map(([status, { tone, count }]) => (
                        <span
                            key={status}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2 rounded-full',
                                    TONE_DOT[tone],
                                )}
                            />
                            <span className="font-semibold text-foreground tabular-nums">
                                {count}
                            </span>
                            {status.replace(/[_-]/g, ' ')}
                        </span>
                    ))}
                    <span className="ml-auto">
                        {hidden} more in the full log
                    </span>
                </div>
            }
        />
    );
}

/**
 * The admin dashboard body for kernel widgets. Layout policy (independent of registration
 * order): KPI row (4 cards + compact tiles) → the money chart with "Needs attention" beside it →
 * other charts first, then lists, packed into two balanced columns → full-width extras.
 */
export function DashboardWidgets({
    widgets,
    todos,
    deferred,
}: {
    widgets: DashboardWidget[];
    todos: TodoItem[];
    /** Deferred widgets; undefined while loading. */
    deferred?: DashboardWidget[];
}) {
    const all = [...widgets, ...(deferred ?? [])];
    const stats = all.filter(
        (widget): widget is StatWidget => widget.type === 'stat',
    );
    const charts = all.filter(
        (widget): widget is ChartWidget => widget.type === 'chart',
    );
    const lists = all.filter(
        (widget): widget is ListWidgetData => widget.type === 'list',
    );
    const hero = charts.find((chart) => chart.currency) ?? null;
    const otherCharts = charts.filter((chart) => chart !== hero);
    const loading = deferred === undefined;

    return (
        <>
            <StatWidgets widgets={stats} />

            {(hero || loading) && (
                <div className="grid items-start gap-(--page-gap) lg:grid-cols-3">
                    {hero ? (
                        <ChartCard
                            widget={hero}
                            prominent
                            className="lg:col-span-2"
                        />
                    ) : (
                        <Skeleton className="h-[22rem] rounded-xl lg:col-span-2" />
                    )}
                    <NeedsAttention items={todos} />
                </div>
            )}

            <div className="gap-(--page-gap) lg:columns-2 [&>*]:mb-(--page-gap) [&>*]:break-inside-avoid">
                {!hero && !loading && <NeedsAttention items={todos} />}
                {otherCharts.map((chart) => (
                    <ChartCard key={chart.key} widget={chart} />
                ))}
                {lists.map((list) => (
                    <CompactList key={list.key} widget={list} />
                ))}
                {lists.length === 0 &&
                    otherCharts.length === 0 &&
                    !loading &&
                    todos.length === 0 && (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <CheckCircle2
                                className="size-4"
                                aria-hidden="true"
                            />
                            No other widgets are enabled for your role.
                        </div>
                    )}
            </div>
        </>
    );
}
