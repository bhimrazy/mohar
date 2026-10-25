import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Download } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { show as showInvoice } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';
import {
    index,
    resend,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/TaxNoteListController';
import { pdf } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/TaxNoteController';
import type { NoteRow } from './index';

type Line = {
    id: number;
    description: string;
    quantity: number;
    amount: number;
    tax: number;
};

type Props = {
    note: NoteRow & {
        bs_issue_date: string | null;
        reason_text: string;
        taxable: number;
        exempt: number;
        tax: number;
        issued_by: string | null;
        print_count: number;
        reverses: string | null;
        lines: Line[];
    };
    can: { resend: boolean };
};

export default function NoteShow({ note, can }: Props) {
    const money = (amount: number) => (
        <Money amount={amount} currency={note.currency} />
    );

    const columns: DataTableColumn<Line>[] = [
        {
            key: 'description',
            header: 'Description',
            primary: true,
            cell: (line) => line.description,
        },
        {
            key: 'quantity',
            header: 'Qty',
            align: 'right',
            cell: (line) => line.quantity,
        },
        {
            key: 'tax',
            header: 'VAT',
            align: 'right',
            cell: (line) => money(line.tax),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (line) => money(line.amount),
        },
    ];

    return (
        <>
            <Head title={note.number} />
            <Page>
                <PageHeader
                    back={{ href: index(), label: 'Credit notes' }}
                    title={note.number}
                    meta={note.kind_label}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a href={pdf.url(note.id)}>
                                    <Download />
                                    PDF
                                </a>
                            </Button>
                            {can.resend && (
                                <ConfirmDialog
                                    trigger={<Button>Resend email</Button>}
                                    title={`Email ${note.number} to ${note.client.display_name} again?`}
                                    description="The client gets the same email, with the PDF attached when attachments are on. The original is not changed."
                                    confirmLabel="Resend email"
                                    destructive={false}
                                    action={resend(note.id)}
                                />
                            )}
                        </>
                    }
                />

                <SectionCard title="Details">
                    <DescriptionList
                        columns={3}
                        items={[
                            {
                                label: 'Invoice',
                                value: (
                                    <Link
                                        href={showInvoice(note.invoice_id)}
                                        className="hover:underline"
                                    >
                                        {note.invoice_number}
                                    </Link>
                                ),
                            },
                            {
                                label: 'Client',
                                value: note.client.display_name,
                            },
                            {
                                label: 'Date',
                                value: (
                                    <>
                                        <DateTime value={note.issue_date} />
                                        {note.bs_issue_date &&
                                            ` · ${note.bs_issue_date}`}
                                    </>
                                ),
                            },
                            { label: 'Reason', value: note.reason },
                            {
                                label: 'Settled as',
                                value: note.settlement.join(', '),
                            },
                            {
                                label: 'Issued by',
                                value: note.automatic
                                    ? 'Automatic'
                                    : note.issued_by,
                            },
                            { label: 'Reverses', value: note.reverses },
                            { label: 'PDF downloads', value: note.print_count },
                            {
                                label: 'Note to the client',
                                value: note.reason_text,
                                full: true,
                            },
                        ]}
                    />
                </SectionCard>

                <SectionCard title="Lines" flush>
                    <DataTable
                        caption="Note lines"
                        columns={columns}
                        rows={note.lines}
                        getRowKey={(line) => line.id}
                        footer={
                            <div className="flex flex-col items-end gap-1 px-4 py-3 text-sm">
                                <span>Taxable {money(note.taxable)}</span>
                                {note.exempt > 0 && (
                                    <span>Exempt {money(note.exempt)}</span>
                                )}
                                <span>VAT {money(note.tax)}</span>
                                <span className="font-medium">
                                    Total {money(note.total)}
                                </span>
                            </div>
                        }
                    />
                </SectionCard>
            </Page>
        </>
    );
}
