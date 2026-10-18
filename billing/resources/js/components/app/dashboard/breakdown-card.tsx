import { Receipt } from 'lucide-react';
import { useId } from 'react';
import { formatMoney } from '@/lib/money';
import { Panel, ViewAllLink } from './panel';
import type { BreakdownWidget } from './types';

/** Part-to-whole as aligned meters (easier to compare than a donut), e.g. income by currency. */
export function BreakdownCard({
    widget,
    shareLabel = 'of the total',
    columns = false,
    className,
}: {
    widget: BreakdownWidget;
    /** Lay rows out in responsive columns (for full-width cards). */
    columns?: boolean;
    className?: string;
    /** What a row's share is relative to; a custom label also draws every meter in one colour (one series). */
    shareLabel?: string;
}) {
    const singleSeries = shareLabel !== 'of the total';
    const titleId = useId();
    const notes = widget.notes ?? [];

    return (
        <Panel
            titleId={titleId}
            className={className}
            title={widget.title}
            description={widget.description}
            actions={widget.href && <ViewAllLink href={widget.href} />}
        >
            {widget.rows.length === 0 ? (
                <p className="flex flex-1 items-center justify-center py-10 text-center text-sm text-muted-foreground">
                    {widget.empty ?? 'Nothing to show yet.'}
                </p>
            ) : (
                <ul
                    className={
                        columns
                            ? 'grid gap-x-8 gap-y-4 sm:grid-cols-2 xl:grid-cols-3'
                            : 'space-y-4'
                    }
                >
                    {widget.rows.map((row, index) => (
                        <li key={row.key} className="space-y-1.5">
                            <div className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="flex min-w-0 items-center gap-2">
                                    {row.code && (
                                        <span className="rounded-sm border px-1.5 py-px font-mono text-[0.6875rem] font-medium">
                                            {row.code}
                                        </span>
                                    )}
                                    <span className="truncate text-muted-foreground">
                                        {row.label}
                                    </span>
                                </span>
                                <span className="font-medium whitespace-nowrap tabular-nums">
                                    {row.display ??
                                        (row.money
                                            ? formatMoney(
                                                  row.money.amount,
                                                  row.money.currency,
                                              )
                                            : (
                                                  row.value ?? 0
                                              ).toLocaleString())}
                                </span>
                            </div>
                            <div
                                className="h-2 overflow-hidden rounded-full bg-muted"
                                role="meter"
                                aria-valuenow={row.share}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-label={`${row.label}: ${row.share}% ${shareLabel}`}
                            >
                                <div
                                    className="h-full rounded-full"
                                    style={{
                                        width: `${Math.max(row.share, 1)}%`,
                                        background: `var(--chart-${singleSeries ? 1 : (index % 6) + 1})`,
                                    }}
                                />
                            </div>
                            <div className="text-xs text-muted-foreground tabular-nums">
                                {row.share}% {shareLabel}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {notes.length > 0 && (
                <div className="mt-auto pt-5">
                    <div className="border-t pt-4">
                        <h3 className="mb-2 flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                            <Receipt className="size-3.5" aria-hidden="true" />
                            {widget.notesTitle ?? 'Notes'}
                        </h3>
                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
                            {notes.map((note) => (
                                <div
                                    key={`${note.label}-${note.money.currency}`}
                                    className="contents"
                                >
                                    <dt className="text-muted-foreground">
                                        {note.label}{' '}
                                        <span className="text-xs">
                                            ({note.money.currency})
                                        </span>
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {formatMoney(
                                            note.money.amount,
                                            note.money.currency,
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            )}
        </Panel>
    );
}
