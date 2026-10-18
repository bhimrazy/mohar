import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import {
    ArrowRight,
    BookOpen,
    CreditCard,
    ExternalLink,
    Globe,
    HardDrive,
    LifeBuoy,
    Mail,
    MessageSquare,
    Plus,
    Server,
    ShieldCheck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

type Service = {
    name: string;
    domain: string;
    icon: LucideIcon;
    status: string;
    price: number;
    cycle: string;
    nextDue: string;
    usage?: { label: string; used: string; percent: number }[];
    note?: string;
    action: string;
};

const SERVICES: Service[] = [
    {
        name: 'Business Hosting',
        domain: 'sharmadesigns.in',
        icon: Server,
        status: 'active',
        price: 299900,
        cycle: 'year',
        nextDue: '2026-11-01',
        usage: [
            { label: 'Disk', used: '31/50 GB', percent: 62 },
            { label: 'Bandwidth', used: '190/500 GB', percent: 38 },
        ],
        action: 'Log in to cPanel',
    },
    {
        name: 'VPS 4 GB — Mumbai',
        domain: 'app.sharmadesigns.in',
        icon: HardDrive,
        status: 'active',
        price: 149900,
        cycle: 'month',
        nextDue: '2026-11-04',
        usage: [
            { label: 'CPU', used: '22% avg', percent: 22 },
            { label: 'Memory', used: '3.1 of 4 GB', percent: 78 },
        ],
        action: 'Open console',
    },
    {
        name: 'Professional Email',
        domain: '2 mailboxes · sharmadesigns.in',
        icon: Mail,
        status: 'active',
        price: 9900,
        cycle: 'month',
        nextDue: '2026-11-01',
        usage: [
            { label: 'Mailboxes', used: '2 of 5', percent: 40 },
            { label: 'Storage', used: '6.2/20 GB', percent: 31 },
        ],
        action: 'Open webmail',
    },
    {
        name: 'SSL — Wildcard',
        domain: '*.sharmadesigns.in',
        icon: ShieldCheck,
        status: 'pending',
        price: 459900,
        cycle: 'year',
        nextDue: '2026-10-24',
        note: 'Add the DNS TXT record we emailed you to finish issuing the certificate.',
        action: 'Validate',
    },
];

const DOMAINS = [
    {
        name: 'sharmadesigns.in',
        expires: '2026-11-02',
        note: 'Renews in 16 days',
        autoRenew: true,
        status: 'active',
    },
    {
        name: 'priyasharma.com',
        expires: '2027-03-18',
        note: 'Renews in 5 months',
        autoRenew: true,
        status: 'active',
    },
    {
        name: 'sharma.studio',
        expires: '2026-10-29',
        note: 'Expires in 12 days',
        autoRenew: false,
        status: 'grace',
    },
];

function UsageBar({ percent }: { percent: number }) {
    return (
        <div
            className="h-1.5 overflow-hidden rounded-full bg-muted"
            role="meter"
            aria-valuenow={percent}
            aria-valuemin={0}
            aria-valuemax={100}
        >
            <div
                className={cn(
                    'h-full rounded-full',
                    percent >= 75 ? 'bg-warning' : 'bg-primary',
                )}
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}

export default function ClientPreview() {
    return (
        <>
            <Head title="Client area preview" />
            <Page>
                <PageHeader
                    eyebrow="Sharma Designs · Client #1042"
                    title="Welcome back, Priya"
                    description="Everything for your websites, domains and billing in one place."
                    actions={
                        <>
                            <Button variant="outline" size="sm">
                                <LifeBuoy />
                                Get help
                            </Button>
                            <Button size="sm">
                                <Plus />
                                Order new service
                            </Button>
                        </>
                    }
                />

                <div className="flex flex-col gap-4 rounded-xl border border-warning/30 bg-warning-subtle p-(--stat-p) sm:flex-row sm:items-center">
                    <span className="grid size-10 shrink-0 place-items-center rounded-full bg-card text-warning-text elevation-card">
                        <CreditCard className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="font-medium">
                            Invoice INV-2026-1042 for{' '}
                            <Money
                                amount={471528}
                                currency="INR"
                                locale="en-IN"
                            />{' '}
                            is due on <DateTime value="2026-10-24" />
                        </div>
                        <div className="text-sm text-muted-foreground">
                            Pay now to keep Business Hosting and
                            sharmadesigns.in renewing without interruption.
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" className="bg-card">
                            View invoice
                        </Button>
                        <Button size="sm">Pay ₹4,715.28</Button>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-(--page-gap) xl:grid-cols-4">
                    <StatCard
                        label="Active services"
                        value="3"
                        hint="1 pending setup"
                        icon={Server}
                        href="#services"
                    />
                    <StatCard
                        label="Domains"
                        value="3"
                        hint="1 expiring soon"
                        icon={Globe}
                        href="#domains"
                    />
                    <StatCard
                        label="Amount due"
                        value={
                            <Money
                                amount={471528}
                                currency="INR"
                                locale="en-IN"
                            />
                        }
                        hint="1 unpaid invoice"
                        icon={CreditCard}
                        tone="warning"
                        href="/dev/invoice-preview"
                    />
                    <StatCard
                        label="Open tickets"
                        value="1"
                        hint="Awaiting your reply"
                        icon={MessageSquare}
                        href="#tickets"
                    />
                </div>

                <div className="grid gap-(--page-gap) lg:grid-cols-3">
                    <section
                        id="services"
                        className="flex flex-col gap-3 lg:col-span-2"
                    >
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-lg">
                                Your services
                            </h2>
                            <Link
                                href="#services"
                                className="inline-flex items-center gap-1 text-sm font-medium text-muted-foreground hover:text-foreground"
                            >
                                All services
                                <ArrowRight
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                            </Link>
                        </div>
                        <div className="grid gap-(--page-gap) md:grid-cols-2">
                            {SERVICES.map((service) => (
                                <article
                                    key={service.name}
                                    className="flex flex-col gap-4 rounded-xl border bg-card p-(--stat-p) elevation-card"
                                >
                                    <div className="flex items-start gap-3">
                                        <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-accent text-accent-foreground">
                                            <service.icon
                                                className="size-5"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <h3 className="truncate font-medium">
                                                {service.name}
                                            </h3>
                                            <p className="truncate text-sm text-muted-foreground">
                                                {service.domain}
                                            </p>
                                        </div>
                                        <StatusBadge
                                            status={service.status}
                                            label={
                                                service.status === 'pending'
                                                    ? 'Setting up'
                                                    : undefined
                                            }
                                        />
                                    </div>

                                    {service.note && (
                                        <p className="rounded-lg bg-muted/60 px-3 py-2 text-sm text-muted-foreground">
                                            {service.note}
                                        </p>
                                    )}

                                    {service.usage && (
                                        <div className="grid grid-cols-2 gap-4">
                                            {service.usage.map((meter) => (
                                                <div
                                                    key={meter.label}
                                                    className="space-y-1.5"
                                                >
                                                    <div className="flex justify-between gap-2 text-xs">
                                                        <span className="text-muted-foreground">
                                                            {meter.label}
                                                        </span>
                                                        <span className="tabular-nums">
                                                            {meter.used}
                                                        </span>
                                                    </div>
                                                    <UsageBar
                                                        percent={meter.percent}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    <div className="mt-auto flex items-center justify-between gap-3 border-t pt-3 text-sm">
                                        <div className="min-w-0">
                                            <div>
                                                <Money
                                                    amount={service.price}
                                                    currency="INR"
                                                    locale="en-IN"
                                                    className="font-medium"
                                                />
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    / {service.cycle}
                                                </span>
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Renews{' '}
                                                <DateTime
                                                    value={service.nextDue}
                                                />
                                            </div>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="-mr-2 shrink-0"
                                        >
                                            {service.action}
                                            <ExternalLink
                                                className="size-3.5"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>

                    <div className="flex flex-col gap-(--page-gap)">
                        <SectionCard
                            title="Invoices"
                            flush
                            actions={
                                <Link
                                    href="/dev/invoice-preview"
                                    className="text-sm font-medium text-muted-foreground hover:text-foreground"
                                >
                                    View all
                                </Link>
                            }
                        >
                            <ul className="divide-y">
                                {[
                                    {
                                        number: 'INV-2026-1042',
                                        date: '2026-10-24',
                                        amount: 471528,
                                        status: 'unpaid',
                                    },
                                    {
                                        number: 'INV-2026-0987',
                                        date: '2026-10-04',
                                        amount: 176882,
                                        status: 'paid',
                                    },
                                    {
                                        number: 'INV-2026-0911',
                                        date: '2026-09-04',
                                        amount: 176882,
                                        status: 'paid',
                                    },
                                ].map((invoice) => (
                                    <li
                                        key={invoice.number}
                                        className="flex items-center justify-between gap-3 px-4 py-(--cell-py) sm:px-(--card-p)"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-medium">
                                                {invoice.number}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {invoice.status === 'paid'
                                                    ? 'Paid '
                                                    : 'Due '}
                                                <DateTime
                                                    value={invoice.date}
                                                />
                                            </div>
                                        </div>
                                        <div className="flex flex-col items-end gap-1">
                                            <Money
                                                amount={invoice.amount}
                                                currency="INR"
                                                locale="en-IN"
                                                className="text-sm font-medium"
                                            />
                                            <StatusBadge
                                                status={invoice.status}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </SectionCard>

                        <SectionCard title="Support" flush>
                            <div className="px-4 py-(--cell-py) sm:px-(--card-p)">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-medium">
                                            Email bouncing for info@ mailbox
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            TKT-4405 · Replied by Anil (support)
                                            5 h ago
                                        </div>
                                    </div>
                                    <StatusBadge
                                        status="answered"
                                        label="Awaiting you"
                                        tone="warning"
                                    />
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-2 border-t p-4 sm:px-(--card-p)">
                                <Button variant="outline" size="sm">
                                    <MessageSquare />
                                    New ticket
                                </Button>
                                <Button variant="outline" size="sm">
                                    <BookOpen />
                                    Help articles
                                </Button>
                            </div>
                        </SectionCard>
                    </div>
                </div>

                <SectionCard
                    title="Domains"
                    description="Renewals are charged to your saved UPI AutoPay mandate."
                    flush
                    actions={
                        <Button variant="outline" size="sm">
                            <Plus />
                            Register domain
                        </Button>
                    }
                >
                    <ul id="domains" className="divide-y">
                        {DOMAINS.map((domain) => (
                            <li
                                key={domain.name}
                                className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-(--cell-py) sm:px-(--card-p)"
                            >
                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                    <Globe
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span className="truncate font-medium">
                                        {domain.name}
                                    </span>
                                    <StatusBadge
                                        status={domain.status}
                                        label={
                                            domain.status === 'grace'
                                                ? 'Expiring'
                                                : undefined
                                        }
                                    />
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    <DateTime value={domain.expires} /> ·{' '}
                                    {domain.note}
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <Switch
                                        defaultChecked={domain.autoRenew}
                                        aria-label={`Auto-renew ${domain.name}`}
                                    />
                                    Auto-renew
                                </label>
                                {!domain.autoRenew && (
                                    <Button size="sm">Renew now</Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </Page>
        </>
    );
}

ClientPreview.layout = {
    area: 'client',
    breadcrumbs: [{ title: 'Home', href: '/dev/client-preview' }],
};
