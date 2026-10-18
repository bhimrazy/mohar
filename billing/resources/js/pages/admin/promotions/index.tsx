import { Head, Link } from '@inertiajs/react';
import { Percent, Plus } from 'lucide-react';
import PromotionController from '@/actions/App/Modules/Orders/Http/Controllers/Admin/PromotionController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { StatusBadge } from '@/components/app/status-badge';
import { useQueryParams } from '@/components/app/use-query-params';
import { Button } from '@/components/ui/button';
import type { ResourcePaginated } from '@/types/app';
import type { Promotion } from '@/types/orders';

type Props = {
    promotions: ResourcePaginated<Promotion>;
    currency: string;
};

export default function PromotionsIndex({ promotions }: Props) {
    const { params } = useQueryParams();
    const filtered = Boolean(params.get('search') || params.get('state'));

    const columns: DataTableColumn<Promotion>[] = [
        {
            key: 'code',
            header: 'Code',
            primary: true,
            cell: (promotion) => (
                <span className="font-mono">{promotion.code}</span>
            ),
        },
        {
            key: 'summary',
            header: 'Discount',
            cell: (promotion) => promotion.summary,
        },
        {
            key: 'type',
            header: 'Type',
            hideOnMobile: true,
            cell: (promotion) => (
                <span className="text-muted-foreground">
                    {promotion.type_label}
                </span>
            ),
        },
        {
            key: 'uses',
            header: 'Uses',
            align: 'right',
            cell: (promotion) => (
                <span className="tabular-nums">
                    {promotion.uses}
                    {promotion.max_uses > 0 && (
                        <span className="text-muted-foreground">
                            {' '}
                            / {promotion.max_uses}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'expires',
            header: 'Expires',
            hideOnMobile: true,
            cell: (promotion) => (
                <DateTime value={promotion.expires_on} fallback="Never" />
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (promotion) => <StatusBadge status={promotion.status} />,
        },
    ];

    return (
        <>
            <Head title="Promotions" />
            <Page>
                <PageHeader
                    title="Promotions"
                    description="Promotion codes customers enter in the cart or open with a ?promo= link."
                    actions={
                        <Button asChild>
                            <Link href={PromotionController.create.url()}>
                                <Plus /> New promotion
                            </Link>
                        </Button>
                    }
                />
                <FilterBar
                    searchPlaceholder="Search codes…"
                    filterKeys={['state']}
                    filters={
                        <FilterSelect
                            name="state"
                            label="Availability"
                            allLabel="All promotions"
                            options={[
                                {
                                    value: 'active',
                                    label: 'Not expired manually',
                                },
                                { value: 'expired', label: 'Expired manually' },
                            ]}
                        />
                    }
                />
                <DataTable
                    caption="Promotions"
                    columns={columns}
                    rows={promotions.data}
                    getRowKey={(promotion) => promotion.id}
                    rowHref={(promotion) =>
                        PromotionController.edit.url(promotion.id)
                    }
                    empty={
                        filtered ? (
                            <EmptyState
                                icon={Percent}
                                title="No promotions match these filters"
                            />
                        ) : (
                            <EmptyState
                                icon={Percent}
                                title="No promotions yet"
                                description="Create a code for a percentage, fixed amount, price override or free setup."
                                action={
                                    <Button asChild>
                                        <Link
                                            href={PromotionController.create.url()}
                                        >
                                            New promotion
                                        </Link>
                                    </Button>
                                }
                            />
                        )
                    }
                    footer={
                        <Pagination
                            paginator={promotions}
                            itemLabel="promotions"
                        />
                    }
                />
            </Page>
        </>
    );
}
