import { router } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Field } from '@/components/app/field';
import { StatusBadge, toneSurface } from '@/components/app/status-badge';
import { FormDialog } from '@/components/billing/form-dialog';
import type { GatewayOption, Transaction } from '@/components/billing/types';
import { humanize } from '@/components/billing/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';
import {
    checkRefund,
    resolveRefund,
} from '@/actions/App/Modules/Billing/Http/Controllers/Admin/TransactionController';

/** The label and tone a transaction's status is shown with: a gateway refund is "Being confirmed" until settled. */
export function transactionStatus(txn: Transaction): {
    status: string;
    label: string;
    tone?: 'warning' | 'info' | 'neutral';
} {
    if (txn.type === 'refund') {
        if (txn.status === 'pending') {
            return {
                status: 'pending',
                label: 'Being confirmed',
                tone: 'warning',
            };
        }

        if (txn.status === 'failed') {
            return { status: 'released', label: 'Released', tone: 'neutral' };
        }

        if (txn.status === 'succeeded') {
            return { status: 'refunded', label: 'Refunded', tone: 'info' };
        }
    }

    return {
        status: txn.status === 'succeeded' ? 'completed' : txn.status,
        label: humanize(txn.status),
    };
}

export function TransactionStatusBadge({ txn }: { txn: Transaction }) {
    const { status, label, tone } = transactionStatus(txn);

    return <StatusBadge status={status} label={label} tone={tone} />;
}

/** A released refund never happened: shown muted and struck through so it can't be read as money out. */
export const releasedClass = (txn: Transaction) =>
    txn.type === 'refund' && txn.status === 'failed'
        ? 'text-muted-foreground line-through opacity-70'
        : undefined;

function CheckRefundButton({ refund }: { refund: Transaction }) {
    const [checking, setChecking] = useState(false);

    return (
        <Button
            variant="outline"
            size="sm"
            disabled={checking}
            onClick={() =>
                router.post(
                    checkRefund.url(refund.id),
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setChecking(true),
                        onFinish: () => setChecking(false),
                    },
                )
            }
        >
            {checking && <Spinner />}
            {checking ? 'Checking…' : 'Check refund'}
        </Button>
    );
}

/**
 * What staff can do with a gateway refund the provider has not confirmed. A provider that can be asked gets "Check
 * refund"; one that can't gets the two hand-settling choices, each with when to use it.
 */
function PendingRefundRow({
    refund,
    gateways,
    canAct,
}: {
    refund: Transaction;
    gateways: GatewayOption[];
    canAct: boolean;
}) {
    const gateway = gateways.find((option) => option.value === refund.gateway);
    const name = gateway?.label ?? humanize(refund.gateway);
    const amount = formatMoney(refund.amount_out, refund.currency);
    const canAsk = gateway?.refund_lookup ?? false;

    return (
        <div className="grid gap-2">
            <p className="font-medium">
                A {amount} refund is waiting for {name}.
            </p>
            {!canAct || !refund.can_resolve ? (
                <p className="text-sm">
                    {canAct
                        ? `The request to ${name} is still running. Actions appear here if it doesn't answer.`
                        : `Staff with refund access can settle it.`}
                </p>
            ) : canAsk ? (
                <>
                    <div className="flex flex-wrap gap-2">
                        <CheckRefundButton refund={refund} />
                    </div>
                    <p className="text-sm">
                        Check refund asks {name} now whether it made this
                        refund.
                    </p>
                </>
            ) : (
                <>
                    <div className="flex flex-wrap gap-2">
                        <FormDialog
                            trigger={
                                <Button variant="outline" size="sm">
                                    Confirmed at the provider
                                </Button>
                            }
                            title={`Record the ${amount} refund as made?`}
                            description={`Only after you saw this refund in the ${name} portal. It is booked as money out, and can't be undone.`}
                            submitLabel="Record refund"
                            action={resolveRefund(refund.id)}
                            initial={{
                                outcome: 'confirmed',
                                provider_reference: '',
                            }}
                        >
                            {(form) => (
                                <Field
                                    htmlFor={`refund-ref-${refund.id}`}
                                    label={`${name} refund reference`}
                                    hint="The reference or transaction ID the portal shows for this refund."
                                    error={form.errors.provider_reference}
                                >
                                    <Input
                                        id={`refund-ref-${refund.id}`}
                                        value={form.data.provider_reference}
                                        onChange={(event) =>
                                            form.setData(
                                                'provider_reference',
                                                event.target.value,
                                            )
                                        }
                                        required
                                    />
                                </Field>
                            )}
                        </FormDialog>
                        <ConfirmDialog
                            trigger={
                                <Button variant="outline" size="sm">
                                    Not refunded
                                </Button>
                            }
                            title={`Release this ${amount} refund?`}
                            description={`Use only after you checked the ${name} portal and found no refund of ${amount}. The amount becomes refundable again, so it can be refunded a second time. ${name} won't tell us if it refunds it later, so a wrong release can mean paying the client twice.`}
                            confirmLabel="Release refund"
                            method="post"
                            action={resolveRefund(refund.id)}
                            data={{ outcome: 'not_refunded' }}
                        />
                    </div>
                    <p className="text-sm">
                        {name} can't be asked. Look for the refund in its
                        portal: <strong>Confirmed</strong> if it is there,{' '}
                        <strong>Not refunded</strong> if it is not.
                    </p>
                </>
            )}
        </div>
    );
}

/** Shown above an invoice's transactions while a gateway refund waits for the provider. */
export function PendingRefundBanner({
    transactions,
    gateways,
    canAct,
}: {
    transactions: Transaction[];
    gateways: GatewayOption[];
    canAct: boolean;
}) {
    const pending = transactions.filter(
        (txn) => txn.type === 'refund' && txn.status === 'pending',
    );

    if (pending.length === 0) {
        return null;
    }

    return (
        <div
            role="status"
            className={cn(
                'grid gap-4 rounded-lg border p-4',
                toneSurface('warning'),
            )}
        >
            <div className="flex items-start gap-3">
                <Clock aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                <div className="grid gap-4">
                    <p className="text-sm font-medium">
                        Don't refund again until this is settled. The amount
                        stays held back.
                    </p>
                    {pending.map((refund) => (
                        <PendingRefundRow
                            key={refund.id}
                            refund={refund}
                            gateways={gateways}
                            canAct={canAct}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}
