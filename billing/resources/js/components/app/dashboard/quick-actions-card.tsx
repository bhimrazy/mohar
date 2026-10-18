import { Link, usePage } from '@inertiajs/react';
import { useId } from 'react';
import { Kbd } from '@/components/app/kbd';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';

/** Create shortcuts from Navigation::quickActions() (the same source as the header "New" menu). */
export function QuickActionsCard({ className }: { className?: string }) {
    const { quickActions } = usePage().props;
    const titleId = useId();

    if (!quickActions || quickActions.length === 0) {
        return null;
    }

    return (
        <section
            aria-labelledby={titleId}
            className={cn(
                'flex min-w-0 flex-col overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            <header className="flex items-center justify-between gap-3 px-(--stat-p) pt-(--stat-p) pb-3">
                <h2 id={titleId} className="font-display text-base">
                    Quick actions
                </h2>
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    Search anything
                    <Kbd>⌘K</Kbd>
                </span>
            </header>
            <ul className="grid flex-1 content-start gap-0.5 border-t p-1.5 sm:grid-cols-2">
                {quickActions.map((action) => {
                    const Icon = navIcon(action.icon);

                    return (
                        <li key={action.href}>
                            <Link
                                href={action.href}
                                className="group flex items-center gap-3 rounded-md px-2.5 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <span className="grid size-7 shrink-0 place-items-center rounded-md border bg-background text-muted-foreground group-hover:text-accent-foreground">
                                    <Icon
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span className="truncate">{action.title}</span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
