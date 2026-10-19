import { Link } from '@inertiajs/react';
import { Check, Globe, Info, Landmark, Search, Star } from 'lucide-react';
import { Money } from '@/components/app/money';
import { GatewayLogo } from '@/components/billing/gateway-logo';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cycleLabel, cycleMonths, cycleSuffix } from '@/lib/billing-cycles';
import { cn } from '@/lib/utils';
import type { FromPrice, PaymentMark, Plan } from '@/types/storefront';

const BILLED: Record<string, string> = {
    monthly: 'monthly',
    quarterly: 'every 3 months',
    semiannually: 'every 6 months',
    annually: 'annually',
    biennially: 'every 2 years',
    triennially: 'every 3 years',
    onetime: 'once',
};

/** "every 2 years": how often the amount is charged, for "Billed …" and "Renews at …". */
export function billedPhrase(cycle: string): string {
    return BILLED[cycle] ?? cycleLabel(cycle).toLowerCase();
}

/** Percentage saved against paying monthly for the same period; 0 when not cheaper. */
export function savingAgainstMonthly(plan: Plan, cycle: string): number {
    const monthly = plan.pricing.find((row) => row.cycle === 'monthly');
    const row = plan.pricing.find((candidate) => candidate.cycle === cycle);
    const months = cycleMonths(cycle);

    if (!monthly || !row || !months || months === 1 || !monthly.recurring) {
        return 0;
    }

    return Math.max(
        0,
        Math.round((1 - row.recurring / (monthly.recurring * months)) * 100),
    );
}

/**
 * The period after a price: a short visible form ("/mo", "/yr") and the words a screen reader says ("per month",
 * "annually"), so prices read with their period (spec §8).
 */
export function PeriodSuffix({
    cycle,
    perMonth = false,
}: {
    cycle: string;
    perMonth?: boolean;
}) {
    if (perMonth || cycle === 'monthly') {
        return (
            <>
                <span aria-hidden="true">/mo</span>
                <span className="sr-only"> per month</span>
            </>
        );
    }

    if (cycle === 'onetime') {
        return <> one-time</>;
    }

    if (!cycleSuffix(cycle)) {
        return null;
    }

    return (
        <>
            <span aria-hidden="true">{cycleSuffix(cycle)}</span>
            <span className="sr-only"> {billedPhrase(cycle)}</span>
        </>
    );
}

/** "From Rs 485.38/mo" with the monthly breakdown on, else "From Rs 6,189.00/yr" or "From Rs 4,409.00 one-time". */
export function FromPriceText({
    from,
    currency,
    className,
}: {
    from: FromPrice;
    currency: string;
    className?: string;
}) {
    return (
        <span className={cn('text-sm text-muted-foreground', className)}>
            {from.cycle !== 'free' && 'From '}
            <Money
                amount={from.amount}
                currency={currency}
                className="font-semibold text-foreground"
            />
            <PeriodSuffix cycle={from.cycle} perMonth={from.per_month} />
        </span>
    );
}

export function TaxNote({
    note,
    className,
}: {
    note: string | null;
    className?: string;
}) {
    if (!note) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-xs text-muted-foreground',
                className,
            )}
        >
            <Info className="size-3.5 shrink-0" aria-hidden="true" />
            {note}
        </span>
    );
}

/**
 * A plan's price for one cycle (spec §5.2): the per-month price, the amount billed, the renewal price and any setup
 * fee, all on the card; nothing struck through without a promotion.
 */
