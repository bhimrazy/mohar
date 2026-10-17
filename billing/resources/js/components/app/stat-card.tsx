import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { Href } from '@/types/app';

export type StatTrend = {
    /** Pre-formatted change, e.g. "12%" or "+$1,200". */
    value: string;
    direction: 'up' | 'down' | 'flat';
    /** Whether "up" is good (income) or bad (overdue). Default true. */
    upIsGood?: boolean;
};

type StatCardProps = {
    label: string;
    value: ReactNode;
    /** Short context line, e.g. "vs. last month" or "3 invoices". */
    hint?: ReactNode;
    trend?: StatTrend;
    icon?: LucideIcon;
    href?: Href;
    loading?: boolean;
    className?: string;
};

function TrendBadge({ trend }: { trend: StatTrend }) {
    const upIsGood = trend.upIsGood ?? true;
    const isGood =
        trend.direction === 'flat'
            ? null
            : (trend.direction === 'up') === upIsGood;
    const Icon =
        trend.direction === 'up'
            ? ArrowUpRight
            : trend.direction === 'down'
              ? ArrowDownRight
              : ArrowRight;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 text-xs font-medium tabular-nums',
                isGood === true && 'text-emerald-700 dark:text-emerald-400',
                isGood === false && 'text-red-700 dark:text-red-400',
                isGood === null && 'text-muted-foreground',
            )}
        >
            <Icon className="size-3.5" aria-hidden="true" />
            <span className="sr-only">
                {trend.direction === 'flat'
                    ? 'No change'
                    : trend.direction === 'up'
                      ? 'Up'
                      : 'Down'}
            </span>
            {trend.value}
        </span>
    );
}

export function StatCard({
    label,
    value,
    hint,
    trend,
    icon: Icon,
    href,
    loading = false,
    className,
}: StatCardProps) {
    const body = (
        <>
            <div className="flex items-center justify-between gap-2">
                <span className="text-sm text-muted-foreground">{label}</span>
                {Icon && (
                    <Icon
                        className="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                )}
            </div>
            {loading ? (
                <Skeleton className="h-8 w-28" />
            ) : (
                <div className="text-2xl font-semibold tracking-tight tabular-nums">
                    {value}
                </div>
            )}
            {(hint || trend) && !loading && (
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    {trend && <TrendBadge trend={trend} />}
                    {hint && <span className="truncate">{hint}</span>}
                </div>
            )}
        </>
    );

    const classes = cn(
        'flex flex-col gap-2 rounded-xl border bg-card p-4 text-card-foreground shadow-xs',
        href &&
            'transition-colors hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
        className,
    );

    return href ? (
        <Link href={href} className={classes} data-slot="stat-card">
            {body}
        </Link>
    ) : (
        <div className={classes} data-slot="stat-card">
            {body}
        </div>
    );
}
