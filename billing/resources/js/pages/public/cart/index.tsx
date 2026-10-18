import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Globe,
    Pencil,
    ShoppingCart,
    Tag,
    Trash2,
    X,
} from 'lucide-react';
import CartController from '@/actions/App/Modules/Orders/Http/Controllers/Public/CartController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { EmptyState } from '@/components/app/empty-state';
import { Money } from '@/components/app/money';
import InputError from '@/components/input-error';
import { OrderSummary } from '@/components/orders/order-summary';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cycleLabel, cycleSuffix } from '@/lib/billing-cycles';
import { cn } from '@/lib/utils';
import store from '@/routes/store';
import type { PricedCart, PricedItem } from '@/types/orders';

type Props = {
    cart: PricedCart;
};

function itemSubtitle(item: PricedItem): string {
    if (item.kind !== 'product') {
        return `${item.years} ${item.years === 1 ? 'year' : 'years'}`;
    }

    return [cycleLabel(item.cycle), item.domain].filter(Boolean).join(' · ');
}

function CartItemCard({
    item,
    currency,
}: {
    item: PricedItem;
    currency: string;
}) {
    return (
        <li className="p-5">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 space-y-0.5">
                    <h3 className="font-medium">
                        {item.title}
                        {item.kind !== 'product' && item.subtitle && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {item.subtitle}
                            </span>
                        )}
                    </h3>
                    <p className="text-sm text-muted-foreground">
                        {itemSubtitle(item)}
                    </p>
                </div>
                <Money
                    amount={item.due_today}
                    currency={currency}
                    className="font-semibold"
                />
            </div>

            <ul className="mt-3 grid gap-1 text-sm">
                {item.lines.map((line) => (
                    <li key={line.key} className="grid gap-0.5">
                        {line.kind === 'product' &&
                            line.breakdown.length > 1 &&
                            line.breakdown.map((part, index) => (
                                <div
                                    key={part.label}
                                    className={cn(
                                        'flex justify-between gap-4 text-xs text-muted-foreground',
                                        index > 0 && 'pl-3',
                                    )}
                                >
                                    <span className="min-w-0 truncate">
                                        {index === 0 ? 'Base plan' : part.label}
                                    </span>
                                    <Money
                                        amount={part.recurring}
                                        currency={currency}
                                        signed={index > 0}
                                    />
                                </div>
                            ))}
                        <div className="flex justify-between gap-4 text-muted-foreground">
                            <span className="min-w-0 truncate">
                                {line.kind === 'product'
                                    ? line.breakdown.length > 1
                                        ? 'Plan total'
                                        : 'Plan'
                                    : line.description}
                            </span>
                            <span className="tabular-nums">
                                <Money
                                    amount={line.recurring}
                                    currency={currency}
                                />
                                {line.kind !== 'domain' &&
                                    cycleSuffix(line.cycle)}
                            </span>
                        </div>
                        {line.setup > 0 && (
                            <div className="flex justify-between gap-4 text-muted-foreground">
                                <span>Setup fee</span>
                                <Money
                                    amount={line.setup}
                                    currency={currency}
                                />
                            </div>
                        )}
                        {line.discount > 0 && (
                            <div className="flex justify-between gap-4">
                                <span className="flex items-center gap-1.5">
                                    <Tag
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />{' '}
                                    Promotion
                                </span>
                                <Money
                                    amount={-line.discount}
                                    currency={currency}
                                />
                            </div>
                        )}
                    </li>
                ))}
            </ul>

            {item.errors.length > 0 && (
                <div
                    role="alert"
                    className="mt-3 flex gap-2 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                >
                    <AlertTriangle
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <ul>
                        {item.errors.map((error) => (
                            <li key={error}>{error}</li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="mt-4 flex flex-wrap gap-2">
                {item.kind === 'product' && item.product_id !== null && (
                    <Button asChild variant="outline" size="sm">
                        <Link
                            href={store.products.show.url(item.product_id, {
                                query: { item: item.key },
                            })}
                        >
                            <Pencil aria-hidden="true" /> Edit
                        </Link>
                    </Button>
                )}
                <ConfirmDialog
                    trigger={
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground"
                        >
                            <Trash2 aria-hidden="true" /> Remove
                        </Button>
                    }
                    title={`Remove ${item.title} from your cart?`}
                    description="You can add it again from the store at any time."
                    confirmLabel="Remove item"
                    action={CartController.destroy(item.key)}
                />
            </div>
        </li>
    );
}

function PromotionForm({ cart }: { cart: PricedCart }) {
    if (cart.promotion) {
        return (
            <div className="grid gap-1.5">
                <div className="flex items-center justify-between gap-3 rounded-lg border bg-muted/40 px-3 py-2 text-sm">
                    <span className="flex min-w-0 items-center gap-2">
                        <Tag
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span className="truncate font-medium">
                            {cart.promotion.code}
                        </span>
                        {cart.promotion.applied && cart.promotion.summary && (
                            <span className="truncate text-muted-foreground">
                                {cart.promotion.summary}
                            </span>
                        )}
                    </span>
                    <Form
                        {...CartController.removePromotion.form()}
                        options={{ preserveScroll: true }}
                    >
                        <Button
                            type="submit"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            aria-label="Remove promotion code"
                        >
                            <X />
                        </Button>
                    </Form>
                </div>
                {cart.promotion.error && (
                    <InputError message={cart.promotion.error} />
                )}
            </div>
        );
    }

    return (
        <Form
            {...CartController.applyPromotion.form()}
            options={{ preserveScroll: true }}
            className="grid gap-1.5"
        >
            {({ processing, errors }) => (
                <>
                    <label
                        htmlFor="promotion_code"
                        className="text-sm font-medium"
                    >
                        Promotion code
                    </label>
                    <div className="flex gap-2">
                        <Input
                            id="promotion_code"
                            name="promotion_code"
                            placeholder="Enter code"
                            autoComplete="off"
                            className="uppercase placeholder:normal-case"
                            aria-invalid={!!errors.promotion_code}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Apply
                        </Button>
                    </div>
                    <InputError message={errors.promotion_code} />
                </>
            )}
        </Form>
    );
}

export default function CartPage({ cart }: Props) {
    const empty = cart.items.length === 0;

    return (
        <>
            <Head title="Your cart" />

            <div className="mx-auto w-full max-w-6xl px-4 py-8 md:px-6 lg:py-12">
                <h1 className="text-3xl font-semibold tracking-tight">
                    Your cart
                </h1>
                <p className="mt-1 text-muted-foreground">
                    {empty
                        ? 'Nothing here yet.'
                        : `${cart.count} ${cart.count === 1 ? 'item' : 'items'} · prices in ${cart.currency}`}
                </p>

                {empty ? (
                    <EmptyState
                        variant="bordered"
                        icon={ShoppingCart}
                        title="Your cart is empty"
                        description="Browse hosting plans and servers, or search for a domain to get started."
                        action={
                            <div className="flex flex-wrap justify-center gap-2">
                                <Button asChild>
                                    <Link href={store.index.url()}>
                                        Browse the store
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={store.domains.url()}>
                                        <Globe /> Find a domain
                                    </Link>
                                </Button>
                            </div>
                        }
                        className="mt-8"
                    />
                ) : (
                    <div className="mt-8 grid gap-8 lg:grid-cols-[1fr_22rem]">
                        <div className="flex min-w-0 flex-col gap-4">
                            <ul className="divide-y overflow-hidden rounded-xl border bg-card shadow-xs">
                                {cart.items.map((item) => (
                                    <CartItemCard
                                        key={item.key}
                                        item={item}
                                        currency={cart.currency}
                                    />
                                ))}
                            </ul>
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant="outline">
                                    <Link href={store.index.url()}>
                                        Continue shopping
                                    </Link>
                                </Button>
                                <Button asChild variant="ghost">
                                    <Link href={store.domains.url()}>
                                        <Globe /> Add a domain
                                    </Link>
                                </Button>
                            </div>
                        </div>

                        <aside className="flex flex-col gap-4 lg:sticky lg:top-24 lg:self-start">
                            <div className="rounded-xl border bg-card p-5 shadow-xs">
                                <PromotionForm cart={cart} />
                            </div>
                            <OrderSummary
                                cart={cart}
                                footer={
                                    <>
                                        {cart.has_errors ? (
                                            <Button
                                                size="lg"
                                                className="w-full"
                                                disabled
                                            >
                                                Checkout
                                            </Button>
                                        ) : (
                                            <Button
                                                asChild
                                                size="lg"
                                                className="w-full"
                                            >
                                                <Link
                                                    href={store.checkout.url()}
                                                >
                                                    Checkout
                                                </Link>
                                            </Button>
                                        )}
                                        {cart.has_errors && (
                                            <p className="mt-2 text-center text-sm text-muted-foreground">
                                                Fix the items marked above to
                                                continue.
                                            </p>
                                        )}
                                    </>
                                }
                            />
                        </aside>
                    </div>
                )}
            </div>
        </>
    );
}
