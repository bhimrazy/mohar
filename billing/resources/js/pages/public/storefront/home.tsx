import { Link, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    BookOpen,
    Eye,
    LifeBuoy,
    Mail,
    Megaphone,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { DateTime } from '@/components/app/date';
import { Money } from '@/components/app/money';
import {
    DomainSearch,
    FromPriceText,
    PaymentMarks,
    PlanCard,
    TaxNote,
} from '@/components/storefront/pricing';
import { SiteHead, WHY_US_ICONS } from '@/components/storefront/site-head';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cycleLabel } from '@/lib/billing-cycles';
import { cn } from '@/lib/utils';
import type { DomainPrice, FromPrice, PageSeo, Plan } from '@/types/storefront';

type FeaturedGroup = {
    id: number;
    name: string;
    slug: string;
    tagline: string | null;
    url: string;
    default_cycle: string | null;
    plans: Plan[];
};

type Props = {
    hero: {
        eyebrow: string;
        headline: string;
        subtext: string;
        domain_search: boolean;
        plan: { group: string; compare_url: string; plan: Plan } | null;
    };
    domains: {
        featured: DomainPrice[];
        search_url: string | null;
        transfer_url: string | null;
        pricing_url: string;
    };
    sections: string[];
    why_us: { icon: string; title: string; text: string }[];
    featured_plans: FeaturedGroup[];
    more_products: {
        name: string;
        tagline: string | null;
        url: string | null;
        from: FromPrice | null;
        count: number;
    }[];
    news: {
        announcements: {
            title: string;
            excerpt: string | null;
            url: string;
            published_at: string;
        }[];
        articles: { title: string; url: string }[];
        status:
            | {
                  title: string;
                  status: string;
                  open: boolean;
                  starts_at: string;
                  ends_at: string | null;
              }[]
            | null;
        announcements_url: string | null;
        kb_url: string | null;
        status_url: string | null;
    } | null;
    tax_note: string | null;
    currency: string;
    preview: boolean;
    seo: PageSeo;
};

const shell = 'mx-auto w-full max-w-6xl px-4 md:px-6';