export function PlanCard({
    plan,
    cycle,
    currency,
    highlighted = false,
    eyebrow,
    className,
}: {
    plan: Plan;
    cycle: string | null;
    currency: string;
    highlighted?: boolean;
    eyebrow?: string;
    className?: string;
}) {
    const row = plan.pricing.find((candidate) => candidate.cycle === cycle);
    const saving = row ? savingAgainstMonthly(plan, row.cycle) : 0;
    const perMonth =
        row && row.monthly !== null && row.cycle !== 'monthly'
            ? row.monthly
            : null;

    return (
        <article
            aria-labelledby={`plan-${plan.id}`}
            className={cn(
                'relative flex flex-col rounded-xl border bg-card p-5 text-card-foreground elevation-card sm:p-6',
                highlighted && 'border-primary ring-1 ring-primary',
                className,
            )}
        >
            {plan.featured && (
                <Badge className="absolute -top-2.5 left-5 gap-1">
                    <Star className="size-3" aria-hidden="true" />
                    Most popular
                </Badge>
            )}
            {eyebrow && (
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {eyebrow}
                </p>
            )}
            <h3 id={`plan-${plan.id}`} className="text-lg font-semibold">
                {plan.name}
            </h3>
            {plan.tagline && (
                <p className="mt-1 text-sm text-muted-foreground">
                    {plan.tagline}
                </p>
            )}

            <div className="mt-4 min-h-24 space-y-1 text-sm">
                {row ? (
                    <>
                        <p className="flex flex-wrap items-baseline gap-x-1">
                            <Money
                                amount={perMonth ?? row.recurring}
                                currency={currency}
                                className="text-3xl font-semibold tracking-tight"
                            />
                            <span className="text-muted-foreground">
                                <PeriodSuffix
                                    cycle={row.cycle}
                                    perMonth={perMonth !== null}
                                />
                            </span>
                        </p>
                        {perMonth !== null && (
                            <p className="text-muted-foreground">
                                Billed{' '}
                                <Money
                                    amount={row.recurring}
                                    currency={currency}
                                />{' '}
                                {billedPhrase(row.cycle)}
                                {saving > 0 && (
                                    <Badge
                                        variant="secondary"
                                        className="ml-1.5 bg-success-subtle text-success-text"
                                    >
                                        Save {saving}%
                                    </Badge>
                                )}
                            </p>
                        )}
                        {row.cycle !== 'onetime' && row.cycle !== 'free' && (
                            <p className="text-muted-foreground">
                                Renews at{' '}
                                <Money
                                    amount={row.recurring}
                                    currency={currency}
                                />{' '}
                                {billedPhrase(row.cycle)}
                            </p>
                        )}
                        {row.setup > 0 && (
                            <p className="text-muted-foreground">
                                Setup fee{' '}
                                <Money amount={row.setup} currency={currency} />
                            </p>
                        )}
                    </>
                ) : (
                    <p className="text-muted-foreground">
                        Not offered {billedPhrase(cycle ?? '')}. Available{' '}
                        {plan.pricing
                            .map((price) => billedPhrase(price.cycle))
                            .join(', ')}
                        .
                    </p>
                )}
            </div>

            {plan.features.length > 0 && (
                <ul className="mt-4 grid gap-1.5 text-sm">
                    {plan.features.map((feature) => (
                        <li key={feature} className="flex items-start gap-2">
                            <Check
                                className="mt-0.5 size-4 shrink-0 text-success-text"
                                aria-hidden="true"
                            />
                            {feature}
                        </li>
                    ))}
                </ul>
            )}

            <div className="mt-auto pt-6">
                {plan.in_stock ? (
                    <Button
                        asChild
                        className="w-full"
                        variant={highlighted ? 'default' : 'outline'}
                    >
                        <Link href={plan.url}>Configure and order</Link>
                    </Button>
                ) : (
                    <Button className="w-full" variant="secondary" disabled>
                        Out of stock
                    </Button>
                )}
            </div>
        </article>
    );
}

/**
 * Accepted payment methods: the driver's official logo (unmodified, with its dark variant), or the name as text.
 */
export function PaymentMarks({
    marks,
    className,
}: {
    marks: PaymentMark[];
    className?: string;
}) {
    return (
        <ul className={cn('flex flex-wrap items-center gap-2', className)}>
            {marks.map((mark) => (
                <li
                    key={mark.key}
                    className="flex h-10 items-center gap-2 rounded-lg border bg-card px-3 text-sm font-medium"
                >
                    {mark.logo ? (
                        <GatewayLogo
                            name={mark.label}
                            logo={mark.logo}
                            className="h-7 max-w-24"
                        />
                    ) : (
                        <>
                            <Landmark
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {mark.label}
                        </>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** Domain search into the store's domain page. */
export function DomainSearch({
    action,
    className,
}: {
    action: string;
    className?: string;
}) {
    return (
        <form
            action={action}
            method="get"
            role="search"
            className={cn(
                'flex w-full flex-col gap-2 rounded-xl border bg-card p-2 elevation-card focus-within:ring-[3px] focus-within:ring-ring/50 sm:flex-row',
                className,
            )}
        >
            <div className="relative flex-1">
                <Globe
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    name="domain"
                    placeholder="Find your domain, e.g. mybusiness.com"
                    aria-label="Domain name"
                    className="h-11 border-0 bg-transparent pl-9 shadow-none focus-visible:ring-0"
                />
            </div>
            <Button type="submit" size="lg" className="h-11">
                <Search aria-hidden="true" />
                Search domains
            </Button>
        </form>
    );
}
