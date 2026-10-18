import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateTime } from '@/components/app/date';
import { StatusBadge } from '@/components/app/status-badge';
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

/** Backend list items send ISO timestamps as `meta`; show them as relative times. */
function Meta({ value }: { value: ReactNode }) {
    return typeof value === 'string' && ISO_DATETIME.test(value) ? (
        <span className="text-xs text-muted-foreground">
            <DateTime value={value} relative />
        </span>
    ) : (
        value
    );
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
            {items.length === 0 ? (
                <p className="border-t px-(--stat-p) py-8 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <ul className="divide-y border-t">
                    {items.map((item, index) => {
                        const content = (
                            <>
                                {item.leading}
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">
                                        {item.title}
                                    </div>
                                    {item.subtitle && (
                                        <div className="truncate text-xs text-muted-foreground">
                                            {item.subtitle}
                                        </div>
                                    )}
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-1">
                                    {item.meta && (
                                        <div className="text-sm tabular-nums">
                                            <Meta value={item.meta} />
                                        </div>
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
                            <li key={index}>
                                {item.href ? (
                                    <Link
                                        href={item.href}
                                        className={cn(
                                            rowClass,
                                            'transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none',
                                        )}
                                    >
                                        {content}
                                    </Link>
                                ) : (
                                    <div className={rowClass}>{content}</div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
