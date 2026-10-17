import { Head, Link, useForm } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { QuoteTotals } from '@/components/billing/invoice-totals';
import type { Quote, QuoteItem } from '@/components/billing/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { show as showInvoice } from '@/actions/App/Modules/Billing/Http/Controllers/Client/InvoiceController';
import { accept, decline, index, pdf } from '@/actions/App/Modules/Billing/Http/Controllers/Client/QuoteController';

export default function ClientQuoteShow({ quote, require_tos }: { quote: Quote; require_tos: boolean }) {
    const open = ['delivered', 'on_hold'].includes(quote.stage);
    const form = useForm({ accept_tos: false });
    const errors = form.errors as Record<string, string | undefined>;
    const columns: DataTableColumn<QuoteItem>[] = [
        { key: 'description', header: 'Description', primary: true, cell: (item) => item.description },
        { key: 'quantity', header: 'Qty', align: 'right', cell: (item) => item.quantity },
        { key: 'discount', header: 'Discount', align: 'right', hideOnMobile: true, cell: (item) => (item.discount_percent > 0 ? `${item.discount_percent}%` : '—') },
        { key: 'amount', header: 'Amount', align: 'right', cell: (item) => <Money amount={item.amount} currency={quote.currency} /> },
    ];

    return (
        <>
            <Head title={`Quote ${quote.number}`} />
            <Page>
                <PageHeader
                    back={{ href: index(), label: 'Quotes' }}
                    title={`Quote ${quote.number}`}
                    status={quote.stage}
                    meta={
                        <>
                            <span>{quote.subject}</span>
                            {quote.valid_until && (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <span>
                                        Valid until <DateTime value={quote.valid_until} />
                                    </span>
                                </>
                            )}
                        </>
                    }
                    actions={
                        <Button variant="outline" asChild>
                            <a href={pdf.url(quote.id)}>
                                <Download />
                                Download PDF
                            </a>
                        </Button>
                    }
                />
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="grid content-start gap-4 lg:col-span-2">
                        {quote.proposal_text && (
                            <SectionCard title="Proposal">
                                <p className="text-sm whitespace-pre-line">{quote.proposal_text}</p>
                            </SectionCard>
                        )}
                        <SectionCard title="Items" flush>
                            <DataTable caption="Quote items" columns={columns} rows={quote.items ?? []} getRowKey={(item) => item.id} className="rounded-none border-0 shadow-none" />
                        </SectionCard>
                    </div>
                    <div className="grid content-start gap-4">
                        <SectionCard title="Total">
                            <QuoteTotals quote={quote} />
                        </SectionCard>
                        {open && (
                            <SectionCard title="Your decision">
                                <form
                                    className="grid gap-3"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        form.submit(accept(quote.id));
                                    }}
                                >
                                    {require_tos && (
                                        <div className="flex items-start gap-2">
                                            <Checkbox id="accept-tos" checked={form.data.accept_tos} onCheckedChange={(checked) => form.setData('accept_tos', checked === true)} />
                                            <Label htmlFor="accept-tos" className="leading-snug font-normal">
                                                I accept the terms of service
                                            </Label>
                                        </div>
                                    )}
                                    <InputError message={errors.accept_tos ?? errors.billing} />
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing && <Spinner />}
                                        Accept quote
                                    </Button>
                                    <ConfirmDialog
                                        trigger={
                                            <Button type="button" variant="outline">
                                                Decline quote
                                            </Button>
                                        }
                                        title={`Decline quote ${quote.number}?`}
                                        description="We’ll let the team know. Ask us if you’d like a revised quote."
                                        confirmLabel="Decline quote"
                                        action={decline(quote.id)}
                                    />
                                </form>
                            </SectionCard>
                        )}
                        {quote.invoice_id && (
                            <SectionCard title="Invoice">
                                <Button variant="outline" asChild>
                                    <Link href={showInvoice(quote.invoice_id)}>View invoice</Link>
                                </Button>
                            </SectionCard>
                        )}
                        {quote.customer_notes && (
                            <SectionCard title="Notes">
                                <p className="text-sm whitespace-pre-line">{quote.customer_notes}</p>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </Page>
        </>
    );
}
