import { router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { CheckCircle2, Search, XCircle } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { search as searchRoute } from '@/routes/domains';
import { EmptyState } from '@/components/app/empty-state';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type { DomainSearch, LookupResult } from '@/types/domains';

type Props = {
    query: string;
    currency: string;
    currencies: string[];
    canChooseCurrency: boolean;
    search: DomainSearch | null;
};

function statusText(result: LookupResult): string {
    switch (result.status) {
        case 'available':
            return result.sellable ? 'Available' : 'Not sold in this currency';
        case 'registered':
            return result.transferable ? 'Taken — transfer it to us' : 'Taken';
        case 'reserved':
            return 'Reserved by the registry';
        case 'unsupported':
            return "We don't offer this extension";
        case 'self_registered':
            return 'Registered at the registry, not sold here';
        default:
            return 'Availability unknown — try again shortly';
    }
}

function selfRegisteredNote(tld?: string | null): string {
    const free = tld?.endsWith('.np') ? ', free for eligible applicants' : '';

    return `${tld} domains are registered by you at the registry${free}. Once it is approved, set the nameservers shown on your hosting service.`;
}

function ResultRow({
    result,
    currency,
    highlight = false,
}: {
    result: LookupResult;
    currency: string;
    highlight?: boolean;
}) {
    const available = result.status === 'available' && result.sellable;
    const firstTerm = result.register?.[0];

    return (
        <li
            className={`flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 ${highlight ? 'py-5' : ''}`}
        >
            <div className="flex min-w-0 items-start gap-3">
                {available ? (
                    <CheckCircle2
                        className="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400"
                        aria-hidden="true"
                    />
                ) : (
                    <XCircle
                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                )}
                <div className="min-w-0">
                    <p
                        className={`truncate font-medium ${highlight ? 'text-lg' : ''}`}
                    >
                        {result.domain_unicode}
                        {result.badge && (
                            <Badge
                                variant="secondary"
                                className="ml-2 align-middle capitalize"
                            >
                                {result.badge}
                            </Badge>
                        )}
                        {result.is_premium && (
                            <Badge
                                variant="outline"
                                className="ml-2 align-middle"
                            >
                                Premium
                            </Badge>
                        )}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {statusText(result)}
                    </p>
                    {result.level_hint && (
                        <p className="mt-1 max-w-prose text-sm">
                            {result.level_hint}
                        </p>
                    )}
                    {result.self_registered && !result.level_hint && (
                        <p className="mt-1 max-w-prose text-sm">
                            {selfRegisteredNote(result.tld)}
                        </p>
                    )}
                </div>
            </div>
            <div className="flex items-center gap-4 sm:justify-end">
                {available && firstTerm && (
                    <div className="text-right">
                        <p className="font-semibold">
                            <Money
                                amount={firstTerm.price}
                                currency={currency}
                            />
                            <span className="text-sm font-normal text-muted-foreground">
                                {' '}
                                /
                                {firstTerm.years === 1
                                    ? 'yr'
                                    : `${firstTerm.years} yrs`}
                            </span>
                        </p>
                        {result.renew !== null &&
                            result.renew !== undefined && (
                                <p className="text-xs text-muted-foreground">
                                    Renews at{' '}
                                    <Money
                                        amount={result.renew}
                                        currency={currency}
                                    />
                                    /yr
                                </p>
                            )}
                    </div>
                )}
                {!available &&
                    result.transferable &&
                    result.transfer !== null &&
                    result.transfer !== undefined && (
                        <p className="text-right text-sm">
                            Transfer{' '}
                            <Money
                                amount={result.transfer}
                                currency={currency}
                            />
                        </p>
                    )}
                {result.self_registered && (
                    <div className="flex flex-wrap gap-2">
                        {result.registration_url && (
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={result.registration_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    How to register
                                </a>
                            </Button>
                        )}
                        {result.existing_url && (
                            <Button asChild size="sm">
                                <a href={result.existing_url}>
                                    I already have it
                                </a>
                            </Button>
                        )}
                    </div>
                )}
                {result.order_url && (available || result.transferable) && (
                    <Button
                        asChild
                        variant={available ? 'default' : 'outline'}
                        size="sm"
                    >
                        <a href={result.order_url}>
                            {available ? 'Add to cart' : 'Transfer'}
                        </a>
                    </Button>
                )}
            </div>
        </li>
    );
}

export default function DomainSearchPage({
    query,
    currency,
    currencies,
    canChooseCurrency,
    search,
}: Props) {
    const [term, setTerm] = useState(query);
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (term.trim() === '') {
            return;
        }

        router.get(
            searchRoute.url({
                query: {
                    q: term.trim(),
                    ...(canChooseCurrency ? { currency } : {}),
                },
            }),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const changeCurrency = (next: string) =>
        router.get(
            searchRoute.url({
                query: { ...(query ? { q: query } : {}), currency: next },
            }),
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Find a domain" />
            <Page size="narrow">
                <PageHeader
                    title="Find your domain"
                    description="Search for a name to see which extensions are available and what they cost."
                />

                <form
                    onSubmit={submit}
                    role="search"
                    className="flex flex-col gap-2 sm:flex-row"
                >
                    <div className="relative flex-1">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            placeholder="yourbrand or yourbrand.com"
                            aria-label="Domain name"
                            className="h-11 pl-9 text-base"
                            maxLength={255}
                            autoFocus
                            autoCapitalize="none"
                            spellCheck={false}
                        />
                    </div>
                    {canChooseCurrency && currencies.length > 1 && (
                        <Select value={currency} onValueChange={changeCurrency}>
                            <SelectTrigger
                                className="h-11 sm:w-28"
                                aria-label="Currency"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {currencies.map((code) => (
                                    <SelectItem key={code} value={code}>
                                        {code}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <Button
                        type="submit"
                        size="lg"
                        className="h-11"
                        disabled={processing || term.trim() === ''}
                    >
                        {processing && <Spinner />}
                        Search
                    </Button>
                </form>

                {search?.error && (
                    <p className="text-sm text-destructive" role="alert">
                        {search.error}
                    </p>
                )}

                {search && !search.error && (
                    <>
                        <SectionCard
                            title={
                                search.results.length === 1
                                    ? 'Your search'
                                    : `Results for “${search.normalised ?? search.query}”`
                            }
                            flush
                        >
                            {search.results.length === 0 ? (
                                <EmptyState
                                    title="No extensions to check"
                                    description="Add an extension, for example yourbrand.com."
                                />
                            ) : (
                                <ul className="divide-y">
                                    {search.results.map((result) => (
                                        <ResultRow
                                            key={result.domain}
                                            result={result}
                                            currency={search.currency}
                                            highlight={
                                                search.results.length === 1
                                            }
                                        />
                                    ))}
                                </ul>
                            )}
                        </SectionCard>

                        {search.suggestions.length > 0 && (
                            <SectionCard
                                title="Suggestions"
                                description="Similar names that are available right now."
                                flush
                            >
                                <ul className="divide-y">
                                    {search.suggestions.map((result) => (
                                        <ResultRow
                                            key={result.domain}
                                            result={result}
                                            currency={search.currency}
                                        />
                                    ))}
                                </ul>
                            </SectionCard>
                        )}
                    </>
                )}

                {!search && (
                    <EmptyState
                        variant="bordered"
                        icon={Search}
                        title="Start with a name"
                        description="Type a word or a full domain. We check the most popular extensions and suggest alternatives."
                    />
                )}
            </Page>
        </>
    );
}
