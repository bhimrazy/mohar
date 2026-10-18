import { cn } from '@/lib/utils';

export type StatusTone =
    | 'success'
    | 'warning'
    | 'danger'
    | 'info'
    | 'neutral'
    | 'outline';

/**
 * The single source of truth for status colours across admin and client
 * area. Keys are normalised to kebab-case ("customer_reply" → "customer-reply").
 */
const STATUS_TONES: Record<string, StatusTone> = {
    // Healthy / settled
    active: 'success',
    paid: 'success',
    open: 'success',
    answered: 'success',
    accepted: 'success',
    published: 'success',
    completed: 'success',
    cleared: 'success',
    sent: 'success',
    online: 'success',
    resolved: 'success',
    pass: 'success',

    // Waiting on someone
    pending: 'warning',
    unpaid: 'warning',
    'pending-transfer': 'warning',
    'pending-registration': 'warning',
    'customer-reply': 'warning',
    grace: 'warning',
    'partially-paid': 'warning',
    scheduled: 'warning',
    review: 'warning',
    identified: 'warning',

    // Needs attention / money at risk
    overdue: 'danger',
    suspended: 'danger',
    fraud: 'danger',
    expired: 'danger',
    redemption: 'danger',
    collections: 'danger',
    failed: 'danger',
    outage: 'danger',
    reversed: 'danger',
    fail: 'danger',
    error: 'danger',

    // In motion / informational
    refunded: 'info',
    delivered: 'info',
    'in-progress': 'info',
    investigating: 'info',
    monitoring: 'info',

    // Finished / inert
    cancelled: 'neutral',
    terminated: 'neutral',
    closed: 'neutral',
    inactive: 'neutral',
    declined: 'neutral',
    'on-hold': 'neutral',
    'transferred-away': 'neutral',
    hidden: 'neutral',
    skipped: 'neutral',

    // Not yet issued
    draft: 'outline',
};

const TONE_CLASSES: Record<StatusTone, string> = {
    success:
        'border-emerald-600/20 bg-emerald-500/10 text-emerald-700 dark:border-emerald-400/20 dark:text-emerald-400',
    warning:
        'border-amber-600/25 bg-amber-500/10 text-amber-800 dark:border-amber-400/20 dark:text-amber-400',
    danger: 'border-red-600/20 bg-red-500/10 text-red-700 dark:border-red-400/25 dark:text-red-400',
    info: 'border-sky-600/20 bg-sky-500/10 text-sky-700 dark:border-sky-400/20 dark:text-sky-400',
    neutral: 'border-border bg-muted text-muted-foreground',
    outline: 'border-dashed border-border bg-transparent text-muted-foreground',
};

const DOT_CLASSES: Record<StatusTone, string> = {
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-red-500',
    info: 'bg-sky-500',
    neutral: 'bg-muted-foreground/60',
    outline: 'bg-muted-foreground/40',
};

export function normalizeStatus(status: string): string {
    return status
        .trim()
        .toLowerCase()
        .replace(/[\s_]+/g, '-');
}

export function statusTone(status: string): StatusTone {
    return STATUS_TONES[normalizeStatus(status)] ?? 'neutral';
}

/** "customer-reply" → "Customer reply" */
export function statusLabel(status: string): string {
    const words = normalizeStatus(status).replace(/-/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

type StatusBadgeProps = {
    status: string;
    /** Override the humanised label (e.g. a translated string). */
    label?: string;
    /** Force a tone instead of looking it up. */
    tone?: StatusTone;
    className?: string;
};

export function StatusBadge({
    status,
    label,
    tone,
    className,
}: StatusBadgeProps) {
    const resolvedTone = tone ?? statusTone(status);

    return (
        <span
            data-slot="status-badge"
            data-status={normalizeStatus(status)}
            className={cn(
                'inline-flex w-fit shrink-0 items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                TONE_CLASSES[resolvedTone],
                className,
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'size-1.5 rounded-full',
                    DOT_CLASSES[resolvedTone],
                )}
            />
            {label ?? statusLabel(status)}
        </span>
    );
}

/** Tinted surface for banners and callouts, from the same tone palette as badges. */
export function toneSurface(tone: StatusTone): string {
    return TONE_CLASSES[tone];
}
