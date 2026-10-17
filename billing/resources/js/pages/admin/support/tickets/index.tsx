import { Form, Head, Link, router } from '@inertiajs/react';
import { LifeBuoy, PenLine, Pin, Plus, X } from 'lucide-react';
import { useState } from 'react';
import TicketController from '@/actions/App/Modules/Support/Http/Controllers/Admin/TicketController';
import StaffSignatureController from '@/actions/App/Modules/Support/Http/Controllers/Admin/StaffSignatureController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import { PriorityBadge } from '@/components/support/priority-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { LaravelPaginated, SelectOption } from '@/types/app';
import type { TicketListItem } from '@/types/support';

type Props = {
    tickets: LaravelPaginated<TicketListItem>;
    filters: Record<string, string | undefined> & { view: string };
    client: {
        id: number;
        first_name: string;
        last_name: string;
        company_name: string | null;
    } | null;
    statuses: SelectOption[];
    priorities: SelectOption[];
    departments: SelectOption[];
    staff: SelectOption[];
    signature: string | null;
    can: { open: boolean; manage: boolean; delete: boolean };
};

const VIEWS = [
    { value: 'all', label: 'Open' },
    { value: 'awaiting', label: 'Awaiting reply' },
    { value: 'mine', label: 'My tickets' },
    { value: 'unassigned', label: 'Unassigned' },
    { value: 'flagged', label: 'Flagged' },
    { value: 'closed', label: 'Closed' },
];

