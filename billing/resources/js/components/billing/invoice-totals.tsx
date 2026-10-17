import { Money } from '@/components/app/money';
import { cn } from '@/lib/utils';
import type { Invoice, Quote } from './types';
import { humanize } from './types';

function Row({
    label,
    children,
    strong = false,
    muted = false,
}: {
    label: string;
    children: React.ReactNode;
    strong?: boolean;
    muted?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-baseline justify-between gap-4',
                strong && 'border-t pt-2 font-medium',
                muted && 'text-muted-foreground',
            )}
        >
            <dt>{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

/** Subtotal → taxes → total → adjustments → paid → balance, exactly as computed by the server. */
export function InvoiceTotals({ invoice }: { invoice: Invoice }) {
    const currency = invoice.currency;

    return (
        <dl className="space-y-2 text-sm">
            <Row label={invoice.tax_inclusive ? 'Subtotal (net)' : 'Subtotal'}>
                <Money amount={invoice.subtotal} currency={currency} />
            </Row>
            {invoice.taxes.map((tax) => (
                <Row key={tax.name} label={`${tax.name} (${tax.rate}%)`} muted>
                    <Money amount={tax.amount} currency={currency} />
                </Row>
            ))}
            <Row label="Total" strong>
                <Money amount={invoice.total} currency={currency} />
            </Row>
            {(invoice.adjustments ?? []).map((adjustment) => (
                <Row
                    key={adjustment.id}
                    label={
                        adjustment.description ?? humanize(adjustment.reason)
                    }
                    muted
                >
                    <Money
                        amount={adjustment.signed_amount}
                        currency={currency}
                        signed
                    />
                </Row>
            ))}
            {invoice.amount_paid !== 0 && (
                <Row label="Paid" muted>
                    <Money amount={-invoice.amount_paid} currency={currency} />
                </Row>
            )}
            <Row label="Balance due" strong>
                <Money
                    amount={invoice.balance}
                    currency={currency}
                    tone="ledger"
                    className="text-base"
                />
            </Row>
        </dl>
    );
}

export function QuoteTotals({ quote }: { quote: Quote }) {
    return (
        <dl className="space-y-2 text-sm">
            <Row label="Subtotal">
                <Money amount={quote.subtotal} currency={quote.currency} />
            </Row>
            {quote.taxes.map((tax) => (
                <Row key={tax.name} label={`${tax.name} (${tax.rate}%)`} muted>
                    <Money amount={tax.amount} currency={quote.currency} />
                </Row>
            ))}
            <Row label="Total" strong>
                <Money amount={quote.total} currency={quote.currency} />
            </Row>
        </dl>
    );
}
