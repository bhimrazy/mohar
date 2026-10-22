import { useState } from 'react';
import { Money } from '@/components/app/money';
import { DomainSearch, TaxNote } from '@/components/storefront/pricing';
import { SiteHead } from '@/components/storefront/site-head';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { DomainPrice, PageSeo } from '@/types/storefront';

type Props = {
    domains: DomainPrice[];
    currency: string;
    tax_note: string | null;
    search_url: string | null;
    seo: PageSeo;
};

/** One-year register, renew and transfer prices for every extension sold in the visitor's currency. */
export default function DomainPricing({
    domains,
    currency,
    tax_note,
    search_url,
    seo,
}: Props) {
    const [filter, setFilter] = useState('');
    const term = filter.trim().replace(/^\.?/, '.').toLowerCase();
    const rows =
        filter.trim() === ''
            ? domains
            : domains.filter((row) => row.tld.includes(term));

    return (
        <>
            <SiteHead seo={seo} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-10 md:px-6">
                <div className="space-y-2">
                    <h1 className="font-display text-3xl font-semibold tracking-tight">
                        Domain prices
                    </h1>
                    <p className="text-muted-foreground">
                        Prices for one year, in {currency}. Renewal is shown for
                        every extension.
                    </p>
                    <TaxNote note={tax_note} />
                </div>
                {search_url && (
                    <DomainSearch action={search_url} className="max-w-xl" />
                )}
                <Input
                    type="search"
                    value={filter}
                    onChange={(event) => setFilter(event.target.value)}
                    placeholder="Filter extensions, e.g. .com"
                    aria-label="Filter extensions"
                    className="max-w-xs"
                />
                {/* Phones: one card per extension with labelled prices, so no column is cut off. */}
                <ul className="divide-y rounded-xl border bg-card sm:hidden">
                    {rows.map((row) => (
                        <li key={row.tld} className="space-y-2 p-4">
                            <p className="flex items-center gap-2 font-medium">
                                {row.tld}
                                {row.badge && (
                                    <Badge
                                        variant="secondary"
                                        className="capitalize"
                                    >
                                        {row.badge}
                                    </Badge>
                                )}
                            </p>
                            <dl className="grid grid-cols-3 gap-2 text-sm">
                                {(
                                    ['register', 'renew', 'transfer'] as const
                                ).map((action) => (
                                    <div key={action}>
                                        <dt className="text-xs text-muted-foreground capitalize">
                                            {action}
                                        </dt>
                                        <dd>
                                            <DomainAmount
                                                amount={row[action]}
                                                currency={currency}
                                            />
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </li>
                    ))}
                    {rows.length === 0 && (
                        <li className="p-6 text-center text-sm text-muted-foreground">
                            {domains.length === 0
                                ? `No domain extensions are sold in ${currency} yet.`
                                : 'No extension matches that filter.'}
                        </li>
                    )}
                </ul>
                <div className="hidden overflow-x-auto rounded-xl border bg-card sm:block">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead scope="col">Extension</TableHead>
                                <TableHead scope="col" className="text-right">
                                    Register
                                </TableHead>
                                <TableHead scope="col" className="text-right">
                                    Renew
                                </TableHead>
                                <TableHead scope="col" className="text-right">
                                    Transfer
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.tld}>
                                    <TableHead
                                        scope="row"
                                        className="font-medium text-foreground"
                                    >
                                        {row.tld}
                                        {row.badge && (
                                            <Badge
                                                variant="secondary"
                                                className="ml-2 capitalize"
                                            >
                                                {row.badge}
                                            </Badge>
                                        )}
                                    </TableHead>
                                    {(
                                        [
                                            'register',
                                            'renew',
                                            'transfer',
                                        ] as const
                                    ).map((action) => (
                                        <TableCell
                                            key={action}
                                            className="text-right"
                                        >
                                            <DomainAmount
                                                amount={row[action]}
                                                currency={currency}
                                            />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                            {rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        {domains.length === 0
                                            ? `No domain extensions are sold in ${currency} yet.`
                                            : 'No extension matches that filter.'}
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

/** A one-year price: "Not offered" when the action is not sold, "Free" for a zero price (UK transfers are a tag change). */
function DomainAmount({
    amount,
    currency,
}: {
    amount: number | null;
    currency: string;
}) {
    if (amount === null) {
        return <span className="text-muted-foreground">Not offered</span>;
    }

    return amount === 0 ? (
        <span>Free</span>
    ) : (
        <Money amount={amount} currency={currency} />
    );
}
