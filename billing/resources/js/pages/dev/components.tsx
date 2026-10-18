import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    AlertTriangle,
    CreditCard,
    FileText,
    LifeBuoy,
    MoreHorizontal,
    Plus,
    Server,
    Wallet,
} from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable, DataTableSkeleton } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { FormActions } from '@/components/app/form-actions';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { ThemeSwitcher } from '@/components/theme-switcher';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { LaravelPaginated } from '@/types/app';

type SampleInvoice = {
    id: number;
    number: string;
    client: string;
    status: string;
    total: number;
    currency: string;
    due_date: string;
};

const invoices: SampleInvoice[] = [
    {
        id: 1042,
        number: 'INV-1042',
        client: 'Acme Hosting Ltd',
        status: 'unpaid',
        total: 12900,
        currency: 'USD',
        due_date: '2026-10-24',
    },
    {
        id: 1041,
        number: 'INV-1041',
        client: 'Northwind Traders',
        status: 'paid',
        total: 249900,
        currency: 'USD',
        due_date: '2026-10-10',
    },
    {
        id: 1040,
        number: 'INV-1040',
        client: 'Globex GmbH',
        status: 'overdue',
        total: 4500,
        currency: 'EUR',
        due_date: '2026-09-30',
    },
    {
        id: 1039,
        number: 'INV-1039',
        client: 'Initech',
        status: 'refunded',
        total: 8000,
        currency: 'GBP',
        due_date: '2026-09-21',
    },
    {
        id: 1038,
        number: 'INV-1038',
        client: 'Umbrella Corp',
        status: 'draft',
        total: 0,
        currency: 'USD',
        due_date: '2026-11-01',
    },
];

const paginator: LaravelPaginated<SampleInvoice> = {
    data: invoices,
    current_page: 2,
    last_page: 13,
    per_page: 25,
    from: 26,
    to: 50,
    total: 312,
    path: '/admin/invoices',
    first_page_url: '/admin/invoices?page=1',
    last_page_url: '/admin/invoices?page=13',
    next_page_url: '/admin/invoices?page=3',
    prev_page_url: '/admin/invoices?page=1',
    links: [
        {
            url: '/admin/invoices?page=1',
            label: '&laquo; Previous',
            active: false,
        },
        { url: '/admin/invoices?page=1', label: '1', active: false },
        { url: '/admin/invoices?page=2', label: '2', active: true },
        { url: '/admin/invoices?page=3', label: '3', active: false },
        { url: null, label: '...', active: false },
        { url: '/admin/invoices?page=12', label: '12', active: false },
        { url: '/admin/invoices?page=13', label: '13', active: false },
        { url: '/admin/invoices?page=3', label: 'Next &raquo;', active: false },
    ],
};

const columns: DataTableColumn<SampleInvoice>[] = [
    {
        key: 'number',
        header: 'Invoice',
        primary: true,
        cell: (invoice) => invoice.number,
    },
    { key: 'client', header: 'Client', cell: (invoice) => invoice.client },
    {
        key: 'status',
        header: 'Status',
        cell: (invoice) => <StatusBadge status={invoice.status} />,
    },
    {
        key: 'due',
        header: 'Due',
        cell: (invoice) => <DateTime value={invoice.due_date} />,
    },
    {
        key: 'total',
        header: 'Total',
        align: 'right',
        cell: (invoice) => (
            <Money amount={invoice.total} currency={invoice.currency} />
        ),
    },
];

const statusGroups: Record<string, string[]> = {
    Invoice: [
        'draft',
        'unpaid',
        'paid',
        'overdue',
        'cancelled',
        'refunded',
        'collections',
    ],
    Order: ['pending', 'active', 'cancelled', 'fraud'],
    Service: [
        'pending',
        'active',
        'suspended',
        'terminated',
        'cancelled',
        'fraud',
    ],
    Domain: [
        'pending',
        'pending_transfer',
        'active',
        'expired',
        'grace',
        'redemption',
        'transferred_away',
        'cancelled',
        'fraud',
    ],
    Ticket: [
        'open',
        'answered',
        'customer_reply',
        'on_hold',
        'in_progress',
        'closed',
    ],
    Client: ['active', 'inactive', 'closed'],
    Quote: ['draft', 'delivered', 'accepted', 'declined'],
};

const daysFromNow = (days: number) =>
    new Date(Date.now() + days * 86_400_000).toISOString();

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-3">
            <h2 className="text-sm font-medium text-muted-foreground">
                {title}
            </h2>
            {children}
        </section>
    );
}

