import { Link, router } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { formatMoney } from '@/lib/money';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';

/** Matches the backend `list` dashboard widget payload. */
export type ListWidgetItem = {
    title: ReactNode;
    subtitle?: ReactNode;
    href?: string;
    /** Right-aligned value: amount, relative time... */
    meta?: ReactNode;
    status?: string;
    /** Optional leading visual (avatar, icon). */
    leading?: ReactNode;
    /** Money in minor units; formatted with the currency's grouping and shown instead of `meta`. */
    money?: { amount: number; currency: string };
    /** A name shown as initials in front of the row (when no `leading` is given). */
    avatar?: string;
    /** ISO date shown after the subtitle, prefixed with `dateLabel` ("Renews", "Due"). */
    date?: string | null;
    dateLabel?: string;
    /** A button after the row's link ("Pay"); a sibling of the link, never nested in it. */
    action?: ListWidgetAction | null;
    /** An on/off switch after the row's link that saves immediately (PUT `{ [field]: on }`). */
    toggle?: ListWidgetToggle | null;
};

export type ListWidgetAction = {
    label: string;
    href: string;
    /** Without `method` the button is a link; with it, a request (after `confirm`, when given). */
    method?: 'post';
    confirm?: {
        title: string;
        description: string;
        confirmLabel: string;
        data?: Record<string, string | number | boolean | null>;
    };
};

export type ListWidgetToggle = {
    /** Accessible name, e.g. "Auto-renew example.com.np". */
    label: string;
    /** Short visible caption ("Auto-renew"); the row title already names the item. */
    text?: string;
    href: string;
    field: string;
    on: boolean;
};

/** A link button in the list's header ("New ticket"). */
export type ListWidgetLink = { label: string; href: string; icon?: string };

type ListWidgetProps = {
    title: ReactNode;
    items: ListWidgetItem[];
    /** "View all" link. */
    href?: string;
    empty?: ReactNode;
    actions?: ReactNode;
    /** Header link buttons from the backend payload, shown before `actions`. */
    links?: ListWidgetLink[];
    /** Shown between the header and the rows (e.g. status counts). */
    summary?: ReactNode;
    className?: string;
};

const ISO_DATETIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function Initials({ name }: { name: string }) {
    const initials = name
        .replace(/\(.*?\)/g, '')
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

    return (
        <span
            aria-hidden="true"
            className="grid size-8 shrink-0 place-items-center rounded-full bg-accent text-xs font-semibold text-accent-foreground"
        >
            {initials}
        </span>
    );
}

/** Backend list items send ISO timestamps as `meta`; show them as relative times. */
function Meta({ value }: { value: ReactNode }) {
    if (typeof value === 'string' && ISO_DATETIME.test(value)) {
        return (
            <span className="text-xs text-muted-foreground">
                <DateTime value={value} relative />
            </span>
        );
    }

    if (typeof value === 'string' && ISO_DATE.test(value)) {
        return (
            <span className="text-xs text-muted-foreground">
                {new Date(`${value}T00:00:00`).toLocaleDateString('en-US', {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                })}
            </span>
        );
    }

    return value;
}

