import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, FileText } from 'lucide-react';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cycleLabel } from '@/lib/billing-cycles';
import { dashboard } from '@/routes';
import store from '@/routes/store';
import type { Order } from '@/types/orders';

type Props = {
    order: Order;
    invoice_url: string | null;
    orders_url: string | null;
};

export default function Complete({
    order,
    invoice_url: invoiceUrl,
    orders_url: ordersUrl,
}: Props) {
    const items = order.items ?? [];

    return (
        <>
            <Head title={`Order ${order.number}`} />

            <div className="mx-auto flex w-full max-w-2xl flex-col gap-8 px-4 py-12 md:px-6 lg:py-16">
                <header className="flex flex-col items-center gap-3 text-center">
                    <span className="flex size-12 items-center justify-center rounded-full bg-primary text-primary-foreground">
                        <CheckCircle2 className="size-6" aria-hidden="true" />
                    </span>
                    <h1 className="text-3xl font-semibold tracking-tight">
                        Thanks, your order is in
                    </h1>
                    <p className="max-w-md text-muted-foreground">
                        Order{' '}
                        <span className="font-medium text-foreground">
                            #{order.number}
                        </span>{' '}
                        was placed on <DateTime value={order.created_at} />.
                        We've emailed you a confirmation and your invoice.
                    </p>
                </header>

                <section
                    aria-labelledby="order-details"
                    className="rounded-xl border bg-card shadow-xs"
                >
                    <div className="flex items-center justify-between gap-4 px-5 pt-5">
                        <h2
                            id="order-details"
                            className="text-base font-semibold"
                        >
                            Order details
                        </h2>
                        <StatusBadge status={order.status} />
                    </div>
                    <ul className="grid gap-3 px-5 py-4 text-sm">
                        {items.map((item) => (
                            <li
                                key={item.id}
                                className="flex justify-between gap-4"
                            >
                                <span className="min-w-0">
                                    <span className="block truncate">
                                        {item.description}
                                    </span>
                                    {item.cycle && (
                                        <span className="text-xs text-muted-foreground">
                                            {cycleLabel(item.cycle)}
                                        </span>
                                    )}
                                </span>
                                <Money
                                    amount={
                                        item.first_amount +
                                        item.setup_fee -
                                        item.discount_amount
                                    }
                                    currency={order.currency}
                                />
                            </li>
                        ))}
                    </ul>
                    <Separator />
                    <dl className="grid gap-2 px-5 py-4 text-sm">
                        {order.discount_total > 0 && (
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">
                                    Discount
                                    {order.promotion_code
                                        ? ` (${order.promotion_code})`
                                        : ''}
                                </dt>
                                <dd>
                                    <Money
                                        amount={-order.discount_total}
                                        currency={order.currency}
                                    />
                                </dd>
                            </div>
                        )}
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">Tax</dt>
                            <dd>
                                <Money
                                    amount={order.tax_total}
                                    currency={order.currency}
                                />
                            </dd>
                        </div>
                        <div className="flex items-baseline justify-between">
                            <dt className="font-medium">Total</dt>
                            <dd className="text-lg font-semibold">
                                <Money
                                    amount={order.total}
                                    currency={order.currency}
                                    showCode
                                />
                            </dd>
                        </div>
                    </dl>
                </section>

                <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
                    {invoiceUrl ? (
                        <Button asChild size="lg">
                            <a href={invoiceUrl}>
                                <FileText /> View and pay invoice
                            </a>
                        </Button>
                    ) : (
                        <Button asChild size="lg">
                            <Link href={dashboard()}>Go to your account</Link>
                        </Button>
                    )}
                    {ordersUrl && (
                        <Button asChild size="lg" variant="outline">
                            <Link href={ordersUrl}>Order history</Link>
                        </Button>
                    )}
                    <Button asChild size="lg" variant="ghost">
                        <Link href={store.index.url()}>Continue shopping</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
