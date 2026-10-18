import { Form, Head } from '@inertiajs/react';
import { ArrowRightLeft, Globe, Plus, Search } from 'lucide-react';
import { useState } from 'react';
import CartController from '@/actions/App/Modules/Orders/Http/Controllers/Public/CartController';
import { EmptyState } from '@/components/app/empty-state';
import { Field } from '@/components/app/field';
import { Money } from '@/components/app/money';
import InputError from '@/components/input-error';
import { StorefrontHero } from '@/components/orders/storefront-hero';
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
import store from '@/routes/store';

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
};

function DomainRow({ result, currency }: { result: Result; currency: string }) {
    const [action, setAction] = useState<'register' | 'transfer'>('register');
    const price = action === 'register' ? result.register : result.transfer;

    return (
        <li className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div className="min-w-0">
                <p className="truncate text-base font-medium">
                    {result.domain}
                </p>
                <p className="text-sm text-muted-foreground">
                    {result.renew !== null ? (
                        <>
                            Renews at{' '}
                            <Money amount={result.renew} currency={currency} />
                            /yr
                        </>
                    ) : (
                        'Renewal price on request'
                    )}
                </p>
            </div>
            <Form
                {...CartController.storeDomain.form()}
                options={{ preserveScroll: true }}
                className="flex flex-col gap-2 sm:items-end"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="domain"
                            value={result.domain}
                        />
                        <input type="hidden" name="action" value={action} />
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                value={action}
                                onValueChange={(next) =>
                                    setAction(next as 'register' | 'transfer')
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
                                        disabled={result.register === null}
                                    >
                                        Register
                                    </SelectItem>
                                    <SelectItem
                                        value="transfer"
                                        disabled={result.transfer === null}
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
                                        : result.transfer_terms)[0] ?? 1,
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
                                            {years === 1 ? 'year' : 'years'}
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
                            <span className="w-24 text-right font-semibold tabular-nums">
                                <Money amount={price} currency={currency} />
                                <span className="text-xs font-normal text-muted-foreground">
                                    /yr
                                </span>
                            </span>
                            <Button
                                type="submit"
                                disabled={processing || price === null}
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
                        </div>
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

export default function Domains({ search, results, currency }: Props) {
    return (
        <>
            <Head title="Domains" />

            <StorefrontHero
                eyebrow="Domains"
                title="Find the perfect domain"
                description="Register a new name or transfer one you already own. Prices are per year."
            >
                <Form
                    {...store.domains.form()}
                    className="mt-2 flex w-full max-w-xl flex-col gap-2 sm:flex-row"
                >
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
                                placeholder="mybusiness.com"
                                className="h-11 bg-background pl-9"
                                autoComplete="off"
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

            <div className="mx-auto w-full max-w-4xl px-4 py-10 md:px-6">
                {search === '' ? (
                    <EmptyState
                        variant="bordered"
                        icon={Globe}
                        title="Search for a domain to see prices"
                        description="Type the name you want, with or without an extension. We'll suggest popular alternatives too."
                    />
                ) : results.length === 0 ? (
                    <EmptyState
                        variant="bordered"
                        icon={Search}
                        title={`We don't offer ${search}`}
                        description="Check the spelling or try a different extension such as .com or .net."
                    />
                ) : (
                    <section
                        aria-labelledby="domain-results"
                        className="overflow-hidden rounded-xl border bg-card shadow-xs"
                    >
                        <h2
                            id="domain-results"
                            className="border-b px-5 py-3 text-sm font-medium text-muted-foreground"
                        >
                            Results for “{search}”
                        </h2>
                        <ul className="divide-y">
                            {results.map((result) => (
                                <DomainRow
                                    key={result.domain}
                                    result={result}
                                    currency={currency}
                                />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
