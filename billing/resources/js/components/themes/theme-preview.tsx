import {
    AlertTriangle,
    LayoutGrid,
    LifeBuoy,
    Receipt,
    Search,
    Server,
    Users,
    Wallet,
} from 'lucide-react';
import type { CSSProperties } from 'react';
import { Money } from '@/components/app/money';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { TimeSeriesChart } from '@/components/app/time-series-chart';
import { Button } from '@/components/ui/button';
import { tokensToCssVars } from '@/lib/theme-tokens';
import type {
    ThemeMode,
    ThemeSchemaDescription,
    ThemeTokens,
} from '@/lib/theme-tokens';
import { cn } from '@/lib/utils';

const MONTHS = [
    '2026-05-01',
    '2026-06-01',
    '2026-07-01',
    '2026-08-01',
    '2026-09-01',
    '2026-10-01',
];
const INCOME = [3380125, 3562090, 3719800, 3611340, 3890215, 3694055];
const LAST_YEAR = [2802300, 2911780, 2988800, 3012400, 3150770, 3204100];

const ROWS = [
    {
        number: 'INV-1042',
        client: 'Priya Sharma',
        status: 'unpaid',
        total: 471528,
        currency: 'INR',
    },
    {
        number: 'INV-1041',
        client: 'Northwind Traders',
        status: 'paid',
        total: 249900,
        currency: 'USD',
    },
    {
        number: 'INV-1040',
        client: 'Rahul Thapa',
        status: 'overdue',
        total: 1862500,
        currency: 'NPR',
    },
    {
        number: 'INV-1039',
        client: 'Globex GmbH',
        status: 'draft',
        total: 18900,
        currency: 'USD',
    },
];

const NAV = [
    { label: 'Dashboard', icon: LayoutGrid, active: true },
    { label: 'Clients', icon: Users },
    { label: 'Invoices', icon: Receipt, badge: 9 },
    { label: 'Services', icon: Server },
    { label: 'Tickets', icon: LifeBuoy, badge: 4 },
];

type ThemePreviewProps = {
    tokens: ThemeTokens;
    mode: ThemeMode;
    schema: ThemeSchemaDescription;
    className?: string;
};

/**
 * A miniature admin screen (sidebar, KPIs, chart, table, badges, buttons) rendered with the
 * given tokens only — the editor's live preview. Works because app.css uses `@theme inline`,
 * so utilities read the raw variables set on this wrapper.
 */
