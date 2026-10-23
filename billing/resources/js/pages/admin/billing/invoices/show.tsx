import { Link, router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import {
    CreditCard,
    Download,
    MoreHorizontal,
    Pencil,
    Send,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { FormDialog } from '@/components/billing/form-dialog';
import { InvoiceDate } from '@/components/billing/invoice-date';
import { InvoiceTotals } from '@/components/billing/invoice-totals';
import { LineItems } from '@/components/billing/line-items';
import { PaymentInProgressCard } from '@/components/billing/payment-in-progress';
import { TaxNotesPanel } from '@/components/billing/tax-notes';
import type { Creditable, TaxNote } from '@/components/billing/tax-notes';
import { SelectField } from '@/components/billing/select-field';
import type {
    GatewayOption,
    Invoice,
    PayMethod,
    Transaction,
} from '@/components/billing/types';
import { humanize, toDecimalInput } from '@/components/billing/types';
import type { PaymentInProgressData } from '@/components/billing/types';
import { parseMoneyInput } from '@/lib/money';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    applyCredit,
    cancel,
    cancelLines,
    capture,
    markPaid,
    pdf,
    publish,
    recordPayment,
    remind,
    removeCredit,
    reopen,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceActionController';
import {
    destroy,
    edit,
    index,
    updateDetails,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/InvoiceController';
import { check as checkAttempt } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/PaymentAttemptController';
import { refund } from '@/actions/App/Modules/Billing/Http/Controllers/Admin/TransactionController';
import {
    PendingRefundBanner,
    TransactionStatusBadge,
    releasedClass,
} from '@/components/billing/pending-refund';
import { show as showClient } from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import { countryName } from '@/lib/country';
import { labelWithCurrency } from '@/lib/money';

type Props = {
    invoice: Invoice;
    transactions: Transaction[];
    credit_available: number;
    payment_in_progress: PaymentInProgressData | null;
    gateways: GatewayOption[];
    pay_methods: PayMethod[];
    tax_notes: TaxNote[] | null;
    notes_enabled: boolean;
    creditable: Creditable | null;
    note_blocked: { credit: string | null; debit: string | null } | null;
    credit_label: string | null;
    can: {
        manage: boolean;
        payments: boolean;
        refunds: boolean;
        notes: boolean;
    };
};

const PAYABLE = ['unpaid', 'payment_pending', 'collections'];

const today = () => new Date().toISOString().slice(0, 10);

function RefundDialog({
    transaction,
    gateways,
}: {
    transaction: Transaction;
    gateways: GatewayOption[];
}) {
    const option = gateways.find(
        (gateway) => gateway.value === transaction.gateway,
    );
    const name = option?.label ?? 'the gateway';
    const canRefundAtGateway = option?.refund ?? false;
    // A gateway without partial refunds can only refund the whole original payment through its API.
    const fullOnly = canRefundAtGateway && !option?.partial_refund;
    const isFullRefund = (input: string) =>
        transaction.refundable_remaining === transaction.amount_in &&
        parseMoneyInput(input) === transaction.amount_in;

    return (
        <FormDialog
            trigger={
                <Button variant="ghost" size="sm">
                    Refund
                </Button>
            }
            title={`Refund ${transaction.gateway_txn_id ?? `transaction #${transaction.id}`}?`}
            description={
                <>
                    Up to{' '}
                    <Money
                        amount={transaction.refundable_remaining}
                        currency={transaction.currency}
                    />{' '}
                    can be refunded.
                </>
            }
            submitLabel="Refund payment"
            destructive
            action={refund(transaction.id)}
            moneyFields={['amount']}
            initial={{
                amount: toDecimalInput(transaction.refundable_remaining),
                mode:
                    canRefundAtGateway &&
                    (!fullOnly ||
                        transaction.refundable_remaining ===
                            transaction.amount_in)
                        ? 'gateway'
                        : 'record_only',
                reverse_effects: false,
                description: '',
            }}
        >
            {(form) => {
                const gatewayAllowed =
                    canRefundAtGateway &&
                    (!fullOnly || isFullRefund(form.data.amount));
                const guidance = !canRefundAtGateway
                    ? `${name} can't refund through the API. Refund in the ${name} merchant portal first, then record it here.`
                    : !gatewayAllowed
                      ? `${name} refunds only the full payment through the API. Refund part of it in the ${name} merchant portal, then record it here.`
                      : `If ${name} refuses (for example a payment made from a bank account), refund it in the ${name} merchant portal and record it here.`;

                return (
                    <div className="grid gap-4">
                        {transaction.gateway && (
                            <p className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                                {guidance}
                            </p>
                        )}
                        <Field
                            className="content-start"
                            htmlFor="refund-amount"
                            label="Amount"
                            error={form.errors.amount}
                        >
                            <Input
                                id="refund-amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(event) => {
                                    const amount = event.target.value;
                                    // A partial amount can't go through a full-only gateway: switch to recording it.
                                    const keepGateway =
                                        !fullOnly || isFullRefund(amount);

                                    form.setData({
                                        ...form.data,
                                        amount,
                                        mode:
                                            form.data.mode === 'gateway' &&
                                            !keepGateway
                                                ? 'record_only'
                                                : form.data.mode,
                                    });
                                }}
                                aria-invalid={!!form.errors.amount}
                            />
                        </Field>
                        <Field
                            className="content-start"
                            htmlFor="refund-mode"
                            label="Refund to"
                            error={form.errors.mode}
                        >
                            <SelectField
                                id="refund-mode"
                                value={form.data.mode}
                                onChange={(value) =>
                                    form.setData('mode', value)
                                }
                                options={[
                                    ...(gatewayAllowed
                                        ? [
                                              {
                                                  value: 'gateway',
                                                  label: 'Original payment method (gateway)',
                                              },
                                          ]
                                        : []),
                                    {
                                        value: 'record_only',
                                        label: 'Record only (refunded outside the system)',
                                    },
                                    {
                                        value: 'to_credit',
                                        label: 'Account credit',
                                    },
                                ]}
                            />
                        </Field>
                        <div className="flex items-start gap-2">
                            <Checkbox
                                id="refund-reverse"
                                checked={form.data.reverse_effects}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'reverse_effects',
                                        checked === true,
                                    )
                                }
                            />
                            <Label
                                htmlFor="refund-reverse"
                                className="leading-snug font-normal"
                            >
                                Reverse what the payment granted (roll back
                                service due dates, affiliate commission)
                            </Label>
                        </div>
                    </div>
                );
            }}
        </FormDialog>
    );
}

