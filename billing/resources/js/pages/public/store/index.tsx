import { Form, Link, setLayoutProps } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Check, Globe, Search } from 'lucide-react';
import { Money } from '@/components/app/money';
import { CurrencyUnavailable } from '@/components/orders/currency-unavailable';
import { StorefrontHero } from '@/components/orders/storefront-hero';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    cycleLabel,
    cycleMonths,
    cycleSuffix,
    lowestMonthly,
} from '@/lib/billing-cycles';
import store from '@/routes/store';
import type { PriceRow, StoreGroup, StoreProduct } from '@/types/orders';

type Props = {
    groups: StoreGroup[];
    currency: string;
    /** Other currencies with products to buy; only filled when the store is empty in `currency`. */
    sold_in: string[];
    account_currency: string | null;
    support_url: string | null;
    /** A domain the visitor already owns, carried from the domain search into "Use a domain I own". */
    existingDomain: string | null;
    /** Set on a group page (`/store/{group}`), which lists only this group. */
    group?: { id: number; name: string; slug: string; url: string } | null;
};

/** Percentage saved against paying monthly for the same period (0 when not cheaper). */
function savingFor(rows: PriceRow[], row: PriceRow): number {
    const monthly = rows.find((candidate) => candidate.cycle === 'monthly');
    const months = cycleMonths(row.cycle);

    if (!monthly || !months || months === 1 || monthly.recurring === 0) {
        return 0;
    }

    return Math.max(
        0,
        Math.round((1 - row.recurring / (monthly.recurring * months)) * 100),
    );
}

function ProductCard({
    product,
    currency,
}: {
    product: StoreProduct;
    currency: string;
}) {
    const cheapest = lowestMonthly(product.pricing);
    const setup = product.pricing.find((row) => row.setup > 0);

    return (
        <article className="flex flex-col rounded-xl border bg-card p-6 text-card-foreground shadow-xs">
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-lg font-semibold">{product.name}</h3>
                {!product.in_stock && (
                    <Badge variant="secondary">Out of stock</Badge>
                )}
            </div>
            {product.description && (
                <p className="mt-2 text-sm text-muted-foreground">
                    {product.description}
                </p>
            )}

            <div className="mt-6">
                {cheapest ? (
                    <p className="flex items-baseline gap-1">
                        <span className="text-sm text-muted-foreground">
                            From
                        </span>
                        <Money
                            amount={cheapest.perMonth}
                            currency={currency}
                            className="text-3xl font-semibold tracking-tight"
                        />
                        <span className="text-sm text-muted-foreground">
                            /mo
                        </span>
                    </p>
                ) : (
                    product.pricing[0] && (
                        <Money
                            amount={product.pricing[0].recurring}
                            currency={currency}
                            className="text-3xl font-semibold tracking-tight"
                        />
                    )
                )}
                {cheapest && cheapest.row.cycle !== 'monthly' && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        Billed{' '}
                        <Money
                            amount={cheapest.row.recurring}
                            currency={currency}
                        />{' '}
                        {cycleLabel(cheapest.row.cycle).toLowerCase()}
                    </p>
                )}
            </div>

            <ul className="mt-5 grid gap-1.5 text-sm">
                {product.pricing.map((row) => (
                    <li
                        key={row.cycle}
                        className="flex items-center justify-between gap-3"
                    >
                        <span className="flex min-w-0 items-center gap-2 text-muted-foreground">
                            <Check
                                className="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            {cycleLabel(row.cycle)}
                            {savingFor(product.pricing, row) > 0 && (
                                <span className="shrink-0 rounded-full bg-muted px-1.5 py-0.5 text-[11px] whitespace-nowrap text-foreground">
                                    Save {savingFor(product.pricing, row)}%
                                </span>
                            )}
                        </span>
                        <span className="shrink-0 whitespace-nowrap tabular-nums">
                            <Money amount={row.recurring} currency={currency} />
                            <span className="text-muted-foreground">
                                {cycleSuffix(row.cycle)}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>

            {setup && (
                <p className="mt-3 text-xs text-muted-foreground">
                    One-time setup fee from{' '}
                    <Money amount={setup.setup} currency={currency} />
                </p>
            )}

            <div className="mt-auto pt-6">
                {product.in_stock ? (
                    <Button asChild className="w-full">
                        <Link href={product.url}>Configure and order</Link>
                    </Button>
                ) : (
                    <Button className="w-full" variant="secondary" disabled>
                        Currently unavailable
                    </Button>
                )}
            </div>
        </article>
    );
}

export default function StoreIndex({
    groups,
    currency,
    sold_in: soldIn,
    account_currency: accountCurrency,
    support_url: supportUrl,
    group: currentGroup = null,
}: Props) {
    // Client area: a group page sits under Store (the public layout ignores breadcrumbs).
    setLayoutProps({
        breadcrumbs: currentGroup ? [{ title: 'Store', href: store.index.url() }] : [],
    });

    const visible = groups.filter((group) => group.products.length > 0);

    return (
        <>
            <Head title={currentGroup?.name ?? 'Store'} />

            <StorefrontHero
                eyebrow="Hosting, servers and domains"
                title={currentGroup?.name ?? 'Everything you need to get online'}
                description={
                    (currentGroup && visible[0]?.description) ||
                    "Pick a plan, add a domain and you're live. Clear pricing, no surprises at renewal."
                }
            >
                <Form
                    {...store.domains.form()}
                    className="mt-2 flex w-full max-w-xl flex-col gap-2 sm:flex-row"
                >
                    <div className="relative flex-1">
                        <Globe
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            name="domain"
                            placeholder="Find your domain, e.g. ashatraders.com"
                            aria-label="Domain name"
                            className="h-11 bg-background pl-9"
                        />
                    </div>
                    <Button type="submit" size="lg" className="h-11">
                        <Search aria-hidden="true" />
                        Search domains
                    </Button>
                </Form>
            </StorefrontHero>

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-14 px-4 py-12 md:px-6">
                {visible.length > 1 && (
                    <nav
                        aria-label="Product categories"
                        className="-mt-4 flex flex-wrap gap-2"
                    >
                        {visible.map((group) => (
                            <a
                                key={group.id}
                                href={`#${group.slug}`}
                                className="rounded-full border bg-card px-4 py-1.5 text-sm font-medium transition-colors hover:bg-muted focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                {group.name}
                            </a>
                        ))}
                    </nav>
                )}

                {visible.length === 0 && (
                    <CurrencyUnavailable
                        title={`Nothing is on sale in ${currency} right now`}
                        soldIn={soldIn}
                        accountCurrency={accountCurrency}
                        supportUrl={supportUrl}
                    />
                )}

                {visible.map((group) => (
                    <section
                        key={group.id}
                        id={group.slug}
                        aria-labelledby={`group-${group.id}`}
                        className="scroll-mt-24"
                    >
                        {/* A group page names the group in its title already. */}
                        <div
                            className={
                                currentGroup
                                    ? 'sr-only'
                                    : 'mb-6 max-w-2xl space-y-1'
                            }
                        >
                            <h2
                                id={`group-${group.id}`}
                                className="text-2xl font-semibold tracking-tight"
                            >
                                {group.name}
                            </h2>
                            {group.description && (
                                <p className="text-muted-foreground">
                                    {group.description}
                                </p>
                            )}
                        </div>
                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {group.products.map((product) => (
                                <ProductCard
                                    key={product.id}
                                    product={product}
                                    currency={currency}
                                />
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}
