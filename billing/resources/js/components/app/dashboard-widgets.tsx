import { Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { BreakdownCard } from '@/components/app/dashboard/breakdown-card';
import { ChartCard } from '@/components/app/dashboard/chart-card';
import { HealthCard } from '@/components/app/dashboard/health-card';
import { ListGroupCard } from '@/components/app/dashboard/list-group-card';
import { QuickActionsCard } from '@/components/app/dashboard/quick-actions-card';
import { useVariant } from '@/components/app/dashboard/reporting-currency';
import type {
    BreakdownWidget,
    ChartWidget,
    DashboardWidget,
    HealthCheck,
    ListWidgetData,
    StatWidget,
} from '@/components/app/dashboard/types';
import { ListWidget } from '@/components/app/list-widget';
import { NeedsAttention } from '@/components/app/needs-attention';
import type { TodoItem } from '@/components/app/needs-attention';
import { StatCard } from '@/components/app/stat-card';
import type { StatTrend } from '@/components/app/stat-card';
import { Skeleton } from '@/components/ui/skeleton';
import { formatMoney } from '@/lib/money';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';

export type {
    BreakdownWidget,
    ChartWidget,
    DashboardWidget,
    HealthCheck,
    ListWidgetData,
    StatWidget,
} from '@/components/app/dashboard/types';

/** The first stat cards by registry order are the primary KPIs; the rest form the counter strip. */
const PRIMARY_STATS = 4;

/** Rows shown per list card. */
const LIST_ROWS = 6;

function statValue(widget: StatWidget): string | number {
    if (widget.money) {
        return formatMoney(widget.money.amount, widget.money.currency);
    }

    return typeof widget.value === 'object' && widget.value !== null
        ? formatMoney(widget.value.amount, widget.value.currency)
        : widget.value;
}

function PrimaryStat({ widget: base }: { widget: StatWidget }) {
    const widget = useVariant(base);

    return (
        <StatCard
            label={widget.label}
            value={statValue(widget)}
            href={widget.href}
            icon={widget.icon ? navIcon(widget.icon) : undefined}
            hint={widget.hint}
            trend={widget.trend}
            sparkline={widget.sparkline}
            tone={widget.tone}
        />
    );
}

function TrendText({ trend }: { trend: StatTrend }) {
    const good =
        trend.direction === 'flat'
            ? null
            : (trend.direction === 'up') === (trend.upIsGood ?? true);
    const arrow =
        trend.direction === 'up' ? '↑' : trend.direction === 'down' ? '↓' : '→';

    return (
        <span
            className={cn(
                'font-medium tabular-nums',
                good === true && 'text-success-text',
                good === false && 'text-danger-text',
            )}
        >
            <span aria-hidden="true">{arrow}</span>
            <span className="sr-only">
                {trend.direction === 'up'
                    ? 'Up'
                    : trend.direction === 'down'
                      ? 'Down'
                      : 'No change'}
            </span>{' '}
            {trend.value.replace(/^-/, '')}
        </span>
    );
}

/** A compact counter: icon, label and value, with the context line or trend underneath. */
function StatTile({ widget: base }: { widget: StatWidget }) {
    const widget = useVariant(base);
    const Icon = widget.icon ? navIcon(widget.icon) : null;
    const body = (
        <>
            {Icon && (
                <span className="grid size-7 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground">
                    <Icon className="size-3.5" aria-hidden="true" />
                </span>
            )}
            <span className="min-w-0 flex-1">
                <span
                    className="line-clamp-2 block text-xs text-muted-foreground sm:truncate"
                    title={widget.label}
                >
                    {widget.label}
                </span>
                {(widget.trend || widget.hint) && (
                    <span className="block truncate text-[0.6875rem] text-muted-foreground">
                        {widget.trend ? (
                            <>
                                <TrendText trend={widget.trend} /> {widget.hint}
                            </>
                        ) : (
                            widget.hint
                        )}
                    </span>
                )}
            </span>
            <span className="font-numeric text-base font-semibold">
                {statValue(widget)}
            </span>
        </>
    );
    const classes =
        'flex min-h-14 min-w-0 items-center gap-2.5 rounded-lg border bg-card px-3 py-2 text-card-foreground elevation-card';

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

export function StatWidgets({
    widgets,
    className,
}: {
    widgets: StatWidget[];
    className?: string;
}) {
    if (widgets.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'grid grid-cols-2 gap-(--page-gap) xl:grid-cols-4',
                className,
            )}
        >
            {widgets.map((widget) => (
                <PrimaryStat key={widget.key} widget={widget} />
            ))}
        </div>
    );
}

