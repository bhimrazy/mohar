import { formatMegabytes } from '@/types/services';
import { cn } from '@/lib/utils';

type UsageMeterProps = {
    label: string;
    used: number;
    limit: number | null;
    className?: string;
};

/** Disk/bandwidth bar: neutral until 90 % of the limit, then destructive. */
export function UsageMeter({ label, used, limit, className }: UsageMeterProps) {
    const percent = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;

    return (
        <div className={cn('space-y-1.5', className)}>
            <div className="flex items-baseline justify-between gap-2 text-sm">
                <span className="text-muted-foreground">{label}</span>
                <span className="tabular-nums">
                    {formatMegabytes(used)}
                    <span className="text-muted-foreground">
                        {' '}
                        / {formatMegabytes(limit)}
                    </span>
                </span>
            </div>
            <div
                className="h-2 overflow-hidden rounded-full bg-muted"
                role="meter"
                aria-label={label}
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    className={cn(
                        'h-full rounded-full transition-[width]',
                        percent >= 90 ? 'bg-destructive' : 'bg-primary/70',
                    )}
                    style={{ width: `${limit ? Math.max(percent, 2) : 0}%` }}
                />
            </div>
        </div>
    );
}