export default function InvoiceShow({
    invoice,
    transactions,
    credit_available,
    payment_in_progress,
    gateways,
    pay_methods,
    tax_notes,
    notes_enabled,
    creditable,
    note_blocked,
    credit_label,
    can,
}: Props) {
    const [dialog, setDialog] = useState<
        | null
        | 'payment'
        | 'details'
        | 'cancel'
        | 'cancelLines'
        | 'cancelLinesPlain'
        | 'markPaid'
        | 'capture'
        | 'credit'
        | 'removeCredit'
    >(null);
    const [selectedLines, setSelectedLines] = useState<number[]>([]);
    const isDraft = invoice.status === 'draft';
    const isPayable = PAYABLE.includes(invoice.status) && invoice.balance > 0;
    const items = invoice.items ?? [];
    const snapshot = invoice.client_snapshot ?? {};
    const close = (open: boolean) => !open && setDialog(null);
    const gatewayName = (key: string | null) =>
        gateways.find((gateway) => gateway.value === key)?.label ??
        (key ? humanize(key) : null);

    const transactionColumns: DataTableColumn<Transaction>[] = [
        {
            key: 'date',
            header: 'Date',
            primary: true,
            cell: (txn) => (
                <DateTime value={txn.occurred_at} format="datetime" />
            ),
        },
        { key: 'type', header: 'Type', cell: (txn) => humanize(txn.type) },
        {
            key: 'gateway',
            header: 'Gateway',
            cell: (txn) => (
                <span className="flex items-center gap-2">
                    {gatewayName(txn.gateway)}
                    {gateways.find((gateway) => gateway.value === txn.gateway)
                        ?.sandbox && (
                        <StatusBadge
                            status="sandbox"
                            tone="warning"
                            label="Sandbox"
                        />
                    )}
                </span>
            ),
        },
        {
            key: 'reference',
            header: 'Reference',
            hideOnMobile: true,
            cell: (txn) => (
                <span
                    className="inline-block max-w-36 truncate align-bottom font-mono text-xs"
                    title={txn.gateway_txn_id ?? undefined}
                >
                    {txn.gateway_txn_id ?? '—'}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (txn) => <TransactionStatusBadge txn={txn} />,
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            cell: (txn) => (
                <div className={releasedClass(txn)}>
                    <Money
                        amount={
                            txn.amount_out > 0 ? -txn.amount_out : txn.amount_in
                        }
                        currency={txn.currency}
                        tone="ledger"
                    />
                    {txn.gateway_currency && txn.gateway_amount !== null && (
                        <div className="text-xs text-muted-foreground">
                            <Money
                                amount={txn.gateway_amount}
                                currency={txn.gateway_currency}
                            />{' '}
                            @ {txn.exchange_rate}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            hideOnMobile: true,
            cell: (txn) => {
                if (!can.refunds) {
                    return null;
                }

                // A refund waiting for the provider holds the payment's amount: say why Refund isn't available.
                if (
                    transactions.some(
                        (other) =>
                            other.parent_transaction_id === txn.id &&
                            other.type === 'refund' &&
                            other.status === 'pending',
                    )
                ) {
                    return (
                        <span className="inline-flex flex-col items-end gap-1">
                            <Button variant="ghost" size="sm" disabled>
                                Refund
                            </Button>
                            <span className="text-xs text-muted-foreground">
                                A refund is waiting for the provider.
                            </span>
                        </span>
                    );
                }

                return txn.refundable ? (
                    <RefundDialog transaction={txn} gateways={gateways} />
                ) : null;
            },
        },
    ];

    return (
        <>
            <Head title={invoice.number} />
            <Page>
                <PageHeader
                    back={{ href: index(), label: 'Invoices' }}
                    title={invoice.number}
                    status={invoice.display_status}
                    badges={
                        invoice.kind === 'add_funds' ? (
                            <StatusBadge
                                status="add_funds"
                                tone="info"
                                label="Add funds"
                            />
                        ) : credit_label ? (
                            <StatusBadge
                                status="credited"
                                tone="info"
                                label={credit_label}
                            />
                        ) : undefined
                    }
                    meta={
                        <>
                            {invoice.client && (
                                <Link
                                    href={showClient(invoice.client.id)}
                                    className="hover:text-foreground hover:underline"
                                >
                                    {invoice.client.display_name}
                                </Link>
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
                                    <DropdownMenuItem asChild>
                                        <a href={pdf.url(invoice.id)}>
                                            <Download />
                                            Download PDF
                                        </a>
                                    </DropdownMenuItem>
                                    {can.manage && isPayable && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                router.post(
                                                    remind.url(invoice.id),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Send />
                                            Send reminder
                                        </DropdownMenuItem>
                                    )}
                                    {can.manage && !isDraft && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setDialog('details')
                                            }
                                        >
                                            <Pencil />
                                            Edit details
                                        </DropdownMenuItem>
                                    )}
                                    {can.payments && isPayable && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                setDialog('markPaid')
                                            }
                                        >
                                            Mark paid (write off)
                                        </DropdownMenuItem>
                                    )}
                                    {can.manage &&
                                        invoice.status === 'cancelled' && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    router.post(
                                                        reopen.url(invoice.id),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Reopen invoice
                                            </DropdownMenuItem>
                                        )}
                                    {can.manage &&
                                        (isDraft ||
                                            PAYABLE.includes(
                                                invoice.status,
                                            )) && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        setDialog('cancel')
                                                    }
                                                >
                                                    {isDraft
                                                        ? 'Delete draft'
                                                        : 'Cancel invoice'}
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                            {isDraft && can.manage && (
                                <>
                                    <Button variant="outline" asChild>
                                        <Link href={edit(invoice.id)}>
                                            Edit
                                        </Link>
                                    </Button>
                                    <ConfirmDialog
                                        trigger={
                                            <Button>Publish invoice</Button>
                                        }
                                        title={`Publish ${invoice.number}?`}
                                        description="The invoice gets its number, tax is calculated with the current rules and the client is notified. Lines can't be edited afterwards."
                                        confirmLabel="Publish invoice"
                                        destructive={false}
                                        action={publish(invoice.id)}
                                    />
                                </>
                            )}
                            {isPayable && can.payments && (
                                <Button onClick={() => setDialog('payment')}>
                                    <CreditCard />
                                    Record payment
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Total"
                        value={
                            <Money
                                amount={invoice.total}
                                currency={invoice.currency}
                            />
                        }
                    />
                    <StatCard
                        label="Paid"
                        value={
                            <Money
                                amount={invoice.amount_paid}
                                currency={invoice.currency}
                            />
                        }
                    />
                    <StatCard
                        label="Balance"
                        value={
                            <Money
                                amount={invoice.balance}
                                currency={invoice.currency}
                                tone="ledger"
                            />
                        }
                        hint={
                            invoice.credit_applied > 0 ? (
                                <>
                                    incl.{' '}
                                    <Money
                                        amount={invoice.credit_applied}
                                        currency={invoice.currency}
                                    />{' '}
                                    credit
                                </>
                            ) : undefined
                        }
                    />
                    <StatCard
                        label={invoice.paid_at ? 'Paid on' : 'Due'}
                        value={
                            <DateTime
                                value={invoice.paid_at ?? invoice.due_date}
                            />
                        }
                        hint={
                            !invoice.paid_at && invoice.due_date_bs
                                ? invoice.due_date_bs
                                : undefined
                        }
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="grid content-start gap-4 lg:col-span-2">
                        {payment_in_progress && (
                            <PaymentInProgressCard
                                attempt={payment_in_progress}
                                staff
                                actions={
                                    can.payments &&
                                    payment_in_progress.id !== null ? (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    checkAttempt.url(
                                                        payment_in_progress.id ??
                                                            0,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Check now
                                        </Button>
                                    ) : (
                                        <></>
                                    )
                                }
                            />
                        )}
                        <SectionCard
                            title="Line items"
                            flush
                            actions={
                                can.manage &&
                                isPayable &&
                                selectedLines.length > 0 ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            notes_enabled
                                                ? setDialog('cancelLines')
                                                : setDialog('cancelLinesPlain')
                                        }
                                    >
                                        Cancel {selectedLines.length}{' '}
                                        {selectedLines.length === 1
                                            ? 'line'
                                            : 'lines'}
                                    </Button>
                                ) : undefined
                            }
                        >
                            <LineItems
                                items={items}
                                currency={invoice.currency}
                                select={
                                    can.manage && isPayable && items.length > 1
                                        ? (item) =>
                                              item.cancelled ? null : (
                                                  <Checkbox
                                                      aria-label={`Select ${item.description}`}
                                                      checked={selectedLines.includes(
                                                          item.id,
                                                      )}
                                                      onCheckedChange={(
                                                          checked,
                                                      ) =>
                                                          setSelectedLines(
                                                              (current) =>
                                                                  checked ===
                                                                  true
                                                                      ? [
                                                                            ...current,
                                                                            item.id,
                                                                        ]
                                                                      : current.filter(
                                                                            (
                                                                                id,
                                                                            ) =>
                                                                                id !==
                                                                                item.id,
                                                                        ),
                                                          )
                                                      }
                                                  />
                                              )
                                        : undefined
                                }
                            />
                        </SectionCard>

                        <PendingRefundBanner
                            transactions={transactions}
                            gateways={gateways}
                            canAct={can.refunds}
                        />

                        <TaxNotesPanel
                            invoiceId={invoice.id}
                            currency={invoice.currency}
                            notes={tax_notes}
                            creditable={creditable}
                            enabled={notes_enabled}
                            canIssue={can.notes}
                            blocked={note_blocked}
                        />

                        <SectionCard title="Transactions" flush>
                            <DataTable
                                caption="Transactions"
                                columns={transactionColumns}
                                rows={transactions}
                                getRowKey={(txn) => txn.id}
                                className="rounded-none border-0 shadow-none"
                                empty={
                                    <EmptyState
                                        title="No payments yet"
                                        description="Payments, refunds and write-offs for this invoice appear here."
                                    />
                                }
                            />
                        </SectionCard>

                        {(invoice.notes || invoice.admin_notes) && (
                            <SectionCard title="Notes">
                                <DescriptionList
                                    columns={1}
                                    items={[
                                        {
                                            label: 'Shown to the client',
                                            value: invoice.notes,
                                            full: true,
                                        },
                                        {
                                            label: 'Staff only',
                                            value: invoice.admin_notes,
                                            full: true,
                                        },
                                    ]}
                                />
                            </SectionCard>
                        )}
                    </div>

                    <div className="grid content-start gap-4">
                        <SectionCard title="Balance">
                            <InvoiceTotals invoice={invoice} />
                            {invoice.tax_exempt_reason && (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    {invoice.tax_exempt_reason ===
                                    'reverse_charge'
                                        ? 'EU reverse charge: the customer accounts for VAT.'
                                        : 'Client is tax exempt.'}
                                </p>
                            )}
                        </SectionCard>

                        <SectionCard title="Bill to">
                            <DescriptionList
                                columns={1}
                                items={[
                                    {
                                        label: 'Name',
                                        value: snapshot.company
                                            ? `${snapshot.company} (${snapshot.name ?? ''})`
                                            : snapshot.name,
                                    },
                                    {
                                        label: 'Address',
                                        value: [
                                            snapshot.address_1,
                                            snapshot.address_2,
                                            [snapshot.postcode, snapshot.city]
                                                .filter(Boolean)
                                                .join(' '),
                                            snapshot.state,
                                            countryName(snapshot.country),
                                        ]
                                            .filter(Boolean)
                                            .join(', '),
                                    },
                                    { label: 'Tax ID', value: snapshot.tax_id },
                                    {
                                        label: 'Gateway',
                                        value: gatewayName(
                                            invoice.payment_gateway,
                                        ),
                                    },
                                    {
                                        label: 'Issued',
                                        value: invoice.issue_date && (
                                            <InvoiceDate
                                                value={invoice.issue_date}
                                                bs={invoice.issue_date_bs}
                                            />
                                        ),
                                    },
                                    ...(invoice.proforma_number &&
                                    invoice.legal_number
                                        ? [
                                              {
                                                  label: 'Proforma number',
                                                  value: invoice.proforma_number,
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </SectionCard>

                        {can.payments &&
                            (isPayable || invoice.credit_applied > 0) &&
                            invoice.kind === 'standard' && (
                                <SectionCard
                                    title="Account credit"
                                    description="Credit available to this client."
                                >
                                    <div className="flex items-center justify-between gap-4">
                                        <Money
                                            amount={credit_available}
                                            currency={invoice.currency}
                                            tone="ledger"
                                            className="text-lg font-semibold"
                                        />
                                        <div className="flex gap-2">
                                            {invoice.credit_applied > 0 &&
                                                PAYABLE.includes(
                                                    invoice.status,
                                                ) && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setDialog(
                                                                'removeCredit',
                                                            )
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            {isPayable &&
                                                credit_available > 0 && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            setDialog('credit')
                                                        }
                                                    >
                                                        Apply credit
                                                    </Button>
                                                )}
                                        </div>
                                    </div>
                                </SectionCard>
                            )}

                        {can.payments &&
                            isPayable &&
                            pay_methods.length > 0 &&
                            invoice.status !== 'payment_pending' && (
                                <SectionCard title="Stored payment methods">
                                    <ul className="mb-3 space-y-1 text-sm">
                                        {pay_methods.map((method) => (
                                            <li
                                                key={method.id}
                                                className="flex justify-between"
                                            >
                                                <span>{method.label}</span>
                                                <span className="text-muted-foreground">
                                                    {method.is_default
                                                        ? 'Default'
                                                        : method.expiry}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setDialog('capture')}
                                    >
                                        Attempt capture
                                    </Button>
                                </SectionCard>
                            )}
                    </div>
                </div>
            </Page>

            {isPayable && (
                <FormDialog
                    open={dialog === 'payment'}
                    onOpenChange={close}
                    title={`Record payment on ${invoice.number}`}
                    description="Partial payments reduce the balance; anything above the balance becomes account credit."
                    submitLabel="Record payment"
                    action={recordPayment(invoice.id)}
                    moneyFields={['amount', 'fee']}
                    initial={{
                        amount: toDecimalInput(invoice.balance),
                        gateway: invoice.payment_gateway ?? 'bank_transfer',
                        gateway_txn_id: '',
                        occurred_at: today(),
                        fee: '',
                    }}
                >
                    {(form) => (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                className="content-start"
                                htmlFor="payment-amount"
                                label={labelWithCurrency(
                                    'Amount',
                                    invoice.currency,
                                )}
                                error={form.errors.amount}
                            >
                                <Input
                                    id="payment-amount"
                                    inputMode="decimal"
                                    value={form.data.amount}
                                    onChange={(event) =>
                                        form.setData(
                                            'amount',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={!!form.errors.amount}
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="payment-date"
                                label="Date"
                                error={form.errors.occurred_at}
                            >
                                <Input
                                    id="payment-date"
                                    type="date"
                                    value={form.data.occurred_at}
                                    onChange={(event) =>
                                        form.setData(
                                            'occurred_at',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="payment-gateway"
                                label="Gateway"
                                error={form.errors.gateway}
                            >
                                <SelectField
                                    id="payment-gateway"
                                    value={form.data.gateway}
                                    onChange={(value) =>
                                        form.setData('gateway', value)
                                    }
                                    options={gateways}
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="payment-fee"
                                label="Fee"
                                optional
                                error={form.errors.fee}
                            >
                                <Input
                                    id="payment-fee"
                                    inputMode="decimal"
                                    value={form.data.fee}
                                    onChange={(event) =>
                                        form.setData('fee', event.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                htmlFor="payment-reference"
                                label="Transaction ID"
                                optional
                                error={form.errors.gateway_txn_id}
                                className="content-start sm:col-span-2"
                            >
                                <Input
                                    id="payment-reference"
                                    value={form.data.gateway_txn_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'gateway_txn_id',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    )}
                </FormDialog>
            )}

            <FormDialog
                open={dialog === 'details'}
                onOpenChange={close}
                title="Edit invoice details"
                description="Lines and amounts of an issued invoice can't change; these fields can."
                submitLabel="Save changes"
                action={updateDetails(invoice.id)}
                initial={{
                    due_date: invoice.due_date ?? '',
                    payment_gateway: invoice.payment_gateway ?? '',
                    notes: invoice.notes ?? '',
                    admin_notes: invoice.admin_notes ?? '',
                }}
            >
                {(form) => (
                    <div className="grid gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                className="content-start"
                                htmlFor="details-due"
                                label="Due date"
                                error={form.errors.due_date}
                            >
                                <Input
                                    id="details-due"
                                    type="date"
                                    value={form.data.due_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'due_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                className="content-start"
                                htmlFor="details-gateway"
                                label="Gateway"
                                error={form.errors.payment_gateway}
                            >
                                <SelectField
                                    id="details-gateway"
                                    value={form.data.payment_gateway}
                                    onChange={(value) =>
                                        form.setData('payment_gateway', value)
                                    }
                                    options={gateways}
                                />
                            </Field>
                        </div>
                        <Field
                            className="content-start"
                            htmlFor="details-notes"
                            label="Notes shown to the client"
                            optional
                            error={form.errors.notes}
                        >
                            <Textarea
                                id="details-notes"
                                rows={3}
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            className="content-start"
                            htmlFor="details-admin-notes"
                            label="Staff notes"
                            optional
                            error={form.errors.admin_notes}
                        >
                            <Textarea
                                id="details-admin-notes"
                                rows={3}
                                value={form.data.admin_notes}
                                onChange={(event) =>
                                    form.setData(
                                        'admin_notes',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                )}
            </FormDialog>

            {isPayable && credit_available > 0 && (
                <FormDialog
                    open={dialog === 'credit'}
                    onOpenChange={close}
                    title="Apply account credit"
                    description={
                        <>
                            Available:{' '}
                            <Money
                                amount={credit_available}
                                currency={invoice.currency}
                            />
                            .
                        </>
                    }
                    submitLabel="Apply credit"
                    action={applyCredit(invoice.id)}
                    moneyFields={['amount']}
                    initial={{
                        amount: toDecimalInput(
                            Math.min(credit_available, invoice.balance),
                        ),
                    }}
                >
                    {(form) => (
                        <Field
                            className="content-start"
                            htmlFor="credit-amount"
                            label="Amount"
                            error={form.errors.amount}
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
                    )}
                </FormDialog>
            )}

            {invoice.credit_applied > 0 && (
                <FormDialog
                    open={dialog === 'removeCredit'}
                    onOpenChange={close}
                    title="Remove applied credit"
                    description="The amount goes back to the client's credit balance and the invoice balance increases."
                    submitLabel="Remove credit"
                    action={removeCredit(invoice.id)}
                    moneyFields={['amount']}
                    initial={{ amount: toDecimalInput(invoice.credit_applied) }}
                >
                    {(form) => (
                        <Field
                            className="content-start"
                            htmlFor="remove-credit-amount"
                            label="Amount"
                            error={form.errors.amount}
                        >
                            <Input
                                id="remove-credit-amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(event) =>
                                    form.setData('amount', event.target.value)
                                }
                            />
                        </Field>
                    )}
                </FormDialog>
            )}

            {pay_methods.length > 0 && (
                <FormDialog
                    open={dialog === 'capture'}
                    onOpenChange={close}
                    title={`Charge ${invoice.number}`}
                    description={
                        <>
                            Charges the balance of{' '}
                            <Money
                                amount={invoice.balance}
                                currency={invoice.currency}
                            />{' '}
                            to a stored payment method.
                        </>
                    }
                    submitLabel="Attempt capture"
                    action={capture(invoice.id)}
                    initial={{
                        pay_method_id: String(
                            pay_methods.find((method) => method.is_default)
                                ?.id ?? pay_methods[0].id,
                        ),
                    }}
                >
                    {(form) => (
                        <Field
                            className="content-start"
                            htmlFor="capture-method"
                            label="Payment method"
                            error={form.errors.pay_method_id}
                        >
                            <SelectField
                                id="capture-method"
                                value={form.data.pay_method_id}
                                onChange={(value) =>
                                    form.setData('pay_method_id', value)
                                }
                                options={pay_methods.map((method) => ({
                                    value: String(method.id),
                                    label: method.label,
                                }))}
                            />
                        </Field>
                    )}
                </FormDialog>
            )}

            <ConfirmDialog
                open={dialog === 'cancel' && !(notes_enabled && !isDraft)}
                onOpenChange={close}
                title={
                    isDraft
                        ? `Delete ${invoice.number}?`
                        : `Cancel invoice ${invoice.number}?`
                }
                description={
                    isDraft
                        ? 'The draft and its lines are removed. This can’t be undone.'
                        : 'A cancellation credit note zeroes the balance and the client can no longer pay it. Billed periods can be invoiced again.'
                }
                confirmLabel={isDraft ? 'Delete draft' : 'Cancel invoice'}
                action={isDraft ? destroy(invoice.id) : cancel(invoice.id)}
            />

            <FormDialog
                open={dialog === 'cancel' && notes_enabled && !isDraft}
                onOpenChange={close}
                title={`Cancel invoice ${invoice.number}?`}
                description="A credit note for the open balance is issued and the client can no longer pay the invoice. The reason is printed on the credit note and the client sees it."
                submitLabel="Cancel invoice"
                destructive
                action={cancel(invoice.id)}
                initial={{ note: '' }}
            >
                {(form) => (
                    <Field
                        className="content-start"
                        htmlFor="cancel-note"
                        label="Reason"
                        error={form.errors.note}
                    >
                        <Textarea
                            id="cancel-note"
                            required
                            maxLength={500}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </Field>
                )}
            </FormDialog>

            <FormDialog
                open={dialog === 'cancelLines'}
                onOpenChange={close}
                title="Cancel the selected lines?"
                description="A credit note for the selected lines is issued. The lines stay visible, struck through. The reason is printed on the credit note and the client sees it."
                submitLabel="Cancel lines"
                destructive
                action={cancelLines(invoice.id)}
                initial={{ note: '' }}
                transform={(data) => ({ ...data, items: selectedLines })}
            >
                {(form) => (
                    <Field
                        className="content-start"
                        htmlFor="cancel-lines-note"
                        label="Reason"
                        error={form.errors.note}
                    >
                        <Textarea
                            id="cancel-lines-note"
                            required
                            maxLength={500}
                            value={form.data.note}
                            onChange={(event) =>
                                form.setData('note', event.target.value)
                            }
                        />
                    </Field>
                )}
            </FormDialog>

            <ConfirmDialog
                open={dialog === 'cancelLinesPlain'}
                onOpenChange={close}
                title="Cancel the selected lines?"
                description="A credit note for the selected lines is added to this invoice. The lines stay visible, struck through."
                confirmLabel="Cancel lines"
                onConfirm={() =>
                    router.post(
                        cancelLines.url(invoice.id),
                        { items: selectedLines },
                        {
                            preserveScroll: true,
                            onSuccess: () => setSelectedLines([]),
                        },
                    )
                }
            />

            <ConfirmDialog
                open={dialog === 'markPaid'}
                onOpenChange={close}
                title={`Mark ${invoice.number} as paid?`}
                description={
                    <>
                        The remaining{' '}
                        <Money
                            amount={invoice.balance}
                            currency={invoice.currency}
                        />{' '}
                        is written off — no income is recorded.
                    </>
                }
                confirmLabel="Mark paid"
                destructive={false}
                action={markPaid(invoice.id)}
            />
        </>
    );
}
