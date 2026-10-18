import type { ReactNode } from 'react';
import { Money } from '@/components/app/money';
import { Separator } from '@/components/ui/separator';
import { cycleLabel } from '@/lib/billing-cycles';
import { cn } from '@/lib/utils';
import type { PricedCart } from '@/types/orders';

function Row({
    label,
    children,
    muted = false,
}: {
    label: ReactNode;
    children: ReactNode;
    muted?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex items-baseline justify-between gap-4 text-sm',
                muted && 'text-muted-foreground',
            )}
        >
            <dt>{label}</dt>
            <dd className="text-right">{children}</dd>
        </div>
    );
}

/**
 * Totals of a priced cart exactly as computed by the server: subtotal,
 * setup fees, discount, taxes, due today and what renews afterwards.
 */
export function OrderSummary({
    cart,
    footer,
    className,
}: {
    cart: PricedCart;
    footer?: ReactNode;
    className?: string;
}) {
    const { currency } = cart;

    return (
        <section
            aria-labelledby="order-summary-title"
            className={cn(
                'rounded-xl border bg-card text-card-foreground shadow-xs',
                className,
            )}
        >
            <div className="px-5 pt-5">
                <h2
                    id="order-summary-title"
                    className="text-base font-semibold"
                >
                    Order summary
                </h2>
            </div>
            <dl className="grid gap-2.5 px-5 py-4">
                <Row label="Subtotal">
                    <Money amount={cart.subtotal} currency={currency} />
                </Row>
                {cart.setup_total > 0 && (
                    <Row label="Includes setup fees" muted>
                        <Money amount={cart.setup_total} currency={currency} />
                    </Row>
                )}
                {cart.discount_total > 0 && (
                    <Row
                        label={
                            cart.promotion?.code
                                ? `Discount (${cart.promotion.code})`
                                : 'Discount'
                        }
                    >
                        <Money
                            amount={-cart.discount_total}
                            currency={currency}
                        />
                    </Row>
                )}
                {cart.taxes.map((tax) => (
                    <Row key={tax.name} label={`${tax.name} (${tax.rate}%)`}>
                        <Money amount={tax.amount} currency={currency} />
                    </Row>
                ))}
                {cart.taxes.length === 0 && (
                    <Row label="Tax" muted>
                        <Money amount={0} currency={currency} />
                    </Row>
                )}
                <Separator className="my-1" />
                <div className="flex items-baseline justify-between gap-4">
                    <dt className="font-medium">Due today</dt>
                    <dd className="text-xl font-semibold tracking-tight">
                        <Money
                            amount={cart.total}
                            currency={currency}
                            showCode
                        />
                    </dd>
                </div>
                {cart.recurring.length > 0 && (
                    <div className="mt-1 rounded-lg bg-muted/50 p-3 text-sm">
                        <p className="mb-1.5 text-xs font-medium text-muted-foreground">
                            Then renews at (before tax)
                        </p>
                        <ul className="grid gap-1">
                            {cart.recurring.map((row) => (
                                <li
                                    key={row.cycle}
                                    className="flex justify-between gap-4"
                                >
                                    <span>{cycleLabel(row.cycle)}</span>
                                    <Money
                                        amount={row.amount}
                                        currency={currency}
                                    />
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </dl>
            {footer && <div className="border-t px-5 py-4">{footer}</div>}
        </section>
    );
}
