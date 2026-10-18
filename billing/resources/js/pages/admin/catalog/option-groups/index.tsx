import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Plus, SlidersHorizontal } from 'lucide-react';
import ConfigOptionGroupController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ConfigOptionGroupController';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { useQueryParams } from '@/components/app/use-query-params';
import { Button } from '@/components/ui/button';
import type { LaravelPaginated } from '@/types/app';

type GroupRow = {
    id: number;
    name: string;
    description: string | null;
    options_count: number;
    products_count: number;
    option_names: string[];
};

export default function OptionGroupsIndex({
    groups,
    canManage,
}: {
    groups: LaravelPaginated<GroupRow>;
    canManage: boolean;
}) {
    const { params } = useQueryParams();

    const columns: DataTableColumn<GroupRow>[] = [
        {
            key: 'name',
            header: 'Option group',
            primary: true,
            cell: (group) => (
                <span className="flex flex-col">
                    <span>{group.name}</span>
                    {group.description && (
                        <span className="text-xs font-normal text-muted-foreground">
                            {group.description}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'options',
            header: 'Options',
            cell: (group) =>
                group.option_names.length > 0 ? (
                    <span className="text-muted-foreground">
                        {group.option_names.join(', ')}
                        {group.options_count > group.option_names.length &&
                            ` +${group.options_count - group.option_names.length}`}
                    </span>
                ) : (
                    <span className="text-muted-foreground">None yet</span>
                ),
        },
        {
            key: 'products',
            header: 'Products',
            align: 'right',
            cell: (group) => (
                <span className="tabular-nums">{group.products_count}</span>
            ),
        },
    ];

    return (
        <>
            <Head title="Configurable options" />
            <Page>
                <PageHeader
                    title="Configurable options"
                    description="Option groups such as RAM or CPU cores, priced per billing cycle and currency, and assigned to products."
                    actions={
                        canManage && (
                            <Button asChild>
                                <Link
                                    href={ConfigOptionGroupController.create()}
                                >
                                    <Plus />
                                    New option group
                                </Link>
                            </Button>
                        )
                    }
                />
                <FilterBar searchPlaceholder="Search option groups…" />
                <DataTable
                    caption="Configurable option groups"
                    columns={columns}
                    rows={groups.data}
                    getRowKey={(group) => group.id}
                    rowHref={(group) => ConfigOptionGroupController.edit(group)}
                    footer={
                        groups.total > groups.per_page && (
                            <Pagination
                                paginator={groups}
                                itemLabel="option groups"
                            />
                        )
                    }
                    empty={
                        <EmptyState
                            icon={SlidersHorizontal}
                            title={
                                params.get('search')
                                    ? 'No option groups match this search'
                                    : 'No configurable options yet'
                            }
                            description={
                                params.get('search')
                                    ? undefined
                                    : 'Create a group like “VPS resources” with options for RAM and CPU cores.'
                            }
                        />
                    }
                />
            </Page>
        </>
    );
}
