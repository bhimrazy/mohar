import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateTime } from '@/components/app/date';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

/** Matches the backend `list` dashboard widget payload. */
export type ListWidgetItem = {
    title: ReactNode;
    subtitle?: ReactNode;
    href?: string;
    /** Right-aligned value: amount, relative time... */
    meta?: ReactNode;
    status?: string;
    /** Optional leading visual (avatar, icon). */
    leading?: ReactNode;
    /** Money in minor units; formatted with the currency's grouping and shown instead of `meta`. */
    money?: { amount: number; currency: string };
    /** A name shown as initials in front of the row (when no `leading` is given). */
    avatar?: string;
    /** ISO date shown after the subtitle, prefixed with `dateLabel` ("Renews", "Due"). */
    date?: string | null;
    dateLabel?: string;
    /** A button after the row's link ("Pay"); a sibling of the link, never nested in it. */
    action?: { label: string; href: string } | null;
};

type ListWidgetProps = {
    title: ReactNode;
    items: ListWidgetItem[];
    /** "View all" link. */
    href?: string;
    empty?: ReactNode;
    actions?: ReactNode;
    /** Shown between the header and the rows (e.g. status counts). */
    summary?: ReactNode;
    className?: string;
};

const ISO_DATETIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function Initials({ name }: { name: string }) {
    const initials = name
        .replace(/\(.*?\)/g, '')
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    return (
        <span
            aria-hidden="true"
            className="grid size-8 shrink-0 place-items-center rounded-full bg-accent text-xs font-semibold text-accent-foreground"
        >
            {initials}
        </span>
    );
}

/** Backend list items send ISO timestamps as `meta`; show them as relative times. */
function Meta({ value }: { value: ReactNode }) {
    if (typeof value === 'string' && ISO_DATETIME.test(value)) {
        return (
            <span className="text-xs text-muted-foreground">
                <DateTime value={value} relative />
            </span>
        );
    }

    if (typeof value === 'string' && ISO_DATE.test(value)) {
        return (
            <span className="text-xs text-muted-foreground">
                {new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                })}
            </span>
        );
    }

    return value;
}

export function ListWidget({
    title,
    items,
    href,
    empty = 'Nothing here yet.',
    actions,
    summary,
    className,
}: ListWidgetProps) {
    return (
        <section
            data-slot="list-widget"
            className={cn(
                'flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            <header className="flex items-center justify-between gap-3 px-(--stat-p) pt-(--stat-p) pb-3">
                <h2 className="font-display text-base">{title}</h2>
                <div className="flex items-center gap-2">
                    {actions}
                    {href && (
                        <Link
                            href={href}
                            className="inline-flex items-center gap-1 rounded-sm text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            View all
                            <ArrowRight
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        </Link>
                    )}
                </div>
            </header>
            {summary && (
                <div className="border-t bg-muted/30 px-(--stat-p) py-2">
                    {summary}
                </div>
            )}
            <ListRows items={items} empty={empty} />
        </section>
    );
}

/** The rows of a list widget (also used inside tabbed dashboard cards). */
export function ListRows({
    items,
    empty = 'Nothing here yet.',
}: {
    items: ListWidgetItem[];
    empty?: ReactNode;
}) {
    return (
        <>
            {items.length === 0 ? (
                <div className="border-t px-(--stat-p) py-8 text-center text-sm text-muted-foreground">
                    {empty}
                </div>
            ) : (
                <ul className="divide-y border-t">
                    {items.map((item, index) => {
                        const content = (
                            <>
                                {item.leading ??
                                    (item.avatar ? (
                                        <Initials name={item.avatar} />
                                    ) : null)}
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">
                                        {item.title}
                                    </div>
                                    {item.subtitle && !item.date && (
                                        <div className="truncate text-xs text-muted-foreground">
                                            {item.subtitle}
                                        </div>
                                    )}
                                    {item.date && (
                                        // The date gets its own line on phones so truncation never hides it.
                                        <div className="text-xs text-muted-foreground sm:truncate">
                                            {item.subtitle && (
                                                <span className="block truncate sm:inline">
                                                    {item.subtitle}
                                                    <span className="hidden sm:inline">
                                                        {' · '}
                                                    </span>
                                                </span>
                                            )}
                                            {item.dateLabel &&
                                                `${item.dateLabel} `}
                                            <DateTime value={item.date} />
                                        </div>
                                    )}
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-1">
                                    {item.money ? (
                                        <div className="text-sm font-medium whitespace-nowrap tabular-nums">
                                            {formatMoney(
                                                item.money.amount,
                                                item.money.currency,
                                            )}
                                        </div>
                                    ) : (
                                        item.meta && (
                                            <div className="text-sm tabular-nums">
                                                <Meta value={item.meta} />
                                            </div>
                                        )
                                    )}
                                    {item.status && (
                                        <StatusBadge status={item.status} />
                                    )}
                                </div>
                            </>
                        );
                        const rowClass =
                            'flex items-center gap-3 px-(--stat-p) py-(--cell-py)';

                        return (
                            <li
                                key={index}
                                className={cn(
                                    item.action && 'flex items-center',
                                )}
                            >
                                {item.href ? (
                                    <Link
                                        href={item.href}
                                        className={cn(
                                            rowClass,
                                            item.action && 'min-w-0 flex-1',
                                            'transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none',
                                        )}
                                    >
                                        {content}
                                    </Link>
                                ) : (
                                    <div
                                        className={cn(
                                            rowClass,
                                            item.action && 'min-w-0 flex-1',
                                        )}
                                    >
                                        {content}
                                    </div>
                                )}
                                {item.action && (
                                    <Button
                                        asChild
                                        size="sm"
                                        className="mr-(--stat-p) shrink-0"
                                    >
                                        <Link href={item.action.href}>
                                            {item.action.label}
                                        </Link>
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </>
    );
}
