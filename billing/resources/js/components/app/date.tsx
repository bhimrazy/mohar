import { formatDate, formatRelative, parseDate } from '@/lib/date';
import type { DateFormat } from '@/lib/date';
import { cn } from '@/lib/utils';

type DateTimeProps = {
    /** ISO 8601 string ("2026-03-04" or "2026-03-04T10:15:00Z"). */
    value: string | Date | null | undefined;
    /** Show "3 days ago"; the absolute value is kept in the tooltip. */
    relative?: boolean;
    format?: DateFormat;
    locale?: string;
    /** Rendered when value is empty. */
    fallback?: string;
    className?: string;
};

export function DateTime({
    value,
    relative = false,
    format = 'date',
    locale,
    fallback = '—',
    className,
}: DateTimeProps) {
    const parsed = value ? parseDate(value) : null;

    if (!value || !parsed) {
        return <span className="text-muted-foreground">{fallback}</span>;
    }

    const absolute = formatDate(value, relative ? 'datetime' : format, locale);

    return (
        <time
            dateTime={typeof value === 'string' ? value : value.toISOString()}
            title={absolute}
            className={cn('whitespace-nowrap', className)}
            suppressHydrationWarning
        >
            {relative ? formatRelative(value, locale) : absolute}
        </time>
    );
}
