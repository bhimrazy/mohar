import { Link, useForm, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { InvoiceDate } from '@/components/billing/invoice-date';
import { InvoiceTotals } from '@/components/billing/invoice-totals';
import { LineItems } from '@/components/billing/line-items';
import { PayPanel } from '@/components/billing/pay-panel';
import { PaymentInProgressCard } from '@/components/billing/payment-in-progress';
import type {
    Adjustment,
    Invoice,
    InvoiceItem,
    PaymentInProgressData,
    PaymentOptions,
    Transaction,
} from '@/components/billing/types';
import {
    humanize,
    normalizeMoney,
    toDecimalInput,
} from '@/components/billing/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    applyCredit,
    closeAttempt,
    index,
    pay,
    pdf,
    resumeAttempt,
} from '@/actions/App/Modules/Billing/Http/Controllers/Client/InvoiceController';

type Props = {
    invoice: Invoice;
    items: InvoiceItem[];
    adjustments: Adjustment[];
    transactions: Transaction[];
    payment: PaymentOptions | null;
    payment_in_progress: PaymentInProgressData | null;
};

function ApplyCreditForm({
    invoice,
    available,
}: {
    invoice: Invoice;
    available: number;
}) {
    const form = useForm({
        amount: toDecimalInput(Math.min(available, invoice.balance)),
    });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) => ({
                    amount: normalizeMoney(data.amount),
                }));
                form.submit(applyCredit(invoice.id), { preserveScroll: true });
            }}
            className="grid gap-3"
        >
            <p className="text-sm text-muted-foreground">
                You have{' '}
                <Money amount={available} currency={invoice.currency} /> credit
                available.
            </p>
            <div className="flex items-end gap-2">
                <Field
                    htmlFor="credit-amount"
                    label="Amount to apply"
                    error={errors.amount}
                    className="flex-1"
                >
                    <Input
                        id="credit-amount"
                        inputMode="decimal"
                        value={form.data.amount}
                        onChange={(event) =>
                            form.setData('amount', event.target.value)
                        }
                    />
                </Field>
                <Button
                    type="submit"
                    variant="outline"
                    disabled={form.processing}
                >
                    {form.processing && <Spinner />}
                    Apply credit
                </Button>
            </div>
            <InputError message={errors.billing} />
        </form>
    );
}

