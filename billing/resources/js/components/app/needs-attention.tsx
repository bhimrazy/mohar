import { Link } from '@inertiajs/react';
import { CheckCircle2, ChevronRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { TONE_DOT, TONE_TEXT } from '@/components/app/status-badge';
import type { StatusTone } from '@/components/app/status-badge';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';

/** Matches Dashboard::resolveTodos() items. */
export type TodoItem = {
    key: string;
    label: string;
    count: number;
    href?: string;
    tone?: StatusTone;
    /** Optional nav icon name (see lib/nav-icons). */
    icon?: string;
    /** Short context, e.g. "oldest 3 days". */
    hint?: string;
};

type NeedsAttentionProps = {
    items: TodoItem[];
    title?: string;
    className?: string;
};

const ICON_SURFACE: Record<StatusTone, string> = {
    success: 'bg-success-subtle text-success-text',
    warning: 'bg-warning-subtle text-warning-text',
    danger: 'bg-danger-subtle text-danger-text',
    info: 'bg-info-subtle text-info-text',
    neutral: 'bg-muted text-muted-foreground',
    outline: 'bg-muted text-muted-foreground',
};

/** The admin "Needs attention" to-do widget: one row per queue with a count. */
export function NeedsAttention({
    items,
    title = 'Needs attention',
    className,
}: NeedsAttentionProps) {
    // Badge = everything waiting across the queues, coloured by the most severe one.
    const severity: StatusTone[] = ['danger', 'warning', 'info', 'neutral'];
    const worst =
        severity.find((tone) =>
            items.some((item) => (item.tone ?? 'warning') === tone),
        ) ?? 'neutral';

    return (
        <section
            data-slot="needs-attention"
            className={cn(
                'flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            <header className="flex items-center justify-between gap-3 px-(--stat-p) pt-(--stat-p) pb-3">
                <h2 className="font-display text-base">{title}</h2>
                {items.length > 0 && (
                    <span
                        className={cn(
                            'rounded-badge px-2 py-0.5 text-xs font-medium tabular-nums',
                            ICON_SURFACE[worst],
                        )}
                    >
                        {items.reduce((sum, item) => sum + item.count, 0)} open
                    </span>
                )}
            </header>

            {items.length === 0 ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-2 px-6 py-10 text-center">
                    <CheckCircle2
                        className="size-6 text-success-text"
                        aria-hidden="true"
                    />
                    <p className="text-sm font-medium">All caught up</p>
                    <p className="text-sm text-muted-foreground">
                        Nothing is waiting on staff right now.
                    </p>
                </div>
            ) : (
                <ul className="divide-y border-t">
                    {items.map((item) => {
                        const tone = item.tone ?? 'warning';
                        const Icon: LucideIcon | null = item.icon
                            ? navIcon(item.icon)
                            : null;
                        const content = (
                            <>
                                {Icon ? (
                                    <span
                                        className={cn(
                                            'grid size-8 shrink-0 place-items-center rounded-md',
                                            ICON_SURFACE[tone],
                                        )}
                                    >
                                        <Icon
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>
                                ) : (
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'size-2 shrink-0 rounded-full',
                                            TONE_DOT[tone],
                                        )}
                                    />
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {item.label}
                                    </span>
                                    {item.hint && (
                                        <span className="block truncate text-xs text-muted-foreground">
                                            {item.hint}
                                        </span>
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'font-numeric text-base font-semibold',
                                        TONE_TEXT[tone],
                                    )}
                                >
                                    {item.count}
                                </span>
                                {item.href && (
                                    <ChevronRight
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                )}
                            </>
                        );
                        const rowClass =
                            'flex items-center gap-3 px-(--stat-p) py-2.5';

                        return (
                            <li key={item.key}>
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
