import type {
    DashboardWidget,
    ListWidgetData,
    StatWidget,
} from '@/components/app/dashboard/types';
import { ListWidget } from '@/components/app/list-widget';
import { StatCard } from '@/components/app/stat-card';
import { formatMoney } from '@/lib/money';
import { navIcon } from '@/lib/nav-icons';

function statValue(widget: StatWidget) {
    return widget.money
        ? formatMoney(widget.money.amount, widget.money.currency)
        : (widget.value as string | number);
}

function Stat({ widget }: { widget: StatWidget }) {
    return (
        <StatCard
            label={widget.label}
            value={statValue(widget)}
            href={widget.href}
            icon={widget.icon ? navIcon(widget.icon) : undefined}
            hint={widget.hint}
            tone={widget.tone}
        />
    );
}

/**
 * The account at a glance: money first (stat cards flagged `primary`), then counts, then the latest rows. All of it is
 * contributed by the modules the staff member may see, so a missing module simply leaves its part out.
 */
export function ClientSummary({ widgets }: { widgets: DashboardWidget[] }) {
    const stats = widgets.filter(
        (widget): widget is StatWidget => widget.type === 'stat',
    );
    const lists = widgets.filter(
        (widget): widget is ListWidgetData => widget.type === 'list',
    );
    const money = stats.filter((widget) => widget.primary);
    const counts = stats.filter((widget) => !widget.primary);

    if (widgets.length === 0) {
        return null;
    }

    return (
        <section aria-label="Account summary" className="flex flex-col gap-4">
            {money.length > 0 && (
                <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 lg:grid-cols-4">
                    {money.map((widget) => (
                        <Stat key={widget.key} widget={widget} />
                    ))}
                </div>
            )}
            {counts.length > 0 && (
                <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 lg:grid-cols-4">
                    {counts.map((widget) => (
                        <Stat key={widget.key} widget={widget} />
                    ))}
                </div>
            )}
            {lists.length > 0 && (
                <div className="grid gap-4 lg:grid-cols-2">
                    {lists.map((widget) => (
                        <ListWidget
                            key={widget.key}
                            title={widget.title}
                            items={widget.items}
                            href={widget.href}
                            empty={widget.empty}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}
