import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CalendarClock,
    CheckCircle2,
    Clock,
    CreditCard,
    Download,
    FileText,
    Globe,
    LifeBuoy,
    Mail,
    Receipt,
    RefreshCw,
    Server,
    ShoppingCart,
    UserRound,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import { Kbd } from '@/components/app/kbd';
import { ListWidget } from '@/components/app/list-widget';
import type { ListWidgetItem } from '@/components/app/list-widget';
import { Money } from '@/components/app/money';
import { NeedsAttention } from '@/components/app/needs-attention';
import type { TodoItem } from '@/components/app/needs-attention';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatCard } from '@/components/app/stat-card';
import { TONE_DOT } from '@/components/app/status-badge';
import type { StatusTone } from '@/components/app/status-badge';
import { TimeSeriesChart } from '@/components/app/time-series-chart';
import {
    convert,
    INCOME_LAST_YEAR_USD,
    INCOME_USD,
    KPI_SPARKS,
    LOCALES,
    MONTHS,
    ORDER_DAYS,
    ORDERS_DOMAINS,
    ORDERS_HOSTING,
    ORDERS_VPS,
    QUICK_ACTIONS,
} from '@/components/dev/preview-data';
import type { PreviewCurrency } from '@/components/dev/preview-data';
import { QuickCreate } from '@/components/quick-create';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

const CURRENCIES: PreviewCurrency[] = ['USD', 'INR', 'NPR'];

const TODOS: TodoItem[] = [
    {
        key: 'orders.pending',
        label: 'Pending orders',
        count: 6,
        href: '#orders',
        tone: 'warning',
        icon: 'ShoppingCart',
        hint: 'Oldest waiting 5 hours',
    },
    {
        key: 'support.awaiting',
        label: 'Tickets awaiting reply',
        count: 4,
        href: '#tickets',
        tone: 'warning',
        icon: 'LifeBuoy',
        hint: '2 breach first-response SLA within 1h',
    },
    {
        key: 'billing.overdue',
        label: 'Overdue invoices',
        count: 9,
        href: '/dev/invoice-preview',
        tone: 'danger',
        icon: 'Receipt',
        hint: '$3,120.40 at risk · 3 suspend tomorrow',
    },
    {
        key: 'provisioning.failed',
        label: 'Failed provisioning',
        count: 2,
        href: '#automation',
        tone: 'danger',
        icon: 'Server',
        hint: 'cPanel nyc-02: disk quota exceeded',
    },
    {
        key: 'domains.expiring',
        label: 'Domains expiring in 30 days',
        count: 5,
        href: '#domains',
        tone: 'info',
        icon: 'Globe',
        hint: '3 without auto-renew',
    },
    {
        key: 'services.cancellations',
        label: 'Cancellation requests',
        count: 1,
        href: '#cancellations',
        tone: 'neutral',
        icon: 'Inbox',
    },
];

function Initials({ name }: { name: string }) {
    const initials = name
        .split(' ')
        .map((part) => part[0])
        .slice(0, 2)
        .join('');

    return (
        <span
            aria-hidden="true"
            className="grid size-8 shrink-0 place-items-center rounded-full bg-accent text-xs font-semibold text-accent-foreground"
        >
            {initials}
        </span>
    );
}

const RECENT_ORDERS: (ListWidgetItem & { name: string })[] = [
    {
        name: 'Priya Sharma',
        title: 'Priya Sharma',
        subtitle: 'ORD-20931 · Business Hosting, annual',
        meta: <Money amount={1438800} currency="INR" locale="en-IN" />,
        status: 'pending',
    },
    {
        name: 'Rahul Thapa',
        title: 'Rahul Thapa',
        subtitle: 'ORD-20930 · VPS 4 GB + thapa.com.np',
        meta: <Money amount={1862500} currency="NPR" locale="en-IN" />,
        status: 'active',
    },
    {
        name: 'Northwind Traders',
        title: 'Northwind Traders',
        subtitle: 'ORD-20929 · Reseller Pro, monthly',
        meta: <Money amount={4900} currency="USD" />,
        status: 'active',
    },
    {
        name: 'Ankit Verma',
        title: 'Ankit Verma',
        subtitle: 'ORD-20928 · verma.dev transfer',
        meta: <Money amount={89900} currency="INR" locale="en-IN" />,
        status: 'fraud',
    },
    {
        name: 'Globex GmbH',
        title: 'Globex GmbH',
        subtitle: 'ORD-20927 · Dedicated E-2386G',
        meta: <Money amount={18900} currency="USD" />,
        status: 'active',
    },
];

