import { Link } from '@inertiajs/react';
import { CheckCircle2, ChevronRight } from 'lucide-react';
import { useId } from 'react';
import { TONE_DOT } from '@/components/app/status-badge';
import { Skeleton } from '@/components/ui/skeleton';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';
import type { HealthCheck } from './types';

const ISSUE_TONES = ['danger', 'warning'];

/** System health: one row per check contributed by a module (Dashboard::health()). */
export function HealthCard({
    checks,
    className,
}: {
    /** Undefined while the deferred prop loads. */
    checks?: HealthCheck[];
    className?: string;
}) {
    const titleId = useId();
    const issues = (checks ?? []).filter((check) =>
        ISSUE_TONES.includes(check.tone),
    ).length;

    return (
        <section
            aria-labelledby={titleId}
            className={cn(
                'flex min-w-0 flex-col overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            <header className="flex items-start justify-between gap-3 px-(--stat-p) pt-(--stat-p) pb-3">
                <div className="min-w-0 space-y-0.5">
                    <h2 id={titleId} className="font-display text-base">
                        System health
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Scheduler, jobs, provisioning and gateways
                    </p>
                </div>
                {checks && checks.length > 0 && (
                    <span
                        className={cn(
                            'inline-flex shrink-0 items-center gap-1.5 rounded-badge px-2 py-0.5 text-xs font-medium',
                            issues > 0
                                ? 'bg-warning-subtle text-warning-text'
                                : 'bg-success-subtle text-success-text',
                        )}
                    >
                        <span
                            aria-hidden="true"
                            className={cn(
                                'size-1.5 rounded-full',
                                issues > 0 ? 'bg-warning' : 'bg-success',
                            )}
                        />
                        {issues > 0
                            ? `${issues} ${issues === 1 ? 'issue' : 'issues'}`
                            : 'All normal'}
                    </span>
                )}
            </header>
            {checks === undefined ? (
                <div className="space-y-3 border-t px-(--stat-p) py-4">
                    {[0, 1, 2, 3, 4].map((row) => (
                        <Skeleton key={row} className="h-9 w-full" />
                    ))}
                </div>
            ) : checks.length === 0 ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-2 border-t px-6 py-10 text-center">
                    <CheckCircle2
                        className="size-6 text-success-text"
                        aria-hidden="true"
                    />
                    <p className="text-sm text-muted-foreground">
                        No health checks are available for your role.
                    </p>
                </div>
            ) : (
                <ul className="divide-y border-t">
                    {checks.map((check) => {
                        const Icon = check.icon ? navIcon(check.icon) : null;
                        const content = (
                            <>
                                {Icon && (
                                    <Icon
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                )}
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium">
                                        {check.label}
                                    </span>
                                    {check.detail && (
                                        <span
                                            className="line-clamp-2 block text-xs text-muted-foreground"
                                            title={check.detail}
                                        >
                                            {check.detail}
                                        </span>
                                    )}
                                </span>
                                <span className="flex shrink-0 items-center gap-1.5 text-xs font-medium tabular-nums">
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'size-2 rounded-full',
                                            TONE_DOT[check.tone],
                                        )}
                                    />
                                    {check.value}
                                </span>
                                {check.href && (
                                    <ChevronRight
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                )}
                            </>
                        );
                        const row =
                            'flex items-center gap-3 px-(--stat-p) py-2.5';

                        return (
                            <li key={check.key}>
                                {check.href ? (
                                    <Link
                                        href={check.href}
                                        className={cn(
                                            row,
                                            'transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none',
                                        )}
                                    >
                                        {content}
                                    </Link>
                                ) : (
                                    <div className={row}>{content}</div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