export default function ComponentsShowcase() {
    return (
        <>
            <Head title="Components" />
            <Page>
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed bg-muted/40 px-4 py-3 text-sm">
                    <span className="text-muted-foreground">
                        Design-system showcase · compare directions on{' '}
                        <a
                            className="font-medium text-foreground underline underline-offset-4"
                            href="/dev/dashboard-preview"
                        >
                            dashboard
                        </a>
                        ,{' '}
                        <a
                            className="font-medium text-foreground underline underline-offset-4"
                            href="/dev/invoice-preview"
                        >
                            invoices
                        </a>{' '}
                        and{' '}
                        <a
                            className="font-medium text-foreground underline underline-offset-4"
                            href="/dev/client-preview"
                        >
                            client area
                        </a>
                        .
                    </span>
                    <ThemeSwitcher />
                </div>
                <PageHeader
                    back={{ href: '/dashboard', label: 'Invoices' }}
                    title="INV-1042"
                    status="unpaid"
                    meta={
                        <>
                            <span>Acme Hosting Ltd</span>
                            <span aria-hidden="true">·</span>
                            <span>
                                Due <DateTime value="2026-10-24" />
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
                                        aria-label="More actions"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem>
                                        Download PDF
                                    </DropdownMenuItem>
                                    <DropdownMenuItem>
                                        Send reminder
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive">
                                        Cancel invoice
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <Button variant="outline">Edit</Button>
                            <Button>
                                <CreditCard />
                                Record payment
                            </Button>
                        </>
                    }
                />

                <Section title="Stat cards">
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            label="Income this month"
                            value={<Money amount={1843250} currency="USD" />}
                            trend={{ value: '12.4%', direction: 'up' }}
                            hint="vs. last month"
                            icon={Wallet}
                        />
                        <StatCard
                            label="Overdue"
                            value={<Money amount={312000} currency="USD" />}
                            trend={{
                                value: '3',
                                direction: 'up',
                                upIsGood: false,
                            }}
                            hint="invoices"
                            icon={AlertTriangle}
                            href="/dashboard"
                        />
                        <StatCard
                            label="Active services"
                            value="1,284"
                            trend={{ value: '0%', direction: 'flat' }}
                            hint="no change"
                            icon={Server}
                        />
                        <StatCard
                            label="Open tickets"
                            value="—"
                            icon={LifeBuoy}
                            loading
                        />
                    </div>
                </Section>

                <Section title="Status badges">
                    <div className="grid gap-3 rounded-xl border bg-card p-4">
                        {Object.entries(statusGroups).map(
                            ([group, statuses]) => (
                                <div
                                    key={group}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <span className="w-16 text-xs text-muted-foreground">
                                        {group}
                                    </span>
                                    {statuses.map((status) => (
                                        <StatusBadge
                                            key={status}
                                            status={status}
                                        />
                                    ))}
                                </div>
                            ),
                        )}
                    </div>
                </Section>

                <Section title="List page: filter bar, table, pagination">
                    <FilterBar
                        searchPlaceholder="Search invoices…"
                        filterKeys={['status', 'gateway']}
                        filters={
                            <>
                                <FilterSelect
                                    name="status"
                                    label="Status"
                                    allLabel="All statuses"
                                    options={[
                                        { value: 'unpaid', label: 'Unpaid' },
                                        { value: 'paid', label: 'Paid' },
                                        { value: 'overdue', label: 'Overdue' },
                                    ]}
                                />
                                <FilterSelect
                                    name="gateway"
                                    label="Gateways"
                                    options={[
                                        {
                                            value: 'bank',
                                            label: 'Bank transfer',
                                        },
                                        { value: 'card', label: 'Card' },
                                    ]}
                                />
                            </>
                        }
                        actions={
                            <Button variant="outline" size="sm">
                                Export CSV
                            </Button>
                        }
                    />
                    <DataTable
                        caption="Invoices"
                        columns={columns}
                        rows={paginator.data}
                        getRowKey={(invoice) => invoice.id}
                        rowHref={() => '/dashboard'}
                        footer={
                            <Pagination
                                paginator={paginator}
                                itemLabel="invoices"
                            />
                        }
                    />
                </Section>

                <Section title="Empty state and loading skeleton">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <DataTable
                            columns={columns}
                            rows={[]}
                            getRowKey={(invoice) => invoice.id}
                            empty={
                                <EmptyState
                                    icon={FileText}
                                    title="No invoices yet"
                                    description="Invoices appear here once an order is placed or you create one manually."
                                    action={
                                        <Button size="sm">
                                            <Plus />
                                            Create invoice
                                        </Button>
                                    }
                                />
                            }
                        />
                        <DataTableSkeleton columns={4} rows={4} />
                    </div>
                </Section>

                <Section title="Detail: section card + description list">
                    <div className="grid gap-4 lg:grid-cols-3">
                        <SectionCard
                            title="Invoice details"
                            className="lg:col-span-2"
                            actions={
                                <Button variant="ghost" size="sm">
                                    Edit
                                </Button>
                            }
                        >
                            <DescriptionList
                                items={[
                                    {
                                        label: 'Client',
                                        value: 'Acme Hosting Ltd',
                                    },
                                    {
                                        label: 'Issued',
                                        value: <DateTime value="2026-10-10" />,
                                    },
                                    {
                                        label: 'Total',
                                        value: (
                                            <Money
                                                amount={12900}
                                                currency="USD"
                                            />
                                        ),
                                    },
                                    {
                                        label: 'Credit applied',
                                        value: (
                                            <Money
                                                amount={-2500}
                                                currency="USD"
                                                tone="ledger"
                                            />
                                        ),
                                    },
                                    { label: 'Payment method', value: null },
                                    {
                                        label: 'Last activity',
                                        value: (
                                            <DateTime
                                                value={daysFromNow(-3)}
                                                relative
                                            />
                                        ),
                                    },
                                    {
                                        label: 'Notes',
                                        value: 'Customer requested a PO number on the next invoice.',
                                        full: true,
                                    },
                                ]}
                            />
                        </SectionCard>
                        <SectionCard title="Balance">
                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Subtotal
                                    </span>
                                    <Money amount={12900} currency="USD" />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Credit
                                    </span>
                                    <Money
                                        amount={-2500}
                                        currency="USD"
                                        tone="ledger"
                                    />
                                </div>
                                <div className="flex justify-between border-t pt-2 font-medium">
                                    <span>Amount due</span>
                                    <Money
                                        amount={10400}
                                        currency="USD"
                                        showCode
                                    />
                                </div>
                                <div className="flex justify-between text-muted-foreground">
                                    <span>Other currencies</span>
                                    <span className="space-x-2">
                                        <Money amount={4500} currency="EUR" />
                                        <Money amount={150000} currency="JPY" />
                                    </span>
                                </div>
                            </div>
                        </SectionCard>
                    </div>
                </Section>

                <Section title="Form: fields, section footer, confirm dialog">
                    <SectionCard
                        title="Domain settings"
                        description="Changes apply at the registrar within a few minutes."
                        footer={
                            <FormActions>
                                <Button variant="outline">Cancel</Button>
                                <Button>Save changes</Button>
                            </FormActions>
                        }
                    >
                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                htmlFor="ns1"
                                label="Nameserver 1"
                                hint="e.g. ns1.example.com"
                            >
                                <Input
                                    id="ns1"
                                    defaultValue="ns1.example.com"
                                    aria-describedby="ns1-description"
                                />
                            </Field>
                            <Field
                                htmlFor="ns2"
                                label="Nameserver 2"
                                error="Enter a valid hostname."
                            >
                                <Input
                                    id="ns2"
                                    defaultValue="ns2..example"
                                    aria-invalid
                                    aria-describedby="ns2-description"
                                />
                            </Field>
                            <Field
                                htmlFor="notes"
                                label="Internal notes"
                                optional
                                className="sm:col-span-2"
                            >
                                <Textarea id="notes" rows={3} />
                            </Field>
                            <div className="flex items-center justify-between gap-4 rounded-lg border p-3 sm:col-span-2">
                                <div className="space-y-0.5">
                                    <label
                                        htmlFor="auto-renew"
                                        className="text-sm font-medium"
                                    >
                                        Auto-renew
                                    </label>
                                    <p className="text-sm text-muted-foreground">
                                        Renew 14 days before expiry using the
                                        default payment method.
                                    </p>
                                </div>
                                <Switch id="auto-renew" defaultChecked />
                            </div>
                        </div>
                    </SectionCard>

                    <div className="flex flex-wrap gap-2">
                        <ConfirmDialog
                            trigger={
                                <Button variant="destructive">
                                    Delete invoice
                                </Button>
                            }
                            title="Delete invoice INV-1042?"
                            description="The invoice and its line items will be permanently removed. Payments already recorded are kept on the client's transaction log."
                            confirmLabel="Delete invoice"
                            href="/dev/noop"
                        />
                        <ConfirmDialog
                            trigger={
                                <Button variant="outline">
                                    Terminate service
                                </Button>
                            }
                            title="Terminate acme.example.com?"
                            description="The hosting account and all its files, databases and mailboxes are deleted on the server. This can't be undone."
                            confirmLabel="Terminate service"
                            confirmText="acme.example.com"
                            href="/dev/noop"
                            method="post"
                        />
                    </div>
                </Section>
            </Page>
        </>
    );
}
