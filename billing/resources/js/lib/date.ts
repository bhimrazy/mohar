import { DEFAULT_LOCALE } from '@/lib/money';

export type DateFormat = 'date' | 'datetime' | 'time';

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

const formatOptions: Record<DateFormat, Intl.DateTimeFormatOptions> = {
    date: { dateStyle: 'medium' },
    datetime: { dateStyle: 'medium', timeStyle: 'short' },
    time: { timeStyle: 'short' },
};

/**
 * Parses an ISO string. Date-only values ("2026-03-04", e.g. due dates) are
 * calendar dates, not instants, so they are always formatted in UTC to avoid
 * shifting a day for users west of UTC.
 */
export function parseDate(
    value: string | Date,
): { date: Date; dateOnly: boolean } | null {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime())
            ? null
            : { date: value, dateOnly: false };
    }

    const dateOnly = DATE_ONLY.test(value);
    const date = new Date(dateOnly ? `${value}T00:00:00Z` : value);

    return Number.isNaN(date.getTime()) ? null : { date, dateOnly };
}

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** Admin → Settings → Company → Date format (PHP tokens), shared as `dateFormat`. */
let dateFormatSetting: string | null = null;

export function setDateFormat(format: string | null | undefined): void {
    dateFormatSetting = format && /[YmndjM]/.test(format) ? format : null;
}

/** Formats with the PHP-style tokens the date format setting offers: Y m n d j M. */
export function formatWithPattern(
    date: Date,
    pattern: string,
    utc: boolean,
): string {
    const year = utc ? date.getUTCFullYear() : date.getFullYear();
    const month = utc ? date.getUTCMonth() : date.getMonth();
    const day = utc ? date.getUTCDate() : date.getDate();
    const pad = (n: number) => String(n).padStart(2, '0');
    const tokens: Record<string, string> = {
        Y: String(year),
        m: pad(month + 1),
        n: String(month + 1),
        d: pad(day),
        j: String(day),
        M: MONTHS[month],
    };

    return pattern.replace(/[YmndjM]/g, (token) => tokens[token]);
}

export function formatDate(
    value: string | Date,
    format: DateFormat = 'date',
    locale: string = DEFAULT_LOCALE,
): string {
    const parsed = parseDate(value);

    if (!parsed) {
        return '';
    }

    if (dateFormatSetting && format !== 'time') {
        const day = formatWithPattern(
            parsed.date,
            dateFormatSetting,
            parsed.dateOnly,
        );

        return format === 'datetime' && !parsed.dateOnly
            ? `${day}, ${new Intl.DateTimeFormat(locale, formatOptions.time).format(parsed.date)}`
            : day;
    }

    return new Intl.DateTimeFormat(locale, {
        ...formatOptions[parsed.dateOnly ? 'date' : format],
        ...(parsed.dateOnly ? { timeZone: 'UTC' } : {}),
    }).format(parsed.date);
}

const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 60 * 60 * 24 * 365],
    ['month', 60 * 60 * 24 * 30],
    ['week', 60 * 60 * 24 * 7],
    ['day', 60 * 60 * 24],
    ['hour', 60 * 60],
    ['minute', 60],
];

/** "3 days ago", "in 2 hours", "just now". */
export function formatRelative(
    value: string | Date,
    locale: string = DEFAULT_LOCALE,
    now: Date = new Date(),
): string {
    const parsed = parseDate(value);

    if (!parsed) {
        return '';
    }

    const seconds = Math.round((parsed.date.getTime() - now.getTime()) / 1000);
    const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.round(seconds / size), unit);
        }
    }

    return 'just now';
}