export default function ClientInvoiceShow({
    invoice,
    items,
    adjustments,
    transactions,
    payment,
    payment_in_progress,
}: Props) {
    const [payAnotherWay, setPayAnotherWay] = useState(false);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    // A started payment that can still complete at the provider hides the pay panel (no accidental second
    // payment); once its session has ended the customer may choose to pay another way.
    const showPayPanel =
        payment !== null &&
        (payment_in_progress === null ||
            (payment_in_progress.can_pay_another_way && payAnotherWay));
    const payments = transactions.filter(
        (txn) => txn.amount_in > 0 || txn.amount_out > 0,
    );
    const columns: DataTableColumn<Transaction>[] = [
        {
            key: 'date',
            header: 'Date',
            primary: true,
            cell: (txn) => <DateTime value={txn.occurred_at} />,
        },
        { key: 'type', header: 'Type', cell: (txn) => humanize(txn.type) },
        {
            key: 'method',
            header: 'Method',
            cell: (txn) => txn.gateway_label ?? humanize(txn.gateway),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (txn) => (
                <Money
                    amount={
                        txn.amount_out > 0 ? -txn.amount_out : txn.amount_in
                    }
                    currency={txn.currency}
                />
            ),
        },
    ];

    return (
        <>
            <Head title={`Invoice ${invoice.number}`} />
            <Page>
                <PageHeader
                    back={{ href: index(), label: 'Invoices' }}
                    title={`Invoice ${invoice.number}`}
                    status={invoice.display_status}
                    meta={
                        <>
                            {invoice.issue_date && (
                                <span>
                                    Issued{' '}
                                    <InvoiceDate
                                        value={invoice.issue_date}
                                        bs={invoice.issue_date_bs}
                                    />
                                </span>
                            )}
                            {invoice.due_date && (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <span>
                                        Due{' '}
                                        <InvoiceDate
                                            value={invoice.due_date}
                                            bs={invoice.due_date_bs}
                                        />
                                    </span>
                                </>
                            )}
                        </>
                    }
                    actions={
                        <Button variant="outline" asChild>
                            <a href={pdf.url(invoice.id)}>
                                <Download />
                                Download PDF
                            </a>
                        </Button>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="grid content-start gap-4 lg:col-span-2">
                        {payment_in_progress && (
                            <PaymentInProgressCard
                                attempt={payment_in_progress}
                                actions={
                                    <div className="grid w-full gap-2">
                                        <div className="flex flex-wrap gap-2">
                                            {payment_in_progress.can_resume && (
                                                <Button asChild>
                                                    <Link
                                                        href={resumeAttempt.url(
                                                            [
                                                                invoice.id,
                                                                payment_in_progress.reference,
                                                            ],
                                                        )}
                                                    >
                                                        Continue payment
                                                    </Link>
                                                </Button>
                                            )}
                                            <Button asChild variant="outline">
                                                <Link
                                                    href={
                                                        payment_in_progress.check_url
                                                    }
                                                    preserveScroll
                                                >
                                                    Check status
                                                </Link>
                                            </Button>
                                            {payment &&
                                                payment_in_progress.can_close && (
                                                    <ConfirmDialog
                                                        trigger={
                                                            <Button variant="ghost">
                                                                Use another
                                                                method
                                                            </Button>
                                                        }
                                                        title="Stop this payment?"
                                                        description="We'll close the payment page first, so the payment can't be made there any more. If it was already paid, we record the payment instead."
                                                        confirmLabel="Use another method"
                                                        destructive={false}
                                                        action={closeAttempt([
                                                            invoice.id,
                                                            payment_in_progress.reference,
                                                        ])}
                                                        onSuccess={() =>
                                                            setPayAnotherWay(
                                                                true,
                                                            )
                                                        }
                                                    />
                                                )}
                                            {payment &&
                                                payment_in_progress.can_pay_another_way &&
                                                !payAnotherWay && (
                                                    <Button
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setPayAnotherWay(
                                                                true,
                                                            )
                                                        }
                                                    >
                                                        Pay a different way
                                                    </Button>
                                                )}
                                        </div>
                                        <InputError message={errors.billing} />
                                    </div>
                                }
                            />
                        )}
                        {payment && showPayPanel && (
                            <SectionCard
                                title="Pay this invoice"
                                description={
                                    <>
                                        Amount due:{' '}
                                        <Money
                                            amount={invoice.balance}
                                            currency={invoice.currency}
                                        />
                                    </>
                                }
                            >
                                <PayPanel
                                    options={payment}
                                    amount={invoice.balance}
                                    currency={invoice.currency}
                                    action={pay(invoice.id)}
                                />
                            </SectionCard>
                        )}
                        {invoice.status === 'payment_pending' && (
                            <SectionCard title="Payment processing">
                                <p className="text-sm text-muted-foreground">
                                    Your payment is being processed. We’ll
                                    update this invoice once it clears.
                                </p>
                            </SectionCard>
                        )}
                        <SectionCard title="Items" flush>
                            <LineItems
                                items={items}
                                currency={invoice.currency}
                            />
                        </SectionCard>
                        {payments.length > 0 && (
                            <SectionCard title="Payments" flush>
                                <DataTable
                                    caption="Payments"
                                    columns={columns}
                                    rows={payments}
                                    getRowKey={(txn) => txn.id}
                                    className="rounded-none border-0 shadow-none"
                                />
                            </SectionCard>
                        )}
                    </div>
                    <div className="order-first grid content-start gap-4 lg:order-none">
                        <SectionCard title="Summary">
                            <InvoiceTotals
                                invoice={{ ...invoice, adjustments }}
                            />
                            {invoice.tax_exempt_reason === 'reverse_charge' && (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    Reverse charge: VAT to be accounted for by
                                    the recipient.
                                </p>
                            )}
                        </SectionCard>
                        {payment && payment.credit_available > 0 && (
                            <SectionCard title="Use your credit">
                                <ApplyCreditForm
                                    invoice={invoice}
                                    available={payment.credit_available}
                                />
                            </SectionCard>
                        )}
                        {invoice.notes && (
                            <SectionCard title="Notes">
                                <p className="text-sm whitespace-pre-line">
                                    {invoice.notes}
                                </p>
                            </SectionCard>
                        )}
                    </div>
                </div>
            </Page>
        </>
    );
}
