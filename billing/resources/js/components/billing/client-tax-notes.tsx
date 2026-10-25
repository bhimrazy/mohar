import { Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import type { ReactNode } from 'react';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { Money } from '@/components/app/money';
import { StatusBadge } from '@/components/app/status-badge';
import { InvoiceDate } from '@/components/billing/invoice-date';
import { Button } from '@/components/ui/button';
import { show as showInvoice } from '@/actions/App/Modules/Billing/Http/Controllers/Client/InvoiceController';
import { pdf } from '@/actions/App/Modules/Billing/Http/Controllers/Client/TaxNoteController';

export type ClientTaxNote = {
    id: number;
    number: string;
    kind: 'credit' | 'debit';
    kind_label: string;
    invoice_id: number;
    invoice_number: string;
    issue_date: string;
    issue_date_bs: string | null;
    reason: string;
    reason_text: string;
    total: number;
    currency: string;
};

/**
 * A client's credit and debit notes with their PDFs. On an invoice page the invoice column is left out.
 */
export function ClientTaxNotesTable({
    notes,
    showInvoice: withInvoice = true,
    empty,
    footer,
}: {
    notes: ClientTaxNote[];
    showInvoice?: boolean;
    empty?: ReactNode;
    footer?: ReactNode;
}) {
    const columns: DataTableColumn<ClientTaxNote>[] = [
        {
            key: 'number',
            header: 'Note',
            primary: true,
            cell: (note) => (
                <span className="flex flex-wrap items-center gap-2">
                    {note.number}
                    <StatusBadge
                        status={note.kind}
                        tone={note.kind === 'credit' ? 'info' : 'neutral'}
                        label={note.kind_label}
                    />
                </span>
            ),
        },
        {
            key: 'date',
            header: 'Date',
            cell: (note) => (
                <InvoiceDate value={note.issue_date} bs={note.issue_date_bs} />
            ),
        },
        ...(withInvoice
            ? [
                  {
                      key: 'invoice',
                      header: 'Invoice',
                      cell: (note: ClientTaxNote) => (
                          <Link
                              href={showInvoice(note.invoice_id)}
                              className="underline-offset-4 hover:underline"
                          >
                              {note.invoice_number}
                          </Link>
                      ),
                  },
              ]
            : []),
        {
            key: 'reason',
            header: 'Reason',
            hideOnMobile: true,
            cell: (note) => (
                <span className="grid">
                    <span>{note.reason}</span>
                    <span className="text-muted-foreground">
                        {note.reason_text}
                    </span>
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (note) => (
                <Money
                    amount={note.kind === 'credit' ? -note.total : note.total}
                    currency={note.currency}
                    signed
                />
            ),
        },
        {
            key: 'pdf',
            header: <span className="sr-only">PDF</span>,
            align: 'right',
            cell: (note) => (
                <Button variant="ghost" size="sm" asChild>
                    <a href={pdf.url(note.id)}>
                        <Download />
                        PDF
                    </a>
                </Button>
            ),
        },
    ];

    return (
        <DataTable
            caption="Credit and debit notes"
            columns={columns}
            rows={notes}
            getRowKey={(note) => note.id}
            empty={empty}
            footer={footer}
            className={
                withInvoice ? undefined : 'rounded-none border-0 shadow-none'
            }
        />
    );
}