export function ThemePreview({
    tokens,
    mode,
    schema,
    className,
}: ThemePreviewProps) {
    const style = {
        ...tokensToCssVars(tokens, mode, schema),
        fontFamily: 'var(--app-font-sans)',
        fontFeatureSettings: 'var(--font-features)',
        colorScheme: mode,
    } as CSSProperties;

    return (
        <div
            data-slot="theme-preview"
            aria-label={`${mode === 'dark' ? 'Dark' : 'Light'} mode preview`}
            role="img"
            className={cn(
                'flex overflow-hidden rounded-xl border bg-sidebar text-foreground',
                mode === 'dark' && 'dark',
                className,
            )}
            style={style}
        >
            <aside className="hidden w-40 shrink-0 flex-col gap-1 p-2 text-sidebar-foreground sm:flex">
                <div className="mb-2 flex items-center gap-2 px-1.5 pt-1">
                    <span className="grid size-6 place-items-center rounded-md bg-sidebar-primary text-[0.625rem] font-bold text-sidebar-primary-foreground">
                        B
                    </span>
                    <span className="truncate text-xs font-semibold">
                        Billing
                    </span>
                </div>
                <div className="mb-1 flex items-center gap-1.5 rounded-md border border-sidebar-border px-2 py-1 text-[0.6875rem] opacity-80">
                    <Search className="size-3" aria-hidden="true" />
                    Search…
                </div>
                <div className="px-1.5 pt-1 eyebrow text-[0.625rem] text-sidebar-foreground/70">
                    General
                </div>
                {NAV.map((item) => (
                    <div
                        key={item.label}
                        className={cn(
                            'flex h-7 items-center gap-2 rounded-md px-1.5 text-xs',
                            item.active &&
                                'bg-sidebar-accent font-medium text-sidebar-accent-foreground',
                        )}
                    >
                        <item.icon className="size-3.5" aria-hidden="true" />
                        <span className="flex-1 truncate">{item.label}</span>
                        {item.badge && (
                            <span className="rounded-badge bg-sidebar-accent px-1 text-[0.625rem] text-sidebar-accent-foreground ring-1 ring-sidebar-border">
                                {item.badge}
                            </span>
                        )}
                    </div>
                ))}
            </aside>

            <div
                className="m-(--shell-gap) ml-0 flex min-w-0 flex-1 flex-col gap-3 rounded-(--shell-radius) bg-background p-3 sm:ml-0"
                style={{
                    boxShadow: 'var(--shell-shadow)',
                    outline: '1px solid var(--shell-border)',
                }}
            >
                <div className="flex items-center justify-between gap-2">
                    <div>
                        <div className="text-[0.6875rem] text-muted-foreground">
                            Saturday, 17 October
                        </div>
                        <div className="font-display text-base">
                            Good afternoon, Admin
                        </div>
                    </div>
                    <div className="flex gap-1.5">
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-7 text-xs"
                        >
                            Export
                        </Button>
                        <Button size="sm" className="h-7 text-xs">
                            New invoice
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-2 lg:grid-cols-3">
                    <StatCard
                        label="MRR"
                        value="$48,210"
                        icon={Wallet}
                        trend={{ value: '4.2%', direction: 'up' }}
                        className="[&_[data-slot=chart]]:h-6"
                    />
                    <StatCard
                        label="Overdue"
                        value="$3,120"
                        icon={AlertTriangle}
                        tone="danger"
                        trend={{
                            value: '+3',
                            direction: 'up',
                            upIsGood: false,
                        }}
                    />
                    <StatCard
                        label="Outstanding"
                        value="$12,480"
                        icon={Receipt}
                        trend={{
                            value: '2.3%',
                            direction: 'down',
                            upIsGood: false,
                        }}
                        className="hidden lg:flex"
                    />
                </div>

                <div className="rounded-xl border bg-card p-(--stat-p) text-card-foreground elevation-card">
                    <div className="mb-1 flex items-baseline justify-between">
                        <span className="font-display text-sm">Income</span>
                        <span className="text-[0.6875rem] text-muted-foreground">
                            Last 6 months
                        </span>
                    </div>
                    <TimeSeriesChart
                        label="Sample income chart"
                        currency="USD"
                        className="h-36"
                        series={[
                            {
                                name: 'This year',
                                points: MONTHS.map((x, i) => ({
                                    x,
                                    y: INCOME[i],
                                })),
                            },
                            {
                                name: 'Previous year',
                                points: MONTHS.map((x, i) => ({
                                    x,
                                    y: LAST_YEAR[i],
                                })),
                            },
                        ]}
                    />
                </div>

                <div className="overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card">
                    <table className="w-full text-xs">
                        <thead className="bg-muted/40 text-muted-foreground">
                            <tr>
                                <th className="h-(--table-head-h) px-3 text-left eyebrow">
                                    Invoice
                                </th>
                                <th className="px-3 text-left eyebrow">
                                    Client
                                </th>
                                <th className="px-3 text-left eyebrow">
                                    Status
                                </th>
                                <th className="px-3 text-right eyebrow">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {ROWS.map((row) => (
                                <tr key={row.number}>
                                    <td className="px-3 py-(--cell-py) font-mono">
                                        {row.number}
                                    </td>
                                    <td className="px-3">{row.client}</td>
                                    <td className="px-3">
                                        <StatusBadge status={row.status} />
                                    </td>
                                    <td className="px-3 text-right font-medium">
                                        <Money
                                            amount={row.total}
                                            currency={row.currency}
                                            locale={
                                                row.currency === 'USD'
                                                    ? 'en-US'
                                                    : 'en-IN'
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusBadge status="active" />
                    <StatusBadge status="pending" />
                    <StatusBadge status="overdue" />
                    <StatusBadge status="refunded" />
                    <StatusBadge status="cancelled" />
                    <span className="ml-auto text-xs text-muted-foreground">
                        Muted text · <span className="text-primary">Link</span>
                    </span>
                </div>
            </div>
        </div>
    );
}
