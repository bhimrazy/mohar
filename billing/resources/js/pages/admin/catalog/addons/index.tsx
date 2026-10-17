import { Head, Link } from '@inertiajs/react';
import { Plus, Puzzle } from 'lucide-react';
import AddonController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/AddonController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { LaravelPaginated } from '@/types/app';

type AddonRow = {
    id: number;
    name: string;
    kind: string;
    payment_type: string;
    payment_type_label: string;
    status: string;
    show_on_order: boolean;
    is_taxable: boolean;
    products_count: number;
    price_from: { amount: number; cycle_label: string } | null;
};

export default function AddonsIndex({
    addons,
    currency,
    canManage,
}: {
    addons: LaravelPaginated<AddonRow>;
    currency: string;
    canManage: boolean;
}) {
    const { params } = useQueryParams();
    const filtered = Boolean(params.get('search') || params.get('status'));

    const columns: DataTableColumn<AddonRow>[] = [
        {
            key: 'name',
            header: 'Addon',
            primary: true,
            cell: (addon) => addon.name,
        },
        {
            key: 'billing',
            header: 'Billing',
            cell: (addon) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    {addon.payment_type_label}
                    {!addon.is_taxable && (
                        <Badge variant="outline">Untaxed</Badge>
                    )}
                </span>
            ),
        },
        {
            key: 'price',
            header: `From (${currency})`,
            align: 'right',
            cell: (addon) =>
                addon.payment_type === 'free' ? (
                    'Free'
                ) : addon.price_from ? (
                    <span className="flex flex-col items-end">
                        <Money
                            amount={addon.price_from.amount}
                            currency={currency}
                        />
                        <span className="text-xs text-muted-foreground">
                            {addon.price_from.cycle_label}
                        </span>
                    </span>
                ) : (
                    <span className="text-muted-foreground">Not priced</span>
                ),
        },
        {
            key: 'order',
            header: 'New orders',
            cell: (addon) =>
                addon.show_on_order ? (
                    'Offered'
                ) : (
                    <span className="text-muted-foreground">
                        Existing services only
                    </span>
                ),
        },
        {
            key: 'products',
            header: 'Products',
            align: 'right',
            cell: (addon) => (
                <span className="tabular-nums">{addon.products_count}</span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (addon) => <StatusBadge status={addon.status} />,
        },
    ];

    return (
        <>
            <Head title="Addons" />
            <Page>
                <PageHeader
                    title="Addons"
                    description="Extras such as backups or dedicated IPs, billed on their own cycle alongside a product."
                    actions={
                        canManage && (
                            <Button asChild>
                                <Link href={AddonController.create()}>
                                    <Plus />
                                    New addon
                                </Link>
                            </Button>
                        )
                    }
                />
                <FilterBar
                    searchPlaceholder="Search addons…"
                    filterKeys={['status']}
                    filters={
                        <FilterSelect
                            name="status"
                            label="Status"
                            allLabel="All statuses"
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'hidden', label: 'Hidden' },
                                { value: 'retired', label: 'Retired' },
                            ]}
                        />
                    }
                />
                <DataTable
                    caption="Addons"
                    columns={columns}
                    rows={addons.data}
                    getRowKey={(addon) => addon.id}
                    rowHref={(addon) => AddonController.edit(addon)}
                    footer={
                        addons.total > addons.per_page && (
                            <Pagination paginator={addons} itemLabel="addons" />
                        )
                    }
                    empty={
                        filtered ? (
                            <EmptyState
                                icon={Puzzle}
                                title="No addons match these filters"
                            />
                        ) : (
                            <EmptyState
                                icon={Puzzle}
                                title="No addons yet"
                                description="Addons appear on the order form of the products you attach them to."
                                action={
                                    canManage && (
                                        <Button size="sm" asChild>
                                            <Link
                                                href={AddonController.create()}
                                            >
                                                <Plus />
                                                New addon
                                            </Link>
                                        </Button>
                                    )
                                }
                            />
                        )
                    }
                />
            </Page>
        </>
    );
}
