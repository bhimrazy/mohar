import { Link, router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Coins, MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';
import CurrencyController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/CurrencyController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type CurrencyRow = {
    id: number;
    code: string;
    name: string;
    prefix: string;
    suffix: string;
    decimals: number;
    format: string;
    exchange_rate: string;
    is_default: boolean;
    rate_updated_at: string | null;
    clients_count: number;
};

export default function CurrenciesIndex({
    currencies,
}: {
    currencies: CurrencyRow[];
}) {
    const [deleting, setDeleting] = useState<CurrencyRow | null>(null);
    const base = currencies.find((currency) => currency.is_default);

    const columns: DataTableColumn<CurrencyRow>[] = [
        {
            key: 'code',
            header: 'Currency',
            primary: true,
            cell: (currency) => (
                <span className="flex items-center gap-2">
                    <span className="font-mono">{currency.code}</span>
                    <span className="font-normal text-muted-foreground">
                        {currency.name}
                    </span>
                    {currency.is_default && (
                        <Badge variant="secondary">Default</Badge>
                    )}
                </span>
            ),
        },
        {
            key: 'sample',
            header: 'Format',
            hideOnMobile: true,
            cell: (currency) => (
                <span className="text-muted-foreground tabular-nums">
                    {currency.prefix}
                    {currency.decimals === 0
                        ? currency.format.replace(/[.,]56$/, '')
                        : currency.format}
                    {currency.suffix}
                </span>
            ),
        },
        {
            key: 'rate',
            header: base ? `Rate per 1 ${base.code}` : 'Rate',
            align: 'right',
            cell: (currency) => (
                <span className="font-mono tabular-nums">
                    {Number(currency.exchange_rate).toLocaleString(undefined, {
                        maximumFractionDigits: 6,
                    })}
                </span>
            ),
        },
        {
            key: 'updated',
            header: 'Rate updated',
            hideOnMobile: true,
            cell: (currency) => (
                <DateTime value={currency.rate_updated_at} relative />
            ),
        },
        {
            key: 'clients',
            header: 'Clients',
            align: 'right',
            cell: (currency) => (
                <span className="tabular-nums">{currency.clients_count}</span>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            hideOnMobile: true,
            cell: (currency) => (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Actions for ${currency.code}`}
                        >
                            <MoreHorizontal />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            <Link href={CurrencyController.edit(currency.id)}>
                                Edit
                            </Link>
                        </DropdownMenuItem>
                        {!currency.is_default && (
                            <>
                                <DropdownMenuItem
                                    onSelect={() =>
                                        router.visit(
                                            CurrencyController.makeDefault.url(
                                                currency.id,
                                            ),
                                            {
                                                method: 'post',
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Make default
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setDeleting(currency)}
                                >
                                    Delete
                                </DropdownMenuItem>
                            </>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            ),
        },
    ];

    return (
        <>
            <Head title="Currencies" />
            <Page>
                <PageHeader
                    title="Currencies"
                    description="Exchange rates are units of each currency per 1 unit of the default currency."
                    actions={
                        <Button asChild>
                            <Link href={CurrencyController.create()}>
                                <Plus />
                                New currency
                            </Link>
                        </Button>
                    }
                />
                <DataTable
                    caption="Currencies"
                    columns={columns}
                    rows={currencies}
                    getRowKey={(currency) => currency.id}
                    rowHref={(currency) => CurrencyController.edit(currency.id)}
                    empty={
                        <EmptyState
                            icon={Coins}
                            title="No currencies yet"
                            description="Add the currency you invoice in first; it becomes the default."
                        />
                    }
                />
            </Page>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.code ?? ''}?`}
                description="Currencies used by clients or prices cannot be deleted."
                confirmLabel="Delete currency"
                action={
                    deleting
                        ? CurrencyController.destroy(deleting.id)
                        : undefined
                }
            />
        </>
    );
}

CurrenciesIndex.layout = {
    breadcrumbs: [{ title: 'Currencies', href: CurrencyController.index() }],
};