export default function TicketsIndex({
    tickets,
    filters,
    client,
    statuses,
    priorities,
    departments,
    staff,
    signature,
    can,
}: Props) {
    const { hrefWith, apply } = useQueryParams();
    const [selected, setSelected] = useState<number[]>([]);
    const [assignee, setAssignee] = useState<string>('');
    const ids = tickets.data.map((ticket) => ticket.id as number);
    const allSelected = ids.length > 0 && ids.every((id) => selected.includes(id));
    const toggle = (id: number) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((value) => value !== id)
                : [...current, id],
        );

    const bulk = (action: 'close' | 'assign' | 'delete') =>
        router.post(
            TicketController.bulk.url(),
            {
                action,
                tickets: selected,
                ...(action === 'assign' ? { assigned_to: assignee } : {}),
            },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );

    const columns: DataTableColumn<TicketListItem>[] = [
        ...(can.manage
            ? [
                  {
                      key: 'select',
                      header: (
                          <Checkbox
                              checked={allSelected}
                              onCheckedChange={(checked) =>
                                  setSelected(checked ? ids : [])
                              }
                              aria-label="Select all tickets"
                          />
                      ),
                      className: 'w-10',
                      hideOnMobile: true,
                      cell: (ticket: TicketListItem) => (
                          <Checkbox
                              checked={selected.includes(ticket.id as number)}
                              onCheckedChange={() => toggle(ticket.id as number)}
                              aria-label={`Select ticket #${ticket.number}`}
                          />
                      ),
                  },
              ]
            : []),
        {
            key: 'subject',
            header: 'Ticket',
            primary: true,
            cell: (ticket) => (
                <span className="flex min-w-0 items-center gap-2">
                    {ticket.is_pinned && (
                        <Pin
                            className="size-3.5 shrink-0 text-muted-foreground"
                            aria-label="Pinned"
                        />
                    )}
                    <span className="shrink-0 font-mono text-xs text-muted-foreground">
                        #{ticket.number}
                    </span>
                    <span className="truncate">{ticket.subject}</span>
                </span>
            ),
        },
        {
            key: 'requester',
            header: 'Requester',
            cell: (ticket) => (
                <div className="min-w-0">
                    <div className="truncate">
                        {ticket.client ?? ticket.requester_name}
                    </div>
                    {!ticket.client_id && (
                        <div className="text-xs text-muted-foreground">
                            Guest · {ticket.requester_email}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'department',
            header: 'Department',
            hideOnMobile: true,
            cell: (ticket) => ticket.department,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (ticket) => <StatusBadge status={ticket.status} />,
        },
        {
            key: 'priority',
            header: 'Priority',
            cell: (ticket) => <PriorityBadge priority={ticket.priority} />,
        },
        {
            key: 'assignee',
            header: 'Assignee',
            hideOnMobile: true,
            cell: (ticket) =>
                ticket.assignee ?? (
                    <span className="text-muted-foreground">Unassigned</span>
                ),
        },
        {
            key: 'last_reply',
            header: 'Last reply',
            align: 'right',
            cell: (ticket) => <DateTime value={ticket.last_reply_at} relative />,
        },
    ];

    const hasFilters = ['search', 'status', 'department', 'priority', 'client', 'tag'].some(
        (key) => filters[key],
    );

    return (
        <>
            <Head title="Tickets" />
            <Page>
                <PageHeader
                    title="Tickets"
                    description="Support requests from clients, guests and email."
                    actions={
                        <>
                            <SignatureDialog signature={signature} />
                            {can.open && (
                                <Button asChild>
                                    <Link
                                        href={TicketController.create({
                                            query: client ? { client: client.id } : {},
                                        })}
                                    >
                                        <Plus />
                                        Open ticket
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <nav
                    aria-label="Ticket views"
                    className="-mx-1 flex gap-1 overflow-x-auto border-b pb-px"
                >
                    {VIEWS.map((view) => {
                        const active = filters.view === view.value;

                        return (
                            <Link
                                key={view.value}
                                href={hrefWith({
                                    view: view.value === 'all' ? null : view.value,
                                    page: null,
                                    status: null,
                                })}
                                preserveScroll
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    '-mb-px shrink-0 border-b-2 px-3 py-2 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                    active
                                        ? 'border-foreground font-medium'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {view.label}
                            </Link>
                        );
                    })}
                </nav>

                <FilterBar
                    searchPlaceholder="Search number, subject, email…"
                    filterKeys={['status', 'department', 'priority', 'client', 'tag']}
                    filters={
                        <>
                            <FilterSelect name="status" label="Status" allLabel="All statuses" options={statuses} />
                            <FilterSelect name="department" label="Department" allLabel="All departments" options={departments} />
                            <FilterSelect name="priority" label="Priority" allLabel="All priorities" options={priorities} />
                            {client && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => apply({ client: null })}
                                    aria-label="Remove client filter"
                                >
                                    {client.company_name ?? `${client.first_name} ${client.last_name}`}
                                    <X />
                                </Button>
                            )}
                        </>
                    }
                />

                {can.manage && selected.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/40 px-4 py-2 text-sm">
                        <span className="mr-auto font-medium">
                            {selected.length} selected
                        </span>
                        <Select value={assignee} onValueChange={setAssignee}>
                            <SelectTrigger className="w-44" aria-label="Assign to">
                                <SelectValue placeholder="Assign to…" />
                            </SelectTrigger>
                            <SelectContent>
                                {staff.map((member) => (
                                    <SelectItem key={member.value} value={member.value}>
                                        {member.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button size="sm" variant="outline" disabled={!assignee} onClick={() => bulk('assign')}>
                            Assign
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => bulk('close')}>
                            Close tickets
                        </Button>
                        {can.delete && (
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="destructive">
                                        Delete
                                    </Button>
                                }
                                title={`Delete ${selected.length} tickets?`}
                                description="The tickets, their messages and attachments are removed permanently. This can't be undone."
                                confirmLabel="Delete tickets"
                                onConfirm={() => bulk('delete')}
                            />
                        )}
                    </div>
                )}

                <DataTable
                    caption="Tickets"
                    columns={columns}
                    rows={tickets.data}
                    getRowKey={(ticket) => ticket.number}
                    rowHref={(ticket) => TicketController.show(ticket.id as number)}
                    empty={
                        <EmptyState
                            icon={LifeBuoy}
                            title={hasFilters ? 'No tickets match these filters' : 'No tickets here'}
                            description={
                                hasFilters
                                    ? 'Try another status or department, or clear the filters.'
                                    : 'New tickets from the client area, guests and email appear here.'
                            }
                        />
                    }
                    footer={<Pagination paginator={tickets} itemLabel="tickets" />}
                />
            </Page>
        </>
    );
}

function SignatureDialog({ signature }: { signature: string | null }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <PenLine />
                    Signature
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    {...StaffSignatureController.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Reply signature</DialogTitle>
                                <DialogDescription>
                                    Added below every reply you send. Markdown is supported.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-2">
                                <Label htmlFor="signature">Signature</Label>
                                <Textarea id="signature" name="signature" rows={4} defaultValue={signature ?? ''} />
                                {errors.signature && <p className="text-sm text-destructive">{errors.signature}</p>}
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Save signature
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
