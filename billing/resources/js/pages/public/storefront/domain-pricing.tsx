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
                <div className="overflow-x-auto rounded-xl border bg-card">
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
                                            {row[action] === null ? (
                                                <span className="text-muted-foreground">
                                                    Not offered
                                                </span>
                                            ) : row[action] === 0 ? (
                                                // A zero price is a free action (UK transfers are a tag change).
                                                <span>Free</span>
                                            ) : (
                                                <Money
                                                    amount={row[action]}
                                                    currency={currency}
                                                />
                                            )}
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