/** Public home page, direction A "Storefront" (docs/design/home/home-page.md). */
export default function Home(props: Props) {
    const { hero, sections, currency } = props;
    const heroPlanGroup = props.featured_plans.find((group) =>
        group.plans.some((plan) => plan.id === hero.plan?.plan.id),
    );

    return (
        <>
            <SiteHead seo={props.seo} />
            {props.preview && (
                <div
                    role="status"
                    className="sticky top-16 z-20 flex items-center justify-center gap-2 border-b bg-warning-subtle px-4 py-2 text-sm font-medium text-warning-text"
                >
                    <Eye className="size-4" aria-hidden="true" />
                    Preview of your draft, not live. Visitors still see the
                    published version.
                </div>
            )}

            <section className="border-b bg-gradient-to-b from-muted/60 to-background">
                <div
                    className={cn(
                        shell,
                        'grid gap-10 py-12 md:py-16',
                        hero.plan &&
                            'lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]',
                    )}
                >
                    <div className="min-w-0 space-y-6">
                        {hero.eyebrow && (
                            <p className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                {hero.eyebrow}
                            </p>
                        )}
                        <h1 className="max-w-2xl font-display text-4xl font-semibold tracking-tight text-balance md:text-5xl">
                            {hero.headline}
                        </h1>
                        {hero.subtext && (
                            <p className="max-w-xl text-lg text-muted-foreground">
                                {hero.subtext}
                            </p>
                        )}
                        {hero.domain_search && props.domains.search_url && (
                            <div className="space-y-3">
                                <DomainSearch
                                    action={props.domains.search_url}
                                    className="max-w-xl"
                                />
                                <DomainTiles
                                    domains={props.domains.featured}
                                    currency={currency}
                                    pricingUrl={props.domains.pricing_url}
                                />
                            </div>
                        )}
                        <div className="flex flex-wrap gap-3">
                            {props.featured_plans.length > 0 && (
                                <Button asChild variant="outline">
                                    <a href="#plans">See hosting plans</a>
                                </Button>
                            )}
                            {props.domains.transfer_url && (
                                <Button asChild variant="ghost">
                                    <Link href={props.domains.transfer_url}>
                                        Transfer a domain
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                    {hero.plan && (
                        <div className="space-y-3 lg:pt-6">
                            <PlanCard
                                plan={hero.plan.plan}
                                cycle={
                                    heroPlanGroup?.default_cycle ??
                                    hero.plan.plan.pricing[0]?.cycle ??
                                    null
                                }
                                currency={currency}
                                highlighted
                                eyebrow={hero.plan.group}
                            />
                            <TaxNote note={props.tax_note} />
                            <Link
                                href={hero.plan.compare_url}
                                className="flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                            >
                                Compare all {hero.plan.group} plans
                                <ArrowRight
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                        </div>
                    )}
                </div>
            </section>

            {sections.map((key) => {
                switch (key) {
                    case 'why_us':
                        return <WhyUs key={key} items={props.why_us} />;
                    case 'featured_plans':
                        return (
                            <FeaturedPlans
                                key={key}
                                groups={props.featured_plans}
                                currency={currency}
                                taxNote={props.tax_note}
                            />
                        );
                    case 'more_products':
                        return (
                            <MoreProducts
                                key={key}
                                items={props.more_products}
                                currency={currency}
                                taxNote={props.tax_note}
                            />
                        );
                    case 'help':
                        return <Help key={key} />;
                    case 'news':
                        return (
                            props.news && <News key={key} news={props.news} />
                        );
                    case 'payments':
                        return <WaysToPay key={key} currency={currency} />;
                    default:
                        return null;
                }
            })}
        </>
    );
}

function DomainTiles({
    domains,
    currency,
    pricingUrl,
}: {
    domains: DomainPrice[];
    currency: string;
    pricingUrl: string;
}) {
    return (
        <ul className="grid max-w-xl grid-cols-2 gap-2 sm:grid-cols-3">
            {domains.map((domain) => (
                <li
                    key={domain.tld}
                    className="rounded-lg border bg-card p-3 text-sm"
                >
                    <p className="flex items-center gap-1.5 font-semibold">
                        {domain.tld}
                        {domain.badge && (
                            <Badge variant="secondary" className="capitalize">
                                {domain.badge}
                            </Badge>
                        )}
                    </p>
                    <p>
                        <Money
                            amount={domain.register}
                            currency={currency}
                            className="font-medium"
                        />
                        <span className="text-muted-foreground">/yr</span>
                    </p>
                    {domain.renew !== null && (
                        <p className="text-xs text-muted-foreground">
                            Renews at{' '}
                            <Money amount={domain.renew} currency={currency} />
                        </p>
                    )}
                </li>
            ))}
            <li className="rounded-lg border border-dashed p-3 text-sm">
                <Link
                    href={pricingUrl}
                    className="flex items-center gap-1 font-medium text-primary hover:underline"
                >
                    All domain prices
                    <ArrowRight className="size-4" aria-hidden="true" />
                </Link>
                <p className="text-xs text-muted-foreground">
                    Register, renew and transfer
                </p>
            </li>
        </ul>
    );
}

function SectionHeading({
    title,
    description,
    children,
}: {
    title: string;
    description?: React.ReactNode;
    children?: React.ReactNode;
}) {
    return (
        <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div className="space-y-1">
                <h2 className="font-display text-2xl font-semibold tracking-tight">
                    {title}
                </h2>
                {description && (
                    <div className="text-muted-foreground">{description}</div>
                )}
            </div>
            {children}
        </div>
    );
}

function WhyUs({ items }: { items: Props['why_us'] }) {
    return (
        <section aria-label="Why choose us" className="border-b">
            <ul
                className={cn(
                    shell,
                    'grid gap-6 py-8 sm:grid-cols-2 lg:grid-cols-4',
                )}
            >
                {items.map((item) => {
                    const Icon = WHY_US_ICONS[item.icon] ?? WHY_US_ICONS.shield;

                    return (
                        <li key={item.title} className="flex gap-3">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-card">
                                <Icon
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                            </span>
                            <span>
                                <span className="block font-medium">
                                    {item.title}
                                </span>
                                <span className="block text-sm text-muted-foreground">
                                    {item.text}
                                </span>
                            </span>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

function FeaturedPlans({
    groups,
    currency,
    taxNote,
}: {
    groups: FeaturedGroup[];
    currency: string;
    taxNote: string | null;
}) {
    return (
        <section id="plans" className="scroll-mt-20 py-14">
            <div className={cn(shell, 'space-y-14')}>
                {groups.map((group) => (
                    <PlanGroup
                        key={group.id}
                        group={group}
                        currency={currency}
                        taxNote={taxNote}
                    />
                ))}
            </div>
        </section>
    );
}

function PlanGroup({
    group,
    currency,
    taxNote,
}: {
    group: FeaturedGroup;
    currency: string;
    taxNote: string | null;
}) {
    const cycles = Array.from(
        new Set(
            group.plans.flatMap((plan) => plan.pricing.map((row) => row.cycle)),
        ),
    );
    const [cycle, setCycle] = useState(group.default_cycle);

    return (
        <div>
            <SectionHeading
                title={`${group.name} plans`}
                description={
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        {group.tagline}
                        <TaxNote note={taxNote} />
                    </span>
                }
            >
                {cycles.length > 1 && (
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={cycle ?? undefined}
                        onValueChange={(value) => value && setCycle(value)}
                        aria-label="Billing cycle"
                        className="flex-wrap bg-card"
                    >
                        {cycles.map((value) => (
                            <ToggleGroupItem
                                key={value}
                                value={value}
                                className="h-10 px-3"
                            >
                                {cycleLabel(value)}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                )}
            </SectionHeading>
            <div className="grid gap-6 pt-2 md:grid-cols-2 lg:grid-cols-3">
                {group.plans.map((plan) => (
                    <PlanCard
                        key={plan.id}
                        plan={plan}
                        cycle={cycle}
                        currency={currency}
                        highlighted={plan.featured}
                    />
                ))}
            </div>
            <Link
                href={group.url}
                className="mt-6 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
            >
                Compare {group.name} plans
                <ArrowRight className="size-4" aria-hidden="true" />
            </Link>
        </div>
    );
}

function MoreProducts({
    items,
    currency,
    taxNote,
}: {
    items: Props['more_products'];
    currency: string;
    taxNote: string | null;
}) {
    const { site } = usePage().props;

    return (
        <section className="border-y bg-muted/40 py-14">
            <div className={shell}>
                <SectionHeading
                    title={`More from ${site?.company.name ?? ''}`.trim()}
                    description={<TaxNote note={taxNote} />}
                />
                <ul className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {items.map((item) => (
                        <li key={item.name}>
                            <Link
                                href={item.url ?? '#'}
                                className="flex h-full flex-col gap-2 rounded-xl border bg-card p-5 elevation-card transition-colors hover:border-primary focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <span className="font-semibold">
                                    {item.name}
                                </span>
                                {item.tagline && (
                                    <span className="text-sm text-muted-foreground">
                                        {item.tagline}
                                    </span>
                                )}
                                <span className="mt-auto flex flex-wrap items-baseline gap-x-2 pt-2">
                                    {item.from && (
                                        <FromPriceText
                                            from={item.from}
                                            currency={currency}
                                        />
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {item.count}{' '}
                                        {item.name === 'Domains'
                                            ? item.count === 1
                                                ? 'extension'
                                                : 'extensions'
                                            : item.count === 1
                                              ? 'plan'
                                              : 'plans'}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

const HELP_TILES: Record<string, { icon: LucideIcon; description: string }> = {
    'Knowledge base': {
        icon: BookOpen,
        description: 'Guides for email, DNS, billing and more.',
    },
    Announcements: {
        icon: Megaphone,
        description: 'News, new features and planned work.',
    },
    'Network status': {
        icon: Activity,
        description: 'Live service status and maintenance.',
    },
    'Open a ticket': {
        icon: LifeBuoy,
        description: 'Ask our team about anything.',
    },
};

function Help() {
    const { site } = usePage().props;

    if (!site) {
        return null;
    }

    const email = site.company.email;

    return (
        <section className="py-14">
            <div className={shell}>
                <SectionHeading
                    title="How can we help?"
                    description="Answers, news and a way to reach us."
                />
                <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                    {[
                        ...site.support.map((link) => ({
                            ...link,
                            ...HELP_TILES[link.label],
                            href: link.url,
                        })),
                        ...(email
                            ? [
                                  {
                                      label: 'Contact us',
                                      badge: null,
                                      icon: Mail,
                                      description: 'Questions before you buy.',
                                      href: `mailto:${email}`,
                                  },
                              ]
                            : []),
                    ].map((tile) => {
                        const Icon = tile.icon ?? LifeBuoy;

                        return (
                            <li key={tile.label}>
                                <a
                                    href={tile.href}
                                    className="flex h-full flex-col gap-2 rounded-xl border bg-card p-4 elevation-card transition-colors hover:border-primary focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                >
                                    <Icon
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <span className="font-medium">
                                        {tile.label}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {tile.description}
                                    </span>
                                    {tile.badge && (
                                        <Badge
                                            variant="secondary"
                                            className="w-fit bg-warning-subtle text-warning-text"
                                        >
                                            {tile.badge} open{' '}
                                            {tile.badge === 1
                                                ? 'issue'
                                                : 'issues'}
                                        </Badge>
                                    )}
                                </a>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </section>
    );
}

function NewsCard({
    title,
    href,
    children,
}: {
    title: string;
    href: string | null;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-4 rounded-xl border bg-card p-5 elevation-card">
            <div className="flex items-center justify-between gap-2">
                <h3 className="font-semibold">{title}</h3>
                {href && (
                    <Link
                        href={href}
                        className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                    >
                        See all
                        <ArrowRight className="size-4" aria-hidden="true" />
                    </Link>
                )}
            </div>
            {children}
        </div>
    );
}

function News({ news }: { news: NonNullable<Props['news']> }) {
    return (
        <section className="pb-14">
            <div className={cn(shell, 'grid gap-4 lg:grid-cols-3')}>
                {news.announcements.length > 0 && (
                    <NewsCard
                        title="Announcements"
                        href={news.announcements_url}
                    >
                        <ul className="divide-y">
                            {news.announcements.map((item) => (
                                <li
                                    key={item.url}
                                    className="space-y-1 py-3 first:pt-0 last:pb-0"
                                >
                                    <DateTime
                                        value={item.published_at}
                                        className="text-xs text-muted-foreground"
                                    />
                                    <Link
                                        href={item.url}
                                        className="block font-medium hover:underline"
                                    >
                                        {item.title}
                                    </Link>
                                    {item.excerpt && (
                                        <p className="line-clamp-2 text-sm text-muted-foreground">
                                            {item.excerpt}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </NewsCard>
                )}
                {news.articles.length > 0 && (
                    <NewsCard title="Popular articles" href={news.kb_url}>
                        <ul className="space-y-3 text-sm">
                            {news.articles.map((article) => (
                                <li
                                    key={article.url}
                                    className="flex items-start gap-2"
                                >
                                    <BookOpen
                                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <Link
                                        href={article.url}
                                        className="hover:underline"
                                    >
                                        {article.title}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </NewsCard>
                )}
                {news.status && (
                    <NewsCard title="Network status" href={news.status_url}>
                        {news.status.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                All services are running normally.
                            </p>
                        ) : (
                            <ul className="space-y-3 text-sm">
                                {news.status.map((incident) => (
                                    <li
                                        key={`${incident.title}-${incident.starts_at}`}
                                        className="flex gap-2"
                                    >
                                        <span
                                            aria-hidden="true"
                                            className={cn(
                                                'mt-1.5 size-2 shrink-0 rounded-full',
                                                incident.open
                                                    ? 'bg-warning'
                                                    : incident.status ===
                                                        'scheduled'
                                                      ? 'bg-info'
                                                      : 'bg-success',
                                            )}
                                        />
                                        <span>
                                            <span className="block font-medium">
                                                {incident.title}
                                            </span>
                                            <span className="text-xs text-muted-foreground capitalize">
                                                {incident.status} ·{' '}
                                                <DateTime
                                                    value={
                                                        incident.status ===
                                                        'resolved'
                                                            ? (incident.ends_at ??
                                                              incident.starts_at)
                                                            : incident.starts_at
                                                    }
                                                />
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </NewsCard>
                )}
            </div>
        </section>
    );
}

function WaysToPay({ currency }: { currency: string }) {
    const { site } = usePage().props;

    if (!site || site.payments.length === 0) {
        return null;
    }

    return (
        <section className="border-t bg-muted/40 py-10">
            <div
                className={cn(
                    shell,
                    'flex flex-col gap-4 md:flex-row md:items-center md:justify-between',
                )}
            >
                <div>
                    <h2 className="font-display text-xl font-semibold">
                        Ways to pay
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Payment methods for prices in {currency}.
                    </p>
                </div>
                <PaymentMarks marks={site.payments} />
            </div>
        </section>
    );
}