const RECENT_TICKETS: (ListWidgetItem & { name: string })[] = [
    {
        name: 'Sita Gurung',
        title: 'Website down after DNS change',
        subtitle: 'TKT-4412 · Sita Gurung · Technical · 12 min ago',
        status: 'customer-reply',
    },
    {
        name: 'Acme Hosting',
        title: 'Invoice shows wrong GSTIN',
        subtitle: 'TKT-4411 · Acme Hosting · Billing · 38 min ago',
        status: 'open',
    },
    {
        name: 'Vikram Rao',
        title: 'Need SSH access on shared plan',
        subtitle: 'TKT-4409 · Vikram Rao · Sales · 2 h ago',
        status: 'in-progress',
    },
    {
        name: 'Maya Shrestha',
        title: 'Email bouncing for info@ mailbox',
        subtitle: 'TKT-4405 · Maya Shrestha · Technical · 5 h ago',
        status: 'answered',
    },
    {
        name: 'Initech LLC',
        title: 'Upgrade to VPS 8 GB',
        subtitle: 'TKT-4401 · Initech LLC · Sales · yesterday',
        status: 'on-hold',
    },
];

type HealthCheck = {
    label: string;
    detail: string;
    value: string;
    tone: StatusTone;
    icon: LucideIcon;
};

const HEALTH: HealthCheck[] = [
    {
        label: 'Cron & scheduler',
        detail: 'Last run 2 min ago',
        value: 'OK',
        tone: 'success',
        icon: Clock,
    },
    {
        label: 'Provisioning',
        detail: '146 of 148 succeeded (7 days)',
        value: '98.6%',
        tone: 'warning',
        icon: Server,
    },
    {
        label: 'Payment gateways',
        detail: 'Stripe · Razorpay · Khalti webhooks',
        value: '3 / 3',
        tone: 'success',
        icon: CreditCard,
    },
    {
        label: 'Domain sync',
        detail: 'Registrar sync 3 h ago',
        value: 'OK',
        tone: 'success',
        icon: RefreshCw,
    },
    {
        label: 'Email delivery',
        detail: '1,284 sent today · 9 bounced',
        value: '99.3%',
        tone: 'success',
        icon: Mail,
    },
    {
        label: 'Queue',
        detail: '12 waiting · 1 failed job',
        value: '1 failed',
        tone: 'danger',
        icon: AlertTriangle,
    },
];

const QUICK_ICONS: Record<string, LucideIcon> = {
    UserRound,
    ShoppingCart,
    FileText,
    LifeBuoy,
    Globe,
};

