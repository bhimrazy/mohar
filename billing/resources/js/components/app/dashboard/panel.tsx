import { Link } from '@inertiajs/react';
import { ArrowRight, Table2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Card shell shared by dashboard blocks: title, optional description, header actions, body. */
export function Panel({
    title,
    description,
    actions,
    children,
    className,
    bodyClassName,
    titleId,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    bodyClassName?: string;
    titleId?: string;
}) {
    return (
        <section
            aria-labelledby={titleId}
            className={cn(
                'flex min-w-0 flex-col rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            <header className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2 px-(--stat-p) pt-(--stat-p)">
                <div className="min-w-0 flex-1 basis-36 space-y-0.5">
                    <h2 id={titleId} className="font-display text-base">
                        {title}
                    </h2>
                    {description && (
                        <div className="text-sm text-muted-foreground">
                            {description}
                        </div>
                    )}
                </div>
                {actions && (
                    <div className="flex shrink-0 items-center gap-2">
                        {actions}
                    </div>
                )}
            </header>
            <div
                className={cn(
                    'flex flex-1 flex-col px-(--stat-p) pt-4 pb-(--stat-p)',
                    bodyClassName,
                )}
            >
                {children}
            </div>
        </section>
    );
}

export function ViewAllLink({ href }: { href: string }) {
    return (
        <Link
            href={href}
            className="inline-flex items-center gap-1 rounded-sm text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            View all
            <ArrowRight className="size-3.5" aria-hidden="true" />
        </Link>
    );
}

/** Toggles a chart's table view (the WCAG equivalent of the plot). */
export function TableToggle({
    pressed,
    onPressedChange,
    label,
}: {
    pressed: boolean;
    onPressedChange: (pressed: boolean) => void;
    label: string;
}) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            aria-label={`Show as table: ${label}`}
            title="Show as table"
            onClick={() => onPressedChange(!pressed)}
            className={cn(
                'grid size-8 place-items-center rounded-md border text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                pressed && 'bg-accent text-accent-foreground',
            )}
        >
            <Table2 className="size-4" aria-hidden="true" />
        </button>
    );
}
