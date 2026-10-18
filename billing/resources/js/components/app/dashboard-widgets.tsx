import { CheckCircle2 } from 'lucide-react';
import { ListWidget } from '@/components/app/list-widget';
import type { ListWidgetItem } from '@/components/app/list-widget';
import { NeedsAttention } from '@/components/app/needs-attention';
import type { TodoItem } from '@/components/app/needs-attention';
import { StatCard } from '@/components/app/stat-card';
import type { StatTrend } from '@/components/app/stat-card';
import { TimeSeriesChart } from '@/components/app/time-series-chart';
import type { ChartSeries } from '@/components/app/time-series-chart';
import { Skeleton } from '@/components/ui/skeleton';
import { formatMoney } from '@/lib/money';
import { navIcon } from '@/lib/nav-icons';

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
    href?: string;
};

export type DashboardWidget = StatWidget | ListWidgetData | ChartWidget;

function statValue(value: StatWidget['value']): string | number {
    return typeof value === 'object' && value !== null
        ? formatMoney(value.amount, value.currency)
        : value;
}

export function StatWidgets({ widgets }: { widgets: StatWidget[] }) {
    if (widgets.length === 0) {
        return null;
    }

    return (
        <div className="grid grid-cols-2 gap-(--page-gap) xl:grid-cols-4">
            {widgets.map((widget) => (
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
    );
}

/** "Last 12 weeks · 16 total" from the series spacing and the first series' sum. */
function chartCaption(widget: ChartWidget): string {
    const points = widget.series[0]?.points ?? [];

    if (points.length < 2) {
        return '';
    }

    const day = (x: string) => new Date(`${x.slice(0, 10)}T00:00:00`).getTime();
    const gap = (day(points[1].x) - day(points[0].x)) / 86_400_000;
    const unit = gap >= 27 ? 'months' : gap >= 6 ? 'weeks' : 'days';
    const total = points.reduce((sum, point) => sum + point.y, 0);
    const totalLabel = widget.currency
        ? formatMoney(total, widget.currency)
        : total.toLocaleString();

    return `Last ${points.length} ${unit} · ${totalLabel} total`;
}

function Widget({ widget }: { widget: ListWidgetData | ChartWidget }) {
    if (widget.type === 'list') {
        return (
            <ListWidget
                title={widget.title}
                items={widget.items}
                href={widget.href}
                empty={widget.empty}
            />
        );
    }

    return (
        <section className="flex min-w-0 flex-col rounded-xl border bg-card p-(--stat-p) text-card-foreground elevation-card">
            <div className="mb-3 flex flex-wrap items-baseline justify-between gap-x-3">
                <h2 className="font-display text-base">{widget.title}</h2>
                <span className="text-xs text-muted-foreground">
                    {chartCaption(widget)}
                </span>
            </div>
            <TimeSeriesChart
                label={widget.title}
                kind={widget.kind}
                currency={widget.currency}
                series={widget.series}
                className="h-64"
            />
        </section>
    );
}

/**
 * The admin dashboard body for kernel widgets: stat row, "Needs attention" (todos) next to the
 * first widgets, then lists and charts by size (sm = 1/4, md = 1/2, lg = full row).
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
    const blocks = all.filter(
        (widget): widget is ListWidgetData | ChartWidget =>
            widget.type !== 'stat',
    );
    const halves = blocks.filter((widget) => widget.size !== 'lg');
    const fulls = blocks.filter((widget) => widget.size === 'lg');

    return (
        <>
            <StatWidgets widgets={stats} />
            {/* Half-width cards pack into two balanced columns (no stretched or empty cards);
                full-width widgets follow on their own rows. */}
            <div className="gap-(--page-gap) lg:columns-2 [&>*]:mb-(--page-gap) [&>*]:break-inside-avoid">
                <NeedsAttention items={todos} />
                {halves.map((widget) => (
                    <Widget key={widget.key} widget={widget} />
                ))}
                {deferred === undefined && (
                    <Skeleton className="h-72 rounded-xl" />
                )}
                {blocks.length === 0 &&
                    deferred !== undefined &&
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
            {fulls.map((widget) => (
                <Widget key={widget.key} widget={widget} />
            ))}
        </>
    );
}
