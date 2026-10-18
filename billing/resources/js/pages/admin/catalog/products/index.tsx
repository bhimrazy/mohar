import { Head, Link } from '@inertiajs/react';
import { Package, Plus } from 'lucide-react';
import ProductController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductController';
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
import type { EnumOption } from '@/components/catalog/types';
import type { LaravelPaginated } from '@/types/app';

type ProductRow = {
    id: number;
    name: string;
    slug: string;
    group: { id: number; name: string };
    type: string;
    type_label: string;
    payment_type: string;
    payment_type_label: string;
    status: string;
    is_taxable: boolean;
    stock: number | null;
    auto_setup: string;
    auto_setup_label: string;
    active_services: number;
    price_from: {
        amount: number;
        setup_fee: number;
        cycle: string;
        cycle_label: string;
    } | null;
};

type Props = {
    products: LaravelPaginated<ProductRow>;
    groups: { id: number; name: string }[];
    types: EnumOption[];
    currency: string;
    canManage: boolean;
};

const AUTO_SETUP_SHORT: Record<string, string> = {
    on_order: 'On order',
    on_payment: 'On payment',
    on_accept: 'On accept',
    manual: 'Manual',
};

export default function ProductsIndex({
    products,
    groups,
    types,
    currency,
    canManage,
}: Props) {
    const { params } = useQueryParams();
    const filtered = ['search', 'group', 'type', 'status'].some((key) =>
        params.get(key),
    );

    const columns: DataTableColumn<ProductRow>[] = [
        {
            key: 'name',
            header: 'Product',
            primary: true,
            cell: (product) => (
                <span className="flex flex-col">
                    <span>{product.name}</span>
                    <span className="text-xs font-normal text-muted-foreground">
                        {product.group.name}
                    </span>
                </span>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            cell: (product) => product.type_label,
        },
        {
            key: 'billing',
            header: 'Billing',
            cell: (product) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    {product.payment_type_label}
                    {!product.is_taxable && (
                        <Badge
                            variant="outline"
                            title="Invoices for this product are not taxed"
                        >
                            Untaxed
                        </Badge>
                    )}
                </span>
            ),
        },
        {
            key: 'price',
            header: `From (${currency})`,
            align: 'right',
            cell: (product) =>
                product.payment_type === 'free' ? (
                    'Free'
                ) : product.price_from ? (
                    <span className="flex flex-col items-end">
                        <Money
                            amount={product.price_from.amount}
                            currency={currency}
                        />
                        <span className="text-xs text-muted-foreground">
                            {product.price_from.cycle_label}
                        </span>
                    </span>
                ) : (
                    <span className="text-muted-foreground">Not priced</span>
                ),
        },
        {
            key: 'stock',
            header: 'Stock',
            align: 'right',
            cell: (product) =>
                product.stock === null ? (
                    <span className="text-muted-foreground">—</span>
                ) : product.stock <= 0 ? (
                    <StatusBadge
                        status="out_of_stock"
                        tone="danger"
                        label="Out of stock"
                    />
                ) : (
                    <span className="tabular-nums">{product.stock}</span>
                ),
        },
        {
            key: 'auto_setup',
            header: 'Auto-setup',
            hideOnMobile: true,
            cell: (product) => (
                <span title={product.auto_setup_label}>
                    {AUTO_SETUP_SHORT[product.auto_setup] ?? product.auto_setup}
                </span>
            ),
        },
        {
            key: 'services',
            header: 'Active services',
            align: 'right',
            cell: (product) => (
                <span className="tabular-nums">{product.active_services}</span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (product) => <StatusBadge status={product.status} />,
        },
    ];

    return (
        <>
            <Head title="Products" />
            <Page>
                <PageHeader
                    title="Products"
                    description="Everything you sell, its prices and how it is provisioned."
                    actions={
                        canManage && (
                            <Button asChild>
                                <Link href={ProductController.create()}>
                                    <Plus />
                                    New product
                                </Link>
                            </Button>
                        )
                    }
                />

                <FilterBar
                    searchPlaceholder="Search products…"
                    filterKeys={['group', 'type', 'status']}
                    only={['products', 'filters']}
                    filters={
                        <>
                            <FilterSelect
                                name="group"
                                label="Groups"
                                options={groups.map((group) => ({
                                    value: String(group.id),
                                    label: group.name,
                                }))}
                                only={['products', 'filters']}
                            />
                            <FilterSelect
                                name="type"
                                label="Types"
                                options={types}
                                only={['products', 'filters']}
                            />
                            <FilterSelect
                                name="status"
                                label="Status"
                                allLabel="All statuses"
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'hidden', label: 'Hidden' },
                                    { value: 'retired', label: 'Retired' },
                                ]}
                                only={['products', 'filters']}
                            />
                        </>
                    }
                />

                <DataTable
                    caption="Products"
                    columns={columns}
                    rows={products.data}
                    getRowKey={(product) => product.id}
                    rowHref={(product) => ProductController.edit(product)}
                    footer={
                        products.total > products.per_page && (
                            <Pagination
                                paginator={products}
                                itemLabel="products"
                                only={['products', 'filters']}
                            />
                        )
                    }
                    empty={
                        filtered ? (
                            <EmptyState
                                icon={Package}
                                title="No products match these filters"
                                description="Try another group, type or status."
                            />
                        ) : (
                            <EmptyState
                                icon={Package}
                                title="No products yet"
                                description="Products appear in the store once they are priced in a currency."
                                action={
                                    canManage && (
                                        <Button size="sm" asChild>
                                            <Link
                                                href={ProductController.create()}
                                            >
                                                <Plus />
                                                New product
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
