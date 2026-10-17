import { Head, Link } from '@inertiajs/react';
import { Download, Send } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { QuoteTotals } from '@/components/billing/invoice-totals';
import type { Quote, QuoteItem } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { show as showInvoice } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';
import { convert, deliver, destroy, edit, index, pdf } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/QuoteController';

type Props = { quote: Quote; can: { manage: boolean } };

export default function QuoteShow({ quote, can }: Props) {
    const editable = ['draft', 'delivered', 'on_hold'].includes(quote.stage);
    const columns: DataTableColumn<QuoteItem>[] = [
        { key: 'description', header: 'Description', primary: true, cell: (item) => item.description },
        { key: 'quantity', header: 'Qty', align: 'right', cell: (item) => item.quantity },
        { key: 'unit', header: 'Unit price', align: 'right', hideOnMobile: true, cell: (item) => <Money amount={item.unit_amount} currency={quote.currency} /> },
        { key: 'discount', header: 'Discount', align: 'right', hideOnMobile: true, cell: (item) => (item.discount_percent > 0 ? `${item.discount_percent}%` : '—') },
        { key: 'amount', header: 'Amount', align: 'right', cell: (item) => <Money amount={item.amount} currency={quote.currency} /> },
    ];

    return (
        <>
            <Head title={quote.number} />
            <Page>
                <PageHeader
                    back={{ href: index(), label: 'Quotes' }}
                    title={quote.number}
                    status={quote.stage}
                    meta={
                        <>
                            <span>{quote.subject}</span>
                            <span aria-hidden="true">·</span>
                            <span>{quote.client?.display_name}</span>
                        </>
                    }
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a href={pdf.url(quote.id)}>
                                    <Download />
                                    PDF
                                </a>
                            </Button>
                            {can.manage && quote.stage === 'draft' && (
                                <ConfirmDialog
                                    trigger={<Button variant="outline">Delete</Button>}
                                    title={`Delete ${quote.number}?`}
                                    description="The draft quote is removed. This can't be undone."
                                    confirmLabel="Delete quote"
                                    action={destroy(quote.id)}
                                />
                            )}
                            {can.manage && editable && (
                                <Button variant="outline" asChild>
                                    <Link href={edit(quote.id)}>Edit</Link>
                                </Button>
                            )}
                            {can.manage && quote.invoice_id === null && quote.stage !== 'declined' && quote.stage !== 'expired' && (
                                <ConfirmDialog
                                    trigger={<Button variant="outline">Convert to invoice</Button>}
                                    title={`Convert ${quote.number} to an invoice?`}
                                    description="An unpaid invoice with these lines is created and the quote is marked accepted."
                                    confirmLabel="Convert to invoice"
                                    destructive={false}
                                    action={convert(quote.id)}
                                />
                            )}
                            {can.manage && editable && (
                                <ConfirmDialog
                                    trigger={
                                        <Button>
                                            <Send />
                                            {quote.stage === 'draft' ? 'Send quote' : 'Resend quote'}
                                        </Button>
                                    }
                                    title={`Send ${quote.number} to ${quote.client?.display_name ?? 'the client'}?`}
                                    description="The client can view, accept or decline it in their account."
                                    confirmLabel="Send quote"
                                    destructive={false}
                                    action={deliver(quote.id)}
                                />
                            )}
                        </>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="grid content-start gap-4 lg:col-span-2">
                        {quote.proposal_text && (
                            <SectionCard title="Proposal">
                                <p className="text-sm whitespace-pre-line">{quote.proposal_text}</p>
                            </SectionCard>
                        )}
                        <SectionCard title="Lines" flush>
                            <DataTable caption="Quote lines" columns={columns} rows={quote.items ?? []} getRowKey={(item) => item.id} className="rounded-none border-0 shadow-none" />
                        </SectionCard>
                    </div>
                    <div className="grid content-start gap-4">
                        <SectionCard title="Totals">
                            <QuoteTotals quote={quote} />
                        </SectionCard>
                        <SectionCard title="Details">
                            <DescriptionList
                                columns={1}
                                items={[
                                    { label: 'Valid until', value: <DateTime value={quote.valid_until} /> },
                                    { label: 'Sent', value: quote.delivered_at && <DateTime value={quote.delivered_at} relative /> },
                                    { label: 'Accepted', value: quote.accepted_at && <DateTime value={quote.accepted_at} relative /> },
                                    {
                                        label: 'Invoice',
                                        value: quote.invoice_id && (
                                            <Link href={showInvoice(quote.invoice_id)} className="hover:underline">
                                                View invoice
                                            </Link>
                                        ),
                                    },
                                    { label: 'Notes for the client', value: quote.customer_notes },
                                    { label: 'Staff notes', value: quote.admin_notes },
                                ]}
                            />
                        </SectionCard>
                    </div>
                </div>
            </Page>
        </>
    );
}
