import { Head } from '@/components/app/head';
import {
    Ban,
    Bell,
    CheckCircle2,
    Copy,
    Download,
    FileText,
    Mail,
    MoreHorizontal,
    Plus,
    Search,
    Send,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';

type Invoice = {
    id: number;
    number: string;
    client: string;
    email: string;
    status: string;
    total: number;
    currency: 'USD' | 'INR' | 'NPR';
    issued: string;
    due: string;
    dueNote: string;
};

const LOCALE = { USD: 'en-US', INR: 'en-IN', NPR: 'en-IN' } as const;

const INVOICES: Invoice[] = [
    {
        id: 1,
        number: 'INV-2026-1042',
        client: 'Priya Sharma',
        email: 'priya@sharmadesigns.in',
        status: 'unpaid',
        total: 471528,
        currency: 'INR',
        issued: '2026-10-10',
        due: '2026-10-24',
        dueNote: 'in 7 days',
    },
    {
        id: 2,
        number: 'INV-2026-1041',
        client: 'Northwind Traders',
        email: 'ap@northwind.com',
        status: 'paid',
        total: 249900,
        currency: 'USD',
        issued: '2026-10-08',
        due: '2026-10-22',
        dueNote: 'Paid Oct 9',
    },
    {
        id: 3,
        number: 'INV-2026-1040',
        client: 'Rahul Thapa',
        email: 'rahul@thapa.com.np',
        status: 'overdue',
        total: 1862500,
        currency: 'NPR',
        issued: '2026-09-20',
        due: '2026-10-04',
        dueNote: '13 days overdue',
    },
    {
        id: 4,
        number: 'INV-2026-1039',
        client: 'Globex GmbH',
        email: 'billing@globex.de',
        status: 'partially-paid',
        total: 18900,
        currency: 'USD',
        issued: '2026-10-06',
        due: '2026-10-20',
        dueNote: 'in 3 days',
    },
    {
        id: 5,
        number: 'INV-2026-1038',
        client: 'Acme Hosting Ltd',
        email: 'finance@acme.io',
        status: 'overdue',
        total: 12900,
        currency: 'USD',
        issued: '2026-09-05',
        due: '2026-09-19',
        dueNote: '28 days overdue',
    },
    {
        id: 6,
        number: 'INV-2026-1037',
        client: 'Maya Shrestha',
        email: 'maya@example.com',
        status: 'paid',
        total: 1596000,
        currency: 'NPR',
        issued: '2026-10-02',
        due: '2026-10-16',
        dueNote: 'Paid Oct 3',
    },
    {
        id: 7,
        number: 'INV-2026-1036',
        client: 'Vikram Rao',
        email: 'vikram@raolabs.in',
        status: 'draft',
        total: 1179800,
        currency: 'INR',
        issued: '2026-10-17',
        due: '2026-10-31',
        dueNote: 'Not sent',
    },
    {
        id: 8,
        number: 'INV-2026-1035',
        client: 'Initech LLC',
        email: 'payables@initech.com',
        status: 'refunded',
        total: 7900,
        currency: 'USD',
        issued: '2026-09-28',
        due: '2026-10-12',
        dueNote: 'Refunded Oct 1',
    },
    {
        id: 9,
        number: 'INV-2026-1034',
        client: 'Ananya Iyer',
        email: 'ananya@iyer.co',
        status: 'cancelled',
        total: 89900,
        currency: 'INR',
        issued: '2026-09-26',
        due: '2026-10-10',
        dueNote: '—',
    },
    {
        id: 10,
        number: 'INV-2026-1033',
        client: 'Sita Gurung',
        email: 'sita@gurungtreks.com',
        status: 'unpaid',
        total: 532400,
        currency: 'NPR',
        issued: '2026-10-12',
        due: '2026-10-26',
        dueNote: 'in 9 days',
    },
];

const FILTERS = [
    { key: 'all', label: 'All', count: 312 },
    { key: 'unpaid', label: 'Unpaid', count: 38 },
    { key: 'overdue', label: 'Overdue', count: 9 },
    { key: 'paid', label: 'Paid', count: 251 },
    { key: 'draft', label: 'Draft', count: 6 },
];

const columns: DataTableColumn<Invoice>[] = [
    {
        key: 'number',
        header: 'Invoice',
        primary: true,
        cell: (row) => (
            <span className="font-mono text-[0.8125rem]">{row.number}</span>
        ),
    },
    {
        key: 'client',
        header: 'Client',
        cell: (row) => (
            <div className="min-w-0">
                <div className="truncate font-medium">{row.client}</div>
                <div className="truncate text-xs text-muted-foreground">
                    {row.email}
                </div>
            </div>
        ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (row) => <StatusBadge status={row.status} />,
    },
    {
        key: 'issued',
        header: 'Issued',
        hideOnMobile: true,
        cell: (row) => (
            <span className="text-muted-foreground">
                <DateTime value={row.issued} />
            </span>
        ),
    },
    {
        key: 'due',
        header: 'Due',
        cell: (row) => (
            <div>
                <DateTime value={row.due} />
                <div
                    className={cn(
                        'text-xs',
                        row.status === 'overdue'
                            ? 'font-medium text-danger-text'
                            : 'text-muted-foreground',
                    )}
                >
                    {row.dueNote}
                </div>
            </div>
        ),
    },
    {
        key: 'total',
        header: 'Total',
        align: 'right',
        cell: (row) => (
            <Money
                amount={row.total}
                currency={row.currency}
                locale={LOCALE[row.currency]}
                showCode
                className="font-medium"
            />
        ),
    },
    {
        key: 'actions',
        header: <span className="sr-only">Actions</span>,
        align: 'right',
        hideOnMobile: true,
        className: 'w-12',
        cell: (row) => (
            <Button
                variant="ghost"
                size="icon"
                className="size-7"
                aria-label={`Actions for ${row.number}`}
            >
                <MoreHorizontal />
            </Button>
        ),
    },
];

type LineItem = {
    description: string;
    detail: string;
    qty: number;
    unit: number;
};

const LINES: LineItem[] = [
    {
        description: 'Business Hosting — Annual',
        detail: 'sharmadesigns.in · Nov 1, 2026 – Oct 31, 2027',
        qty: 1,
        unit: 299900,
    },
    {
        description: 'Domain renewal — sharmadesigns.in',
        detail: '1 year · registrar: .IN Registry',
        qty: 1,
        unit: 79900,
    },
    {
        description: 'Professional email — 2 mailboxes',
        detail: '10 GB each · monthly × 1',
        qty: 2,
        unit: 9900,
    },
];

function Row({
    label,
    children,
    strong = false,
}: {
    label: ReactNode;
    children: ReactNode;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-baseline justify-between gap-6 text-sm',
                strong && 'text-base font-semibold',
            )}
        >
            <dt className={cn(!strong && 'text-muted-foreground')}>{label}</dt>
            <dd className="tabular-nums">{children}</dd>
        </div>
    );
}