export function ListWidget({
    title,
    items,
    href,
    empty = 'Nothing here yet.',
    actions,
    links,
    summary,
    className,
}: ListWidgetProps) {
    return (
        <section
            data-slot="list-widget"
            className={cn(
                'flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            <header className="flex flex-wrap items-center justify-between gap-3 px-(--stat-p) pt-(--stat-p) pb-3">
                <h2 className="font-display text-base">{title}</h2>
                <div className="flex flex-wrap items-center justify-end gap-2">
                    {links?.map((link) => {
                        const Icon = link.icon ? navIcon(link.icon) : null;

                        return (
                            <Button
                                key={link.href}
                                asChild
                                variant="outline"
                                size="sm"
                            >
                                <Link href={link.href}>
                                    {Icon && <Icon aria-hidden="true" />}
                                    {link.label}
                                </Link>
                            </Button>
                        );
                    })}
                    {actions}
                    {href && (
                        <Link
                            href={href}
                            className="inline-flex items-center gap-1 rounded-sm text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            View all
                            <ArrowRight
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        </Link>
                    )}
                </div>
            </header>
            {summary && (
                <div className="border-t bg-muted/30 px-(--stat-p) py-2">
                    {summary}
                </div>
            )}
            <ListRows items={items} empty={empty} />
        </section>
    );
}

/** The rows of a list widget (also used inside tabbed dashboard cards). */
export function ListRows({
    items,
    empty = 'Nothing here yet.',
}: {
    items: ListWidgetItem[];
    empty?: ReactNode;
}) {
    return (
        <>
            {items.length === 0 ? (
                <div className="border-t px-(--stat-p) py-8 text-center text-sm text-muted-foreground">
                    {empty}
                </div>
            ) : (
                <ul className="divide-y border-t">
                    {items.map((item, index) => {
                        const content = (
                            <>
                                {item.leading ??
                                    (item.avatar ? (
                                        <Initials name={item.avatar} />
                                    ) : null)}
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">
                                        {item.title}
                                    </div>
                                    {item.subtitle && !item.date && (
                                        <div className="truncate text-xs text-muted-foreground">
                                            {item.subtitle}
                                        </div>
                                    )}
                                    {item.date && (
                                        // The date gets its own line on phones so truncation never hides it.
                                        <div className="text-xs text-muted-foreground sm:truncate">
                                            {item.subtitle && (
                                                <span className="block truncate sm:inline">
                                                    {item.subtitle}
                                                    <span className="hidden sm:inline">
                                                        {' · '}
                                                    </span>
                                                </span>
                                            )}
                                            {item.dateLabel &&
                                                `${item.dateLabel} `}
                                            <DateTime value={item.date} />
                                        </div>
                                    )}
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-1">
                                    {item.money ? (
                                        <div className="text-sm font-medium whitespace-nowrap tabular-nums">
                                            {formatMoney(
                                                item.money.amount,
                                                item.money.currency,
                                            )}
                                        </div>
                                    ) : (
                                        item.meta && (
                                            <div className="text-sm tabular-nums">
                                                <Meta value={item.meta} />
                                            </div>
                                        )
                                    )}
                                    {item.status && (
                                        <StatusBadge status={item.status} />
                                    )}
                                </div>
                            </>
                        );
                        const rowClass =
                            'flex items-center gap-3 px-(--stat-p) py-(--cell-py)';
                        const controls = Boolean(item.action || item.toggle);

                        return (
                            <li
                                key={index}
                                className={cn(
                                    controls && 'flex items-center',
                                    // Two controls wrap under the title on phones instead of squeezing it.
                                    item.toggle && 'flex-wrap sm:flex-nowrap',
                                )}
                            >
                                {item.href ? (
                                    <Link
                                        href={item.href}
                                        className={cn(
                                            rowClass,
                                            controls && 'min-w-0 flex-1',
                                            item.toggle && 'max-sm:basis-full',
                                            'transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none',
                                        )}
                                    >
                                        {content}
                                    </Link>
                                ) : (
                                    <div
                                        className={cn(
                                            rowClass,
                                            controls && 'min-w-0 flex-1',
                                            item.toggle && 'max-sm:basis-full',
                                        )}
                                    >
                                        {content}
                                    </div>
                                )}
                                {controls && (
                                    <div
                                        className={cn(
                                            'flex shrink-0 items-center gap-3 pr-(--stat-p)',
                                            item.toggle &&
                                                'max-sm:w-full max-sm:justify-end max-sm:pb-(--cell-py) max-sm:pl-(--stat-p)',
                                        )}
                                    >
                                        {item.toggle && (
                                            <RowToggle toggle={item.toggle} />
                                        )}
                                        {item.action && (
                                            <RowAction action={item.action} />
                                        )}
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </>
    );
}

function RowToggle({ toggle }: { toggle: ListWidgetToggle }) {
    const [processing, setProcessing] = useState(false);

    const change = (on: boolean) =>
        router.visit(toggle.href, {
            method: 'put',
            data: { [toggle.field]: on },
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            // The switch snaps back on its own; say why instead of failing silently.
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? 'The change was not saved.',
                ),
            onHttpException: () => {
                toast.error(
                    'The change was not saved. Reload the page and try again.',
                );

                return false;
            },
        });

    return (
        // The label widens the hit area to at least 24px around the small switch.
        <label className="inline-flex min-h-8 cursor-pointer items-center gap-2 rounded-md px-1 text-xs text-muted-foreground has-disabled:cursor-not-allowed">
            {toggle.text && <span aria-hidden="true">{toggle.text}</span>}
            <Switch
                checked={toggle.on}
                disabled={processing}
                onCheckedChange={change}
                aria-label={toggle.label}
            />
        </label>
    );
}

function RowAction({ action }: { action: ListWidgetAction }) {
    if (!action.method) {
        return (
            <Button asChild size="sm">
                <Link href={action.href}>{action.label}</Link>
            </Button>
        );
    }

    if (!action.confirm) {
        return (
            <Button
                size="sm"
                variant="outline"
                onClick={() =>
                    router.visit(action.href, {
                        method: action.method,
                        preserveScroll: true,
                    })
                }
            >
                {action.label}
            </Button>
        );
    }

    return (
        <ConfirmDialog
            trigger={
                <Button size="sm" variant="outline">
                    {action.label}
                </Button>
            }
            title={action.confirm.title}
            description={action.confirm.description}
            confirmLabel={action.confirm.confirmLabel}
            destructive={false}
            href={action.href}
            method={action.method}
            data={action.confirm.data}
        />
    );
}