function Panel({
    title,
    description,
    actions,
    children,
    className,
    id,
}: {
    title: string;
    description?: React.ReactNode;
    actions?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
    id?: string;
}) {
    return (
        <section
            id={id}
            className={cn(
                'flex min-w-0 flex-col rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            <header className="flex flex-wrap items-start justify-between gap-3 px-(--stat-p) pt-(--stat-p)">
                <div className="min-w-0 space-y-0.5">
                    <h2 className="font-display text-base">{title}</h2>
                    {description && (
                        <div className="text-sm text-muted-foreground">
                            {description}
                        </div>
                    )}
                </div>
                {actions}
            </header>
            <div className="flex-1 px-(--stat-p) pt-4 pb-(--stat-p)">
                {children}
            </div>
        </section>
    );
}

const SHORTCUTS = ['C', 'O', 'I', 'T', 'D'];

function QuickActions() {
    return (
        <section className="flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card lg:col-span-2 xl:col-span-1">
            <header className="flex items-center justify-between gap-3 px-(--stat-p) pt-(--stat-p) pb-3">
                <h2 className="font-display text-base">Quick actions</h2>
                <Kbd>⌘K</Kbd>
            </header>
            <ul className="grid flex-1 border-t p-1.5 sm:grid-cols-2 xl:grid-cols-1">
                {QUICK_ACTIONS.map((action, index) => {
                    const Icon = QUICK_ICONS[action.icon] ?? FileText;

                    return (
                        <li key={action.title}>
                            <Link
                                href={action.href}
                                className="group flex items-center gap-3 rounded-md px-2.5 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <span className="grid size-7 place-items-center rounded-md border bg-background text-muted-foreground group-hover:text-accent-foreground">
                                    <Icon
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span className="flex-1">{action.title}</span>
                                <Kbd className="opacity-70 group-hover:opacity-100">
                                    N {SHORTCUTS[index]}
                                </Kbd>
                            </Link>
                        </li>
                    );
                })}
                <li>
                    <Link
                        href="/dev/invoice-preview"
                        className="group flex items-center gap-3 rounded-md px-2.5 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <span className="grid size-7 place-items-center rounded-md border border-dashed text-muted-foreground">
                            <CheckCircle2
                                className="size-3.5"
                                aria-hidden="true"
                            />
                        </span>
                        <span className="flex-1">Run billing now</span>
                    </Link>
                </li>
            </ul>
        </section>
    );
}

export default function DashboardPreview() {
    const [currency, setCurrency] = useState<PreviewCurrency>('USD');
    const locale = LOCALES[currency];
    const money = (usdMinor: number) =>
        formatMoney(convert(usdMinor, currency), currency, locale);

    const income = INCOME_USD.map((value) => convert(value, currency));
    const lastYear = INCOME_LAST_YEAR_USD.map((value) =>
        convert(value, currency),
    );
    const yearTotal = income.reduce((sum, value) => sum + value, 0);

    const byCurrency = [
        { code: 'USD', label: 'US dollar', share: 46, amount: 1699200 },
        { code: 'INR', label: 'Indian rupee', share: 38, amount: 116720000 },
        { code: 'NPR', label: 'Nepalese rupee', share: 16, amount: 78680000 },
    ];

    return (
        <>
            <Head title="Dashboard preview" />
            <Page>
                <PageHeader
                    eyebrow="Saturday, 17 October 2026"
                    title="Good afternoon, Admin"
                    description="Here's what needs you today and how the business is trending."
                    actions={
                        <>
                            <ToggleGroup
                                type="single"
                                variant="outline"
                                size="sm"
                                value={currency}
                                onValueChange={(value) =>
                                    value &&
                                    setCurrency(value as PreviewCurrency)
                                }
                                aria-label="Reporting currency"
                            >
                                {CURRENCIES.map((code) => (
                                    <ToggleGroupItem
                                        key={code}
                                        value={code}
                                        className="px-3 text-xs"
                                    >
                                        {code}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                            <Button
                                variant="outline"
                                size="sm"
                                className="hidden sm:inline-flex"
                            >
                                <Download />
                                Export
                            </Button>
                            <QuickCreate actions={QUICK_ACTIONS} />
                        </>
                    }
                />

                <div className="grid gap-(--page-gap) lg:grid-cols-3">
                    <div className="order-2 grid grid-cols-2 gap-(--page-gap) lg:order-1 lg:col-span-3 xl:grid-cols-4">
                        <StatCard
                            label="Monthly recurring revenue"
                            value={money(4821000)}
                            trend={{ value: '4.2%', direction: 'up' }}
                            hint="vs. last month"
                            icon={Wallet}
                            sparkline={KPI_SPARKS.mrr}
                            href="#mrr"
                        />
                        <StatCard
                            label="Income this month"
                            value={money(3694055)}
                            trend={{ value: '12.1%', direction: 'up' }}
                            hint="Oct 1–17 vs. Sep 1–17"
                            icon={ArrowUpRight}
                            sparkline={KPI_SPARKS.income}
                            href="#income"
                        />
                        <StatCard
                            label="Outstanding"
                            value={money(1248000)}
                            trend={{
                                value: '2.3%',
                                direction: 'down',
                                upIsGood: false,
                            }}
                            hint="38 unpaid invoices"
                            icon={Receipt}
                            sparkline={KPI_SPARKS.outstanding}
                            href="/dev/invoice-preview"
                        />
                        <StatCard
                            label="Overdue"
                            value={money(312040)}
                            trend={{
                                value: '+3',
                                direction: 'up',
                                upIsGood: false,
                            }}
                            hint="9 invoices, oldest 41 days"
                            icon={AlertTriangle}
                            tone="danger"
                            sparkline={KPI_SPARKS.overdue}
                            href="/dev/invoice-preview"
                        />
                    </div>

                    <Panel
                        className="order-3 lg:order-2 lg:col-span-2"
                        title="Income"
                        description={
                            <span className="flex flex-wrap items-baseline gap-x-2">
                                <span className="font-numeric text-2xl font-semibold text-foreground">
                                    {formatMoney(yearTotal, currency, locale)}
                                </span>
                                <span>last 12 months</span>
                                <span className="rounded-badge bg-success-subtle px-1.5 py-0.5 text-xs font-medium text-success-text">
                                    +18.4% year over year
                                </span>
                            </span>
                        }
                        actions={
                            <span className="text-xs text-muted-foreground">
                                Reporting in {currency}
                            </span>
                        }
                    >
                        <TimeSeriesChart
                            label={`Income per month in ${currency}, last 12 months, compared with the previous year`}
                            currency={currency}
                            locale={locale}
                            className="h-72"
                            series={[
                                {
                                    name: 'This year',
                                    points: MONTHS.map((x, index) => ({
                                        x,
                                        y: income[index],
                                    })),
                                },
                                {
                                    name: 'Previous year',
                                    points: MONTHS.map((x, index) => ({
                                        x,
                                        y: lastYear[index],
                                    })),
                                },
                            ]}
                        />
                    </Panel>

                    <NeedsAttention
                        items={TODOS}
                        className="order-1 lg:order-3"
                    />
                </div>

                <div className="grid gap-(--page-gap) md:grid-cols-2 xl:grid-cols-3">
                    <Panel
                        title="New orders"
                        description="Last 14 days · 287 orders"
                    >
                        <TimeSeriesChart
                            kind="bar"
                            stacked
                            label="New orders per day for the last 14 days, by product type"
                            className="h-64"
                            formatX={(x) => String(Number(x.slice(8, 10)))}
                            series={[
                                {
                                    name: 'Hosting',
                                    points: ORDER_DAYS.map((x, index) => ({
                                        x,
                                        y: ORDERS_HOSTING[index],
                                    })),
                                },
                                {
                                    name: 'Domains',
                                    points: ORDER_DAYS.map((x, index) => ({
                                        x,
                                        y: ORDERS_DOMAINS[index],
                                    })),
                                },
                                {
                                    name: 'VPS',
                                    points: ORDER_DAYS.map((x, index) => ({
                                        x,
                                        y: ORDERS_VPS[index],
                                    })),
                                },
                            ]}
                        />
                    </Panel>

                    <Panel
                        title="Income by currency"
                        description="This month, settled in original currency"
                    >
                        <ul className="space-y-4">
                            {byCurrency.map((row, index) => (
                                <li key={row.code} className="space-y-1.5">
                                    <div className="flex items-baseline justify-between gap-3 text-sm">
                                        <span className="flex items-center gap-2">
                                            <span className="rounded-sm border px-1.5 py-px font-mono text-[0.6875rem] font-medium">
                                                {row.code}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {row.label}
                                            </span>
                                        </span>
                                        <Money
                                            amount={row.amount}
                                            currency={row.code}
                                            locale={
                                                row.code === 'USD'
                                                    ? 'en-US'
                                                    : 'en-IN'
                                            }
                                            className="font-medium"
                                        />
                                    </div>
                                    <div
                                        className="h-2 overflow-hidden rounded-full bg-muted"
                                        role="meter"
                                        aria-valuenow={row.share}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        aria-label={`${row.code} share of income`}
                                    >
                                        <div
                                            className="h-full rounded-full"
                                            style={{
                                                width: `${row.share}%`,
                                                background: `var(--chart-${index + 1})`,
                                            }}
                                        />
                                    </div>
                                    <div className="text-xs text-muted-foreground tabular-nums">
                                        {row.share}% of income
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-5 flex items-center gap-2 border-t pt-4 text-xs text-muted-foreground">
                            <CalendarClock
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            GST (India) and VAT (Nepal) collected:{' '}
                            {money(412300)}
                        </p>
                    </Panel>

                    <Panel
                        id="automation"
                        title="Automation health"
                        description="Background jobs, modules and gateways"
                        className="md:col-span-2 xl:col-span-1"
                        actions={
                            <span className="inline-flex items-center gap-1.5 rounded-badge bg-warning-subtle px-2 py-0.5 text-xs font-medium text-warning-text">
                                <span
                                    aria-hidden="true"
                                    className="size-1.5 rounded-full bg-warning"
                                />
                                2 issues
                            </span>
                        }
                    >
                        <ul className="-my-1 divide-y">
                            {HEALTH.map((check) => (
                                <li
                                    key={check.label}
                                    className="flex items-center gap-3 py-2"
                                >
                                    <check.icon
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-medium">
                                            {check.label}
                                        </div>
                                        <div className="truncate text-xs text-muted-foreground">
                                            {check.detail}
                                        </div>
                                    </div>
                                    <span className="flex items-center gap-1.5 text-xs font-medium tabular-nums">
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'size-2 rounded-full',
                                                TONE_DOT[check.tone],
                                            )}
                                        />
                                        {check.value}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                </div>

                <div className="grid gap-(--page-gap) lg:grid-cols-2 xl:grid-cols-[1fr_1fr_17rem]">
                    <ListWidget
                        title="Recent orders"
                        href="#orders"
                        items={RECENT_ORDERS.map((order) => ({
                            ...order,
                            href: '#orders',
                            leading: <Initials name={order.name} />,
                        }))}
                    />
                    <ListWidget
                        title="Recent tickets"
                        href="#tickets"
                        items={RECENT_TICKETS.map((ticket) => ({
                            ...ticket,
                            href: '#tickets',
                            leading: <Initials name={ticket.name} />,
                        }))}
                    />
                    <QuickActions />
                </div>
            </Page>
        </>
    );
}

DashboardPreview.layout = {
    area: 'admin',
    breadcrumbs: [{ title: 'Dashboard', href: '/dev/dashboard-preview' }],
};
