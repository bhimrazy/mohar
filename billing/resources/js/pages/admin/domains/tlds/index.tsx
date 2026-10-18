import { Head, Link } from '@inertiajs/react';
import { Plus, Tag } from 'lucide-react';
import TldController from '@/actions/App/Modules/Domains/Http/Controllers/Admin/TldController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type TldRow = {
    id: number;
    extension: string;
    display_unicode: string | null;
    registrar_key: string | null;
    epp_required: boolean;
    grace_days: number;
    redemption_days: number;
    is_spotlight: boolean;
    badge: string | null;
    is_active: boolean;
    domains_count: number;
    price: {
        register: number | null;
        transfer: number | null;
        renew: number | null;
    } | null;
};

type Props = {
    tlds: TldRow[];
    currency: string;
    canManage: boolean;
    registrarNames: Record<string, string>;
};

export default function TldsIndex({
    tlds,
    currency,
    canManage,
    registrarNames,
}: Props) {
    const price = (amount: number | null | undefined) => (
        <Money amount={amount ?? null} currency={currency} />
    );

    const columns: DataTableColumn<TldRow>[] = [
        {
            key: 'extension',
            header: 'Extension',
            primary: true,
            cell: (tld) => (
                <span className="inline-flex items-center gap-2">
                    {tld.display_unicode &&
                    tld.display_unicode !== tld.extension.slice(1)
                        ? `.${tld.display_unicode}`
                        : tld.extension}
                    {tld.is_spotlight && (
                        <Badge variant="secondary">Spotlight</Badge>
                    )}
                    {tld.badge && <Badge variant="outline">{tld.badge}</Badge>}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (tld) => (
                <StatusBadge
                    status={tld.is_active ? 'active' : 'hidden'}
                    label={tld.is_active ? 'Active' : 'Hidden'}
                />
            ),
        },
        {
            key: 'registrar',
            header: 'Registrar',
            hideOnMobile: true,
            cell: (tld) =>
                tld.registrar_key ? (
                    (registrarNames[tld.registrar_key] ?? tld.registrar_key)
                ) : (
                    <span className="text-muted-foreground">Manual</span>
                ),
        },
        {
            key: 'register',
            header: 'Register 1y',
            align: 'right',
            cell: (tld) => price(tld.price?.register),
        },
        {
            key: 'transfer',
            header: 'Transfer',
            align: 'right',
            cell: (tld) => price(tld.price?.transfer),
        },
        {
            key: 'renew',
            header: 'Renew 1y',
            align: 'right',
            cell: (tld) => price(tld.price?.renew),
        },
        {
            key: 'grace',
            header: 'Grace / redemption',
            hideOnMobile: true,
            cell: (tld) => `${tld.grace_days} / ${tld.redemption_days} days`,
        },
        {
            key: 'domains',
            header: 'Domains',
            align: 'right',
            hideOnMobile: true,
            cell: (tld) => tld.domains_count,
        },
    ];

    return (
        <>
            <Head title="TLD pricing" />
            <Page>
                <PageHeader
                    title="TLD pricing"
                    description={`Extensions you sell, with base prices in ${currency}. Open one to edit every currency, client group and term.`}
                    actions={
                        canManage && (
                            <Button asChild>
                                <Link href={TldController.create()}>
                                    <Plus />
                                    Add TLD
                                </Link>
                            </Button>
                        )
                    }
                />
                <DataTable
                    caption="TLDs"
                    columns={columns}
                    rows={tlds}
                    getRowKey={(tld) => tld.id}
                    rowHref={(tld) => TldController.edit(tld.id)}
                    empty={
                        <EmptyState
                            icon={Tag}
                            title="No TLDs yet"
                            description="Add an extension such as .com, set its registrar and prices, and it appears in the domain search."
                            action={
                                canManage && (
                                    <Button size="sm" asChild>
                                        <Link href={TldController.create()}>
                                            <Plus />
                                            Add TLD
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    }
                />
            </Page>
        </>
    );
}
