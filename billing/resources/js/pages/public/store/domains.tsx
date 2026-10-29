import { Form, Link, useHttp, usePage } from '@inertiajs/react';
import {
    TldAttributeFields,
    tldFieldsFor,
    tldMatch,
    type TldAttributeFieldMap,
} from '@/components/orders/tld-attribute-fields';
import { Head } from '@/components/app/head';
import { ArrowRightLeft, Globe, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import CartController from '@/actions/App/Modules/Orders/Http/Controllers/Public/CartController';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import { StatusBadge } from '@/components/app/status-badge';
import type { StatusTone } from '@/components/app/status-badge';
import InputError from '@/components/input-error';
import { StorefrontHero } from '@/components/orders/storefront-hero';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import store from '@/routes/store';
import type { DomainSearch, LookupResult } from '@/types/domains';

type Result = {
    domain: string;
    tld: string;
    register: number | null;
    transfer: number | null;
    renew: number | null;
    register_terms: number[];
    transfer_terms: number[];
};

type Props = {
    search: string;
    results: Result[];
    currency: string;
    tld_attribute_fields: TldAttributeFieldMap;
    /** The Domains availability lookup (JSON); null when Domains offers none. */
    lookup_url: string | null;
};

type Availability = {
    label: string;
    tone: StatusTone;
    note?: string;
    /** Why Register cannot be chosen; Register stays enabled when unset. */
    registerBlocked?: string;
    /** Why nothing can be added; hides the order controls. */
    blocked?: string;
};

/** What the lookup says about a name, in the customer's words. */
function availabilityOf(result: LookupResult | undefined): Availability | null {
    if (!result) {
        return null;
    }

    switch (result.status) {
        case 'available':
            return result.sellable
                ? { label: 'Available', tone: 'success' }
                : {
                      label: 'Available',
                      tone: 'neutral',
                      blocked:
                          "This name is free, but we don't sell it in your currency.",
                  };
        case 'registered':
            return result.transferable
                ? {
                      label: 'Taken',
                      tone: 'neutral',
                      note: 'Someone has registered this name. If it is yours, transfer it to us.',
                      registerBlocked:
                          'Already registered, so it can only be transferred.',
                  }
                : {
                      label: 'Taken',
                      tone: 'neutral',
                      blocked: result.in_system
                          ? 'This name is already registered with us.'
                          : 'Someone has registered this name.',
                  };
        case 'reserved':
            return {
                label: 'Reserved',
                tone: 'neutral',
                blocked:
                    'The registry has reserved this name, so it cannot be registered.',
            };
        case 'self_registered':
            return {
                label: 'Registered at the registry',
                tone: 'info',
                blocked:
                    result.level_hint ??
                    `${result.tld ?? 'These'} domains are registered by you at the registry${result.tld?.endsWith('.np') ? ', free for eligible applicants' : ''}. Once it is approved, use it with a hosting plan.`,
            };
        case 'unknown':
            return {
                label: 'Not checked',
                tone: 'warning',
                note: "We couldn't check this name just now. You can still add it; we check again before registering.",
            };
        default:
            return null;
    }
}

function DomainRow({
    result,
    lookup,
    checking,
    currency,
    transfer,
    tldAttributeFields,
}: {
    result: Result;
    lookup: LookupResult | undefined;
    checking: boolean;
    currency: string;
    transfer: boolean;
    tldAttributeFields: TldAttributeFieldMap;
}) {
    const availability = availabilityOf(lookup);
    const canRegister =
        result.register !== null && !availability?.registerBlocked;
    const canTransfer = result.transfer !== null;
    const [action, setAction] = useState<'register' | 'transfer'>(
        transfer && canTransfer ? 'transfer' : 'register',
    );

    // A name the lookup reports as taken switches to Transfer once the answer arrives.
    useEffect(() => {
        if (availability?.registerBlocked && canTransfer) {
            setAction('transfer');
        }
    }, [availability?.registerBlocked, canTransfer]);

    const [details, setDetails] = useState<Record<string, string>>({});
    const detailFields =
        action === 'register'
            ? tldFieldsFor(tldAttributeFields, result.domain)
            : [];
    const price = action === 'register' ? result.register : result.transfer;
    const actionBlocked =
        action === 'register' ? availability?.registerBlocked : undefined;
    const reasonId = `${result.domain}-reason`;

    const addButton = (processing: boolean) => (
        <Button
            type="submit"
            disabled={processing || price === null || Boolean(actionBlocked)}
            aria-describedby={actionBlocked ? reasonId : undefined}
        >
            {processing ? (
                <Spinner />
            ) : action === 'transfer' ? (
                <ArrowRightLeft />
            ) : (
                <Plus />
            )}
            Add
        </Button>
    );

    return (
        <li className="p-4 sm:px-5">
            <Form
                {...CartController.storeDomain.form()}
                options={{ preserveScroll: true }}
                className="grid gap-4"
            >
                {({ processing, errors, clearErrors }) => (
                    <>
                        <input
                            type="hidden"
                            name="domain"
                            value={result.domain}
                        />
                        <input type="hidden" name="action" value={action} />
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0 space-y-1">
                                <p className="flex flex-wrap items-center gap-2">
                                    <span className="truncate text-base font-medium">
                                        {lookup?.domain_unicode ??
                                            result.domain}
                                    </span>
                                    {checking && !availability && (
                                        <Skeleton
                                            className="h-5 w-20"
                                            aria-label="Checking availability"
                                        />
                                    )}
                                    {availability && (
                                        <StatusBadge
                                            status={lookup?.status ?? ''}
                                            label={availability.label}
                                            tone={availability.tone}
                                        />
                                    )}
                                    {lookup?.is_premium && (
                                        <Badge variant="outline">Premium</Badge>
                                    )}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {availability?.blocked ??
                                        availability?.note ??
                                        (result.renew !== null ? (
                                            <>
                                                Renews at{' '}
                                                <Money
                                                    amount={result.renew}
                                                    currency={currency}
                                                />
                                                /yr
                                            </>
                                        ) : (
                                            'Renewal price on request'
                                        ))}
                                </p>
                                {actionBlocked && (
                                    <p
                                        id={reasonId}
                                        className="text-sm text-muted-foreground"
                                    >
                                        {actionBlocked}
                                    </p>
                                )}
                            </div>
                            {availability?.blocked ? (
                                lookup?.self_registered && (
                                    <div className="flex flex-wrap gap-2">
                                        {lookup.registration_url && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                size="sm"
                                            >
                                                <a
                                                    href={
                                                        lookup.registration_url
                                                    }
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                >
                                                    How to register
                                                </a>
                                            </Button>
                                        )}
                                        {lookup.existing_url && (
                                            <Button asChild size="sm">
                                                <Link
                                                    href={lookup.existing_url}
                                                >
                                                    I already have it
                                                </Link>
                                            </Button>
                                        )}
                                    </div>
                                )
                            ) : (
                                <div className="flex flex-wrap items-center gap-2">
                                    <Select
                                        value={action}
                                        onValueChange={(next) =>
                                            setAction(
                                                next as 'register' | 'transfer',
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label={`Action for ${result.domain}`}
                                            className="w-36"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                value="register"
                                                disabled={!canRegister}
                                            >
                                                Register
                                            </SelectItem>
                                            <SelectItem
                                                value="transfer"
                                                disabled={!canTransfer}
                                            >
                                                Transfer in
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <Select
                                        key={action}
                                        name="years"
                                        defaultValue={String(
                                            (action === 'register'
                                                ? result.register_terms
                                                : result.transfer_terms)[0] ??
                                                1,
                                        )}
                                    >
                                        <SelectTrigger
                                            aria-label={`Years for ${result.domain}`}
                                            className="w-28"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(action === 'register'
                                                ? result.register_terms
                                                : result.transfer_terms
                                            ).map((years) => (
                                                <SelectItem
                                                    key={years}
                                                    value={String(years)}
                                                >
                                                    {years}{' '}
                                                    {years === 1
                                                        ? 'year'
                                                        : 'years'}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {action === 'transfer' && (
                                        <Input
                                            name="epp_code"
                                            placeholder="EPP code"
                                            aria-label="EPP code"
                                            className="w-32"
                                            autoComplete="off"
                                        />
                                    )}
                                    <span className="text-right font-semibold tabular-nums">
                                        <Money
                                            amount={price}
                                            currency={currency}
                                        />
                                        <span className="text-xs font-normal text-muted-foreground">
                                            /yr
                                        </span>
                                    </span>
                                    {detailFields.length === 0 &&
                                        addButton(processing)}
                                </div>
                            )}
                        </div>
                        {!availability?.blocked && detailFields.length > 0 && (
                            <>
                                <TldAttributeFields
                                    extension={
                                        tldMatch(
                                            tldAttributeFields,
                                            result.domain,
                                        ) ?? ''
                                    }
                                    fields={detailFields}
                                    values={details}
                                    onChange={setDetails}
                                    errors={errors}
                                    onClearError={clearErrors}
                                    idPrefix={`${result.domain}-detail`}
                                    className="grid gap-4 rounded-lg border bg-muted/30 p-4"
                                    nativeInputs
                                />
                                <div className="flex justify-end">
                                    {addButton(processing)}
                                </div>
                            </>
                        )}
                        <InputError
                            message={
                                errors.domain ?? errors.epp_code ?? errors.years
                            }
                        />
                    </>
                )}
            </Form>
        </li>
    );
}

/** A lookup suggestion the store did not price itself: register only, at the lookup's prices. */
function suggestionRow(suggestion: LookupResult): Result {
    const terms = suggestion.register ?? [];

    return {
        domain: suggestion.domain,
        tld: suggestion.tld ?? '',
        register: terms[0]?.price ?? null,
        transfer: null,
        renew: suggestion.renew ?? null,
        register_terms: terms.map((term) => term.years),
        transfer_terms: [],
    };
}

export default function Domains({
    search,
    results,
    currency,
    tld_attribute_fields: tldAttributeFields,
    lookup_url: lookupUrl,
}: Props) {
    // "Transfer a domain" links here with ?transfer=1: transfer is preselected on each result.
    const transfer =
        new URL(usePage().url, 'http://localhost').searchParams.get(
            'transfer',
        ) === '1';
    const lookup = useHttp<Record<string, never>, DomainSearch>({});
    const [answer, setAnswer] = useState<DomainSearch | null>(null);
    const [checking, setChecking] = useState(false);

    useEffect(() => {
        setAnswer(null);

        if (!lookupUrl || search === '') {
            return;
        }

        setChecking(true);
        lookup
            .get(`${lookupUrl}?${new URLSearchParams({ q: search, currency })}`)
            .then((response) => setAnswer(response))
            .catch(() => setAnswer(null))
            .finally(() => setChecking(false));
        // The lookup runs once per search; `lookup` is a new object on every render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lookupUrl, search, currency]);

    const byDomain = new Map(
        [...(answer?.results ?? []), ...(answer?.suggestions ?? [])].map(
            (result) => [result.domain, result],
        ),
    );
    const priced = new Set(results.map((result) => result.domain));
    // Extensions registered at the registry (.np) join the results; available names the lookup suggests get their own list.
    const rows = [
        ...results,
        ...(answer?.results ?? [])
            .filter(
                (result) =>
                    !priced.has(result.domain) &&
                    result.status === 'self_registered',
            )
            .map(suggestionRow),
    ];
    const suggestions = (answer?.suggestions ?? [])
        .filter((result) => !priced.has(result.domain) && result.sellable)
        .map(suggestionRow);
    const row = (result: Result) => (
        <DomainRow
            key={result.domain}
            result={result}
            lookup={byDomain.get(result.domain)}
            checking={checking}
            currency={currency}
            transfer={transfer}
            tldAttributeFields={tldAttributeFields}
        />
    );

    return (
        <>
            <Head
                title={transfer ? 'Transfer a domain' : 'Register a domain'}
            />

            <StorefrontHero
                eyebrow="Domains"
                title={
                    transfer
                        ? 'Transfer your domain to us'
                        : 'Find the perfect domain'
                }
                description="Register a new name or transfer one you already own. Prices are per year."
            >
                <Form
                    {...store.domains.form()}
                    className="mt-2 flex w-full max-w-xl flex-col gap-2 sm:flex-row"
                >
                    {transfer && (
                        <input type="hidden" name="transfer" value="1" />
                    )}
                    <Field
                        htmlFor="domain"
                        label={<span className="sr-only">Domain name</span>}
                        className="flex-1 gap-0"
                    >
                        <div className="relative">
                            <Globe
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Input
                                id="domain"
                                name="domain"
                                defaultValue={search}
                                placeholder="ashatraders.com.np"
                                className="h-11 bg-background pl-9"
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                            />
                        </div>
                    </Field>
                    <Button type="submit" size="lg" className="h-11">
                        <Search aria-hidden="true" />
                        Search
                    </Button>
                </Form>
            </StorefrontHero>

            <div className="mx-auto w-full max-w-6xl px-4 py-8 md:px-6 lg:py-10">
                {search === '' ? (
                    <EmptyState
                        variant="bordered"
                        icon={Globe}
                        title="Search for a domain to see prices"
                        description="Type the name you want, with or without an extension. We'll check if it's free and suggest alternatives."
                    />
                ) : rows.length === 0 && !checking ? (
                    <EmptyState
                        variant="bordered"
                        icon={Search}
                        title={`We don't offer ${search}`}
                        description="Check the spelling or try a different extension such as .com or .com.np."
                    />
                ) : (
                    <section
                        aria-labelledby="domain-results"
                        aria-busy={checking}
                        className="overflow-hidden rounded-xl border bg-card shadow-xs"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-3">
                            <h2
                                id="domain-results"
                                className="text-sm font-medium text-muted-foreground"
                            >
                                Results for “{search}”
                            </h2>
                            <p
                                className="text-xs text-muted-foreground"
                                role="status"
                            >
                                {checking
                                    ? 'Checking availability…'
                                    : answer
                                      ? 'Availability checked'
                                      : ''}
                            </p>
                        </div>
                        <ul className="divide-y">{rows.map(row)}</ul>
                    </section>
                )}
                {suggestions.length > 0 && (
                    <section
                        aria-labelledby="domain-suggestions"
                        className="mt-6 overflow-hidden rounded-xl border bg-card shadow-xs"
                    >
                        <h2
                            id="domain-suggestions"
                            className="border-b px-5 py-3 text-sm font-medium text-muted-foreground"
                        >
                            More ideas, available now
                        </h2>
                        <ul className="divide-y">{suggestions.map(row)}</ul>
                    </section>
                )}
            </div>
        </>
    );
}
