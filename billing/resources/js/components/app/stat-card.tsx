import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { lazy, Suspense } from 'react';
import type { ReactNode } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { Href } from '@/types/app';

// Recharts is only fetched by pages that actually draw a sparkline.
const Sparkline = lazy(() =>
    import('@/components/app/sparkline').then((module) => ({
        default: module.Sparkline,
    })),
);

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
    /** Recent values, drawn as a sparkline under the figure. */
    sparkline?: number[];
    /** Highlights money at risk (overdue) with the danger tone on the icon. */
    tone?: 'default' | 'danger' | 'warning';
    className?: string;
};

function trendIsGood(trend: StatTrend): boolean | null {
    if (trend.direction === 'flat') {
        return null;
    }

    return (trend.direction === 'up') === (trend.upIsGood ?? true);
}

function TrendBadge({ trend }: { trend: StatTrend }) {
    const isGood = trendIsGood(trend);
    const Icon =
        trend.direction === 'up'
            ? ArrowUpRight
            : trend.direction === 'down'
              ? ArrowDownRight
              : ArrowRight;

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-0.5 rounded-badge px-1.5 py-0.5 text-xs font-medium whitespace-nowrap tabular-nums',
                isGood === true && 'bg-success-subtle text-success-text',
                isGood === false && 'bg-danger-subtle text-danger-text',
                isGood === null && 'bg-muted text-muted-foreground',
            )}
        >
            <Icon className="size-3.5" aria-hidden="true" />
            <span className="sr-only">
                {trend.direction === 'flat'
                    ? 'No change'
                    : `${trend.direction === 'up' ? 'Up' : 'Down'} (${isGood ? 'good' : 'needs attention'})`}
            </span>
            {trend.value}
        </span>
    );
}

const ICON_TONES = {
    default: 'bg-muted text-muted-foreground',
    danger: 'bg-danger-subtle text-danger-text',
    warning: 'bg-warning-subtle text-warning-text',
} as const;

export function StatCard({
    label,
    value,
    hint,
    trend,
    icon: Icon,
    href,
    loading = false,
    sparkline,
    tone = 'default',
    className,
}: StatCardProps) {
    // The pill says whether a change is good; the line stays calm unless the card itself is money at risk.
    const sparkColor = tone === 'danger' ? 'var(--danger)' : 'var(--chart-1)';

    const body = (
        <>
            <div className="flex min-h-10 items-start justify-between gap-2">
                <span
                    className="line-clamp-2 text-sm text-muted-foreground"
                    title={label}
                >
                    {label}
                </span>
                {Icon && (
                    <span
                        className={cn(
                            'grid size-7 place-items-center rounded-md',
                            ICON_TONES[tone],
                        )}
                    >
                        <Icon className="size-4" aria-hidden="true" />
                    </span>
                )}
            </div>
            {loading ? (
                <Skeleton className="h-8 w-28" />
            ) : (
                <div className="font-display font-numeric text-xl leading-tight sm:text-2xl">
                    {value}
                </div>
            )}
            {(hint || trend) && !loading && (
                <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                    {trend && <TrendBadge trend={trend} />}
                    {hint && <span className="truncate">{hint}</span>}
                </div>
            )}
            {sparkline && sparkline.length > 1 && !loading && (
                <Suspense fallback={<div className="mt-1 h-10" />}>
                    <Sparkline
                        values={sparkline}
                        color={sparkColor}
                        className="mt-1"
                    />
                </Suspense>
            )}
        </>
    );

    const classes = cn(
        'flex flex-col gap-2 rounded-xl border bg-card p-(--stat-p) text-card-foreground elevation-card',
        href &&
            'group/stat cursor-pointer transition-[border-color,box-shadow] hover:border-primary/35 hover:elevation-hover focus-visible:border-primary/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
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
