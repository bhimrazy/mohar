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
    href?: string;
};

export type DashboardWidget = StatWidget | ListWidgetData | ChartWidget;

const SPAN: Record<WidgetBase['size'], string> = {
    sm: 'lg:col-span-1',
    md: 'lg:col-span-2',
    lg: 'lg:col-span-4',
};

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

function Widget({ widget }: { widget: ListWidgetData | ChartWidget }) {
    if (widget.type === 'list') {
        return (
            <ListWidget
                className={SPAN[widget.size]}
                title={widget.title}
                items={widget.items}
                href={widget.href}
                empty={widget.empty}
            />
        );
    }

    return (
        <section
            className={cn(
                'flex min-w-0 flex-col rounded-xl border bg-card p-(--stat-p) text-card-foreground elevation-card',
                SPAN[widget.size],
            )}
        >
            <h2 className="mb-3 font-display text-base">{widget.title}</h2>
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

    return (
        <>
            <StatWidgets widgets={stats} />
            <div className="grid gap-(--page-gap) lg:grid-cols-4">
                <NeedsAttention items={todos} className="lg:col-span-2" />
                {blocks.map((widget) => (
                    <Widget key={widget.key} widget={widget} />
                ))}
                {deferred === undefined && (
                    <Skeleton className="h-72 rounded-xl lg:col-span-2" />
                )}
                {blocks.length === 0 &&
                    deferred !== undefined &&
                    todos.length === 0 && (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground lg:col-span-2">
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