function StatStrip({
    widgets,
    className,
}: {
    widgets: StatWidget[];
    className?: string;
}) {
    if (widgets.length === 0) {
        return null;
    }

    return (
        <section aria-label="Counters" className={className}>
            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6">
                {widgets.map((widget) => (
                    <li key={widget.key} className="grid min-w-0">
                        <StatTile widget={widget} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

type DetailCard =
    | { kind: 'list'; order: number; list: ListWidgetData }
    | { kind: 'group'; order: number; lists: ListWidgetData[] };

/** Lists in registry order; lists sharing a `group` merge into one tabbed card at the first member's place. */
function detailCards(lists: ListWidgetData[]): DetailCard[] {
    const cards: DetailCard[] = [];

    lists.forEach((list, index) => {
        if (!list.group) {
            cards.push({ kind: 'list', order: index, list });

            return;
        }

        const group = cards.find(
            (card): card is Extract<DetailCard, { kind: 'group' }> =>
                card.kind === 'group' && card.lists[0].group === list.group,
        );

        if (group) {
            group.lists.push(list);
        } else {
            cards.push({ kind: 'group', order: index, lists: [list] });
        }
    });

    return cards;
}

function TrendsSkeleton() {
    return (
        <>
            {[0, 1, 2].map((key) => (
                <Skeleton key={key} className="h-80 rounded-xl" />
            ))}
        </>
    );
}

/**
 * The admin dashboard body for kernel widgets. Layout is a policy over widget types, not keys, so a missing
 * module only removes its own cards: summary (KPIs, counter strip, Needs attention) → the money chart →
 * trends (other charts and breakdowns) → detail (health, lists, quick actions). On phones Needs attention
 * comes first.
 */
export function DashboardWidgets({
    widgets,
    todos,
    deferred,
    health,
}: {
    widgets: DashboardWidget[];
    todos: TodoItem[];
    /** Deferred widgets; undefined while loading. */
    deferred?: DashboardWidget[];
    /** System health checks; undefined while loading. */
    health?: HealthCheck[];
}) {
    const all = [...widgets, ...(deferred ?? [])];
    const stats = all.filter(
        (widget): widget is StatWidget => widget.type === 'stat',
    );
    const charts = all.filter(
        (widget): widget is ChartWidget => widget.type === 'chart',
    );
    const breakdowns = all.filter(
        (widget): widget is BreakdownWidget => widget.type === 'breakdown',
    );
    const lists = all.filter(
        (widget): widget is ListWidgetData => widget.type === 'list',
    );
    const hero = charts.find((chart) => chart.currency) ?? null;
    // Trends keep registry order across charts and breakdowns (both arrive in order within each prop).
    const trends = [...charts.filter((chart) => chart !== hero), ...breakdowns];
    const loading = deferred === undefined;
    const cards = detailCards(lists);
    const showHealth = health === undefined || health.length > 0;

    return (
        <>
            <div className="grid gap-(--page-gap) lg:grid-cols-3">
                <StatWidgets
                    widgets={stats.slice(0, PRIMARY_STATS)}
                    className="order-2 md:order-1 lg:col-span-3"
                />
                <StatStrip
                    widgets={stats.slice(PRIMARY_STATS)}
                    className="order-4 md:order-2 lg:col-span-3"
                />
                {hero ? (
                    <ChartCard
                        widget={hero}
                        prominent
                        className="order-3 lg:col-span-2"
                    />
                ) : (
                    loading && (
                        <Skeleton className="order-3 h-[26rem] rounded-xl lg:col-span-2" />
                    )
                )}
                <NeedsAttention
                    items={todos}
                    className={cn(
                        'order-first md:order-4',
                        !hero && !loading && 'lg:col-span-3',
                    )}
                />
            </div>

            {(trends.length > 0 || loading) && (
                <div className="grid items-stretch gap-(--page-gap) md:grid-cols-2 xl:grid-cols-3">
                    {loading ? (
                        <TrendsSkeleton />
                    ) : (
                        trends.map((widget) =>
                            widget.type === 'chart' ? (
                                <ChartCard key={widget.key} widget={widget} />
                            ) : (
                                <BreakdownCard
                                    key={widget.key}
                                    widget={widget}
                                />
                            ),
                        )
                    )}
                </div>
            )}

            <div className="grid items-stretch gap-(--page-gap) md:grid-cols-2 xl:grid-cols-3">
                {showHealth && <HealthCard checks={health} />}
                {cards.map((card) =>
                    card.kind === 'list' ? (
                        <ListWidget
                            key={card.list.key}
                            title={card.list.title}
                            href={card.list.href}
                            empty={card.list.empty}
                            items={card.list.items
                                .slice(0, LIST_ROWS)
                                .map((item) => ({
                                    ...item,
                                    href: item.href ?? undefined,
                                    meta: item.meta ?? undefined,
                                    status: item.status ?? undefined,
                                }))}
                        />
                    ) : (
                        <ListGroupCard
                            key={card.lists[0].key}
                            lists={card.lists}
                            className="xl:col-span-2"
                        />
                    ),
                )}
                <QuickActionsCard />
                {cards.length === 0 && !showHealth && todos.length === 0 && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <CheckCircle2 className="size-4" aria-hidden="true" />
                        No other widgets are enabled for your role.
                    </div>
                )}
            </div>
        </>
    );
}
