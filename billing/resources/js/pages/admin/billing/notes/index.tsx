import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Receipt } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { useQueryParams } from '@/components/app/use-query-params';
import { ClientPicker } from '@/components/billing/client-picker';
import type { ClientOption } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { show as showInvoice } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';
import {
    index,
    resend,
    show,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/TaxNoteListController';
import type { ResourcePaginated, SelectOption } from '@/types/app';

export type NoteRow = {
    id: number;
    number: string;
    kind: 'credit' | 'debit';
    kind_label: string;
    invoice_id: number;
    invoice_number: string;
    client: { id: number; display_name: string };
    issue_date: string;
    issue_date_bs: string | null;
    reason: string;
    total: number;
    currency: string;
    settlement: string[];
    automatic: boolean;
};

type Props = {
    notes: ResourcePaginated<NoteRow>;
    filters: Record<string, string>;
    kinds: SelectOption[];
    reasons: SelectOption[];
    settlements: SelectOption[];
    periods: SelectOption[];
    client: ClientOption | null;
    can: { resend: boolean };
};

const FILTER_KEYS = [
    'kind',
    'reason',
    'settlement',
    'automatic',
    'period',
    'from',
    'to',
    'client',
];

export default function NotesIndex({
    notes,
    filters,
    kinds,
    reasons,
    settlements,
    periods,
    client,
    can,
}: Props) {
    const { apply } = useQueryParams();
    const hasFilters = Object.keys(filters).length > 0;

    const columns: DataTableColumn<NoteRow>[] = [
        {
            key: 'number',
            header: 'Note',
            primary: true,
            cell: (note) => (
                <span className="inline-flex flex-col">
                    <span className="font-medium">{note.number}</span>
                    <span className="text-xs text-muted-foreground">
                        {note.kind_label}
                        {note.automatic && ' · Automatic'}
                    </span>
                </span>
            ),
        },
        {
            key: 'date',
            header: 'Date',
            cell: (note) => (
                <span className="inline-flex flex-col">
                    <DateTime value={note.issue_date} />
                    {note.issue_date_bs && (
                        <span className="text-xs text-muted-foreground">
                            {note.issue_date_bs}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'client',
            header: 'Client',
            cell: (note) => (
                <span className="inline-flex flex-col">
                    <span>{note.client.display_name}</span>
                    <Link
                        href={showInvoice(note.invoice_id)}
                        className="text-xs text-muted-foreground hover:underline"
                    >
                        {note.invoice_number}
                    </Link>
                </span>
            ),
        },
        {
            key: 'reason',
            header: 'Reason',
            hideOnMobile: true,
            cell: (note) => (
                <span className="inline-flex flex-col">
                    <span>{note.reason}</span>
                    <span className="text-xs text-muted-foreground">
                        {note.settlement.join(', ') || '—'}
                    </span>
                </span>
            ),
        },
        {
            key: 'total',
            header: 'Amount',
            align: 'right',
            cell: (note) => (
                <Money
                    amount={note.kind === 'credit' ? -note.total : note.total}
                    currency={note.currency}
                    tone="ledger"
                    signed
                />
            ),
        },
        ...(can.resend
            ? [
                  {
                      key: 'actions',
                      header: <span className="sr-only">Actions</span>,
                      align: 'right',
                      hideOnMobile: true,
                      cell: (note: NoteRow) => (
                          <ConfirmDialog
                              trigger={
                                  <Button variant="outline" size="sm">
                                      Resend
                                  </Button>
                              }
                              title={`Email ${note.number} to ${note.client.display_name} again?`}
                              description="The client gets the same email, with the PDF attached when attachments are on. The original is not changed."
                              confirmLabel="Resend email"
                              destructive={false}
                              action={resend(note.id)}
                          />
                      ),
                  } satisfies DataTableColumn<NoteRow>,
              ]
            : []),
    ];

    return (
        <>
            <Head title="Credit notes" />
            <Page>
                <PageHeader
                    title="Credit notes"
                    description="Every credit and debit note issued against a tax invoice. Issue one from the invoice."
                />

                <FilterBar
                    searchPlaceholder="Search note or invoice number…"
                    filterKeys={FILTER_KEYS}
                    filters={
                        <>
                            <FilterSelect
                                name="kind"
                                label="Types"
                                options={kinds}
                            />
                            <FilterSelect
                                name="reason"
                                label="Reasons"
                                options={reasons}
                            />
                            <FilterSelect
                                name="settlement"
                                label="Settlements"
                                options={settlements}
                            />
                            <FilterSelect
                                name="automatic"
                                label="Issued by"
                                allLabel="All issuers"
                                options={[
                                    { value: '0', label: 'Staff' },
                                    { value: '1', label: 'Automatic' },
                                ]}
                            />
                            <FilterSelect
                                name="period"
                                label="BS months"
                                allLabel="All BS months"
                                options={periods}
                            />
                            <Input
                                type="date"
                                aria-label="From date"
                                className="w-full sm:w-40"
                                value={filters.from ?? ''}
                                onChange={(event) =>
                                    apply({ from: event.target.value })
                                }
                            />
                            <Input
                                type="date"
                                aria-label="To date"
                                className="w-full sm:w-40"
                                value={filters.to ?? ''}
                                onChange={(event) =>
                                    apply({ to: event.target.value })
                                }
                            />
                            <div className="w-full sm:w-64">
                                <ClientPicker
                                    id="notes-client"
                                    value={client}
                                    placeholder="Filter by client…"
                                    onChange={(option) =>
                                        apply({ client: option?.id ?? null })
                                    }
                                />
                            </div>
                        </>
                    }
                />

                <DataTable
                    caption="Credit and debit notes"
                    columns={columns}
                    rows={notes.data}
                    getRowKey={(note) => note.id}
                    rowHref={(note) => show(note.id)}
                    empty={
                        <EmptyState
                            icon={Receipt}
                            title={
                                hasFilters
                                    ? 'No notes match these filters'
                                    : 'No credit or debit notes yet'
                            }
                            description={
                                hasFilters
                                    ? undefined
                                    : 'Notes appear here when staff issue them from a tax invoice, or when a cancellation or refund writes one.'
                            }
                            action={
                                hasFilters && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={index()}>
                                            Clear filters
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    }
                    footer={<Pagination paginator={notes} itemLabel="notes" />}
                />
            </Page>
        </>
    );
}