const ACTIVITY = [
    {
        icon: Bell,
        title: 'Payment reminder sent',
        meta: 'Automation · Oct 17, 09:00',
    },
    {
        icon: Mail,
        title: 'Invoice emailed to priya@sharmadesigns.in',
        meta: 'Automation · Oct 10, 09:02',
    },
    {
        icon: FileText,
        title: 'Invoice generated for renewal',
        meta: 'Billing run #8812 · Oct 10, 09:00',
    },
];

export default function InvoicePreview() {
    const [filter, setFilter] = useState('all');
    const subtotal = LINES.reduce((sum, line) => sum + line.qty * line.unit, 0);
    const cgst = Math.round(subtotal * 0.09);
    const total = subtotal + cgst * 2;

    return (
        <>
            <Head title="Invoices preview" />
            <Page>
                <PageHeader
                    title="Invoices"
                    description="All client invoices across currencies. Amounts shown in the invoice currency."
                    actions={
                        <>
                            <Button variant="outline" size="sm">
                                <Download />
                                Export
                            </Button>
                            <Button size="sm">
                                <Plus />
                                New invoice
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-(--page-gap) sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Outstanding"
                        value="$12,480.00"
                        hint="38 invoices · all currencies in USD"
                    />
                    <StatCard
                        label="Overdue"
                        value="$3,120.40"
                        hint="9 invoices"
                        trend={{
                            value: '3',
                            direction: 'up',
                            upIsGood: false,
                        }}
                    />
                    <StatCard
                        label="Collected, last 30 days"
                        value="$41,932.18"
                        trend={{ value: '9.4%', direction: 'up' }}
                        hint="vs. previous 30 days"
                    />
                    <StatCard
                        label="Average days to pay"
                        value="4.2 days"
                        trend={{
                            value: '0.6 d',
                            direction: 'down',
                            upIsGood: false,
                        }}
                        hint="faster than last quarter"
                    />
                </div>

                <div className="flex flex-col gap-3">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div
                            role="tablist"
                            aria-label="Filter by status"
                            className="inline-flex w-fit max-w-full overflow-x-auto rounded-lg border bg-muted/50 p-0.5"
                        >
                            {FILTERS.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    role="tab"
                                    aria-selected={filter === item.key}
                                    onClick={() => setFilter(item.key)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-md px-3 py-1 text-sm whitespace-nowrap text-muted-foreground transition-colors hover:text-foreground',
                                        filter === item.key &&
                                            'bg-card font-medium text-foreground ring-1 elevation-card ring-border',
                                    )}
                                >
                                    {item.label}
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {item.count}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <div className="flex gap-2">
                            <div className="relative flex-1 lg:w-72 lg:flex-none">
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <Input
                                    placeholder="Search number, client or email"
                                    aria-label="Search invoices"
                                    className="h-8 pl-8"
                                />
                            </div>
                            <Button variant="outline" size="sm">
                                Currency: All
                            </Button>
                        </div>
                    </div>

                    <DataTable
                        caption="Invoices"
                        columns={columns}
                        rows={INVOICES}
                        getRowKey={(row) => row.id}
                        rowHref={() => '#invoice-detail'}
                        footer={
                            <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
                                <span>Showing 1–10 of 312 invoices</span>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled
                                    >
                                        Previous
                                    </Button>
                                    <Button variant="outline" size="sm">
                                        Next
                                    </Button>
                                </div>
                            </div>
                        }
                    />
                </div>

                <div
                    id="invoice-detail"
                    className="flex items-center gap-3 pt-4 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    <Separator className="flex-1" />
                    Invoice detail
                    <Separator className="flex-1" />
                </div>

                <PageHeader
                    back={{ href: '/dev/invoice-preview', label: 'Invoices' }}
                    title="INV-2026-1042"
                    status="unpaid"
                    meta={
                        <>
                            <span>Priya Sharma · Sharma Designs</span>
                            <span aria-hidden="true">·</span>
                            <span>
                                Due <DateTime value="2026-10-24" /> · in 7 days
                            </span>
                        </>
                    }
                    actions={
                        <>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        className="size-8"
                                        aria-label="More actions"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem>
                                        <Copy />
                                        Duplicate
                                    </DropdownMenuItem>
                                    <DropdownMenuItem>
                                        <Download />
                                        Download PDF
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive">
                                        <Ban />
                                        Cancel invoice
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <Button variant="outline" size="sm">
                                <Send />
                                Send reminder
                            </Button>
                            <Button size="sm">
                                <CheckCircle2 />
                                Record payment
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-(--page-gap) lg:grid-cols-3 lg:items-start">
                    <SectionCard className="lg:col-span-2" flush>
                        <div className="flex flex-col gap-6 p-(--card-p) sm:flex-row sm:justify-between">
                            <div className="space-y-1">
                                <div className="font-display text-lg">
                                    Example Hosting Pvt. Ltd.
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    4th Floor, Prestige Tower, Bengaluru 560001
                                    <br />
                                    GSTIN 29ABCDE1234F1Z5
                                </div>
                            </div>
                            <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:text-right">
                                <dt className="text-muted-foreground">
                                    Invoice
                                </dt>
                                <dd className="font-mono">INV-2026-1042</dd>
                                <dt className="text-muted-foreground">
                                    Issued
                                </dt>
                                <dd>
                                    <DateTime value="2026-10-10" />
                                </dd>
                                <dt className="text-muted-foreground">Due</dt>
                                <dd>
                                    <DateTime value="2026-10-24" />
                                </dd>
                            </dl>
                        </div>

                        <div className="grid gap-6 border-y bg-muted/30 px-(--card-p) py-4 text-sm sm:grid-cols-2">
                            <div>
                                <div className="mb-1 eyebrow text-muted-foreground">
                                    Bill to
                                </div>
                                <div className="font-medium">
                                    Priya Sharma — Sharma Designs
                                </div>
                                <div className="text-muted-foreground">
                                    12 MG Road, Pune, Maharashtra 411001
                                    <br />
                                    GSTIN 27PQRSX6789K1Z2
                                </div>
                            </div>
                            <div>
                                <div className="mb-1 eyebrow text-muted-foreground">
                                    Payment
                                </div>
                                <div>
                                    UPI, cards or net banking via Razorpay
                                </div>
                                <div className="text-muted-foreground">
                                    Place of supply: Maharashtra (27)
                                </div>
                            </div>
                        </div>

                        <table className="w-full text-sm">
                            <caption className="sr-only">Line items</caption>
                            <thead>
                                <tr className="border-b text-muted-foreground">
                                    <th
                                        scope="col"
                                        className="h-(--table-head-h) px-(--card-p) text-left eyebrow"
                                    >
                                        Description
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden px-3 text-right eyebrow sm:table-cell"
                                    >
                                        Qty
                                    </th>
                                    <th
                                        scope="col"
                                        className="hidden px-3 text-right eyebrow sm:table-cell"
                                    >
                                        Unit price
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-(--card-p) text-right eyebrow"
                                    >
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {LINES.map((line) => (
                                    <tr key={line.description}>
                                        <td className="px-(--card-p) py-(--cell-py)">
                                            <div className="font-medium">
                                                {line.description}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {line.detail}
                                            </div>
                                        </td>
                                        <td className="hidden px-3 text-right tabular-nums sm:table-cell">
                                            {line.qty}
                                        </td>
                                        <td className="hidden px-3 text-right sm:table-cell">
                                            <Money
                                                amount={line.unit}
                                                currency="INR"
                                                locale="en-IN"
                                            />
                                        </td>
                                        <td className="px-(--card-p) text-right">
                                            <Money
                                                amount={line.unit * line.qty}
                                                currency="INR"
                                                locale="en-IN"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        <dl className="ml-auto w-full max-w-sm space-y-2 border-t px-(--card-p) py-4 sm:border-l">
                            <Row label="Subtotal">
                                <Money
                                    amount={subtotal}
                                    currency="INR"
                                    locale="en-IN"
                                />
                            </Row>
                            <Row label="CGST 9%">
                                <Money
                                    amount={cgst}
                                    currency="INR"
                                    locale="en-IN"
                                />
                            </Row>
                            <Row label="SGST 9%">
                                <Money
                                    amount={cgst}
                                    currency="INR"
                                    locale="en-IN"
                                />
                            </Row>
                            <Separator />
                            <Row label="Total" strong>
                                <Money
                                    amount={total}
                                    currency="INR"
                                    locale="en-IN"
                                />
                            </Row>
                            <Row label="Paid">
                                <Money
                                    amount={0}
                                    currency="INR"
                                    locale="en-IN"
                                />
                            </Row>
                        </dl>
                    </SectionCard>

                    <div className="flex flex-col gap-(--page-gap)">
                        <SectionCard>
                            <div className="space-y-1">
                                <div className="text-sm text-muted-foreground">
                                    Balance due
                                </div>
                                <div className="font-display font-numeric text-3xl">
                                    <Money
                                        amount={total}
                                        currency="INR"
                                        locale="en-IN"
                                    />
                                </div>
                                <div className="text-sm text-muted-foreground">
                                    ≈ $56.67 USD · due in 7 days
                                </div>
                            </div>
                            <div className="mt-4 grid gap-2">
                                <Button className="w-full">
                                    <CheckCircle2 />
                                    Record payment
                                </Button>
                                <Button variant="outline" className="w-full">
                                    <Send />
                                    Send payment link
                                </Button>
                            </div>
                        </SectionCard>

                        <SectionCard title="Client">
                            <div className="flex items-center gap-3">
                                <span className="grid size-10 place-items-center rounded-full bg-accent text-sm font-semibold text-accent-foreground">
                                    PS
                                </span>
                                <div className="min-w-0">
                                    <div className="truncate font-medium">
                                        Priya Sharma
                                    </div>
                                    <div className="truncate text-sm text-muted-foreground">
                                        priya@sharmadesigns.in
                                    </div>
                                </div>
                            </div>
                            <dl className="mt-4 space-y-2">
                                <Row label="Client since">Mar 2023</Row>
                                <Row label="Credit balance">
                                    <Money
                                        amount={0}
                                        currency="INR"
                                        locale="en-IN"
                                        tone="ledger"
                                    />
                                </Row>
                                <Row label="Lifetime value">
                                    <Money
                                        amount={4218600}
                                        currency="INR"
                                        locale="en-IN"
                                    />
                                </Row>
                            </dl>
                        </SectionCard>

                        <SectionCard title="Activity">
                            <ol className="relative space-y-4 before:absolute before:top-2 before:bottom-2 before:left-[0.9375rem] before:w-px before:bg-border">
                                {ACTIVITY.map((entry) => (
                                    <li
                                        key={entry.title}
                                        className="relative flex gap-3"
                                    >
                                        <span className="z-10 grid size-8 shrink-0 place-items-center rounded-full border bg-card">
                                            <entry.icon
                                                className="size-3.5 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <div className="min-w-0 pt-1">
                                            <div className="text-sm font-medium">
                                                {entry.title}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {entry.meta}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </SectionCard>
                    </div>
                </div>
            </Page>
        </>
    );
}

InvoicePreview.layout = {
    area: 'admin',
    active: 'Invoices',
    breadcrumbs: [
        { title: 'Billing', href: '/dev/invoice-preview' },
        { title: 'Invoices', href: '/dev/invoice-preview' },
    ],
};
