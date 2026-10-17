import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    Boxes,
    MoreHorizontal,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import ProductGroupController from '@/actions/App/Modules/Catalog/Http/Controllers/Admin/ProductGroupController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

type Group = {
    id: number;
    name: string;
    slug: string;
    headline: string | null;
    is_hidden: boolean;
    allowed_gateways: string[] | null;
    products_count: number;
};

type Props = {
    groups: Group[];
    gatewayConflicts: [string, string][];
    /** Gateway key → display name, from Billing. */
    gatewayNames: Record<string, string>;
    canManage: boolean;
};

export default function ProductGroupsIndex({
    groups,
    gatewayConflicts,
    gatewayNames,
    canManage,
}: Props) {
    const [deleting, setDeleting] = useState<Group | null>(null);

    const move = (index: number, offset: number) => {
        const ids = groups.map((group) => group.id);
        const [moved] = ids.splice(index, 1);
        ids.splice(index + offset, 0, moved);
        router.post(
            ProductGroupController.reorder.url(),
            { ids },
            { preserveScroll: true },
        );
    };

    const columns: DataTableColumn<Group>[] = [
        {
            key: 'name',
            header: 'Group',
            primary: true,
            cell: (group) => (
                <span className="flex flex-col">
                    <span>{group.name}</span>
                    {group.headline && (
                        <span className="text-xs font-normal text-muted-foreground">
                            {group.headline}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'slug',
            header: 'Slug',
            cell: (group) => (
                <span className="font-mono text-xs text-muted-foreground">
                    {group.slug}
                </span>
            ),
        },
        {
            key: 'visibility',
            header: 'Visibility',
            cell: (group) => (
                <StatusBadge
                    status={group.is_hidden ? 'hidden' : 'active'}
                    label={group.is_hidden ? 'Hidden' : 'Visible'}
                />
            ),
        },
        {
            key: 'gateways',
            header: 'Gateways',
            cell: (group) =>
                group.allowed_gateways === null ? (
                    <span className="text-muted-foreground">All</span>
                ) : (
                    group.allowed_gateways
                        .map((key) => gatewayNames[key] ?? key)
                        .join(', ')
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

    if (canManage) {
        columns.push({
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            align: 'right',
            hideOnMobile: true,
            className: 'w-36',
            cell: (group) => {
                const index = groups.indexOf(group);

                return (
                    <div className="flex items-center justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            disabled={index === 0}
                            aria-label={`Move ${group.name} up`}
                            onClick={() => move(index, -1)}
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            disabled={index === groups.length - 1}
                            aria-label={`Move ${group.name} down`}
                            onClick={() => move(index, 1)}
                        >
                            <ArrowDown />
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Actions for ${group.name}`}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem asChild>
                                    <Link
                                        href={ProductGroupController.edit(
                                            group,
                                        )}
                                    >
                                        Edit group
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => setDeleting(group)}
                                >
                                    Delete group
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                );
            },
        });
    }

    return (
        <>
            <Head title="Product groups" />
            <Page>
                <PageHeader
                    title="Product groups"
                    description="Groups organise the store. The first visible group is the store's landing page; hidden groups are reachable only by direct link."
                    actions={
                        canManage && (
                            <Button asChild>
                                <Link href={ProductGroupController.create()}>
                                    <Plus />
                                    New group
                                </Link>
                            </Button>
                        )
                    }
                />

                {gatewayConflicts.length > 0 && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>
                            Some groups share no payment gateway
                        </AlertTitle>
                        <AlertDescription>
                            A cart containing products from{' '}
                            {gatewayConflicts
                                .map(
                                    ([first, second]) =>
                                        `${first} and ${second}`,
                                )
                                .join('; ')}{' '}
                            can't be paid. Allow a common gateway in both
                            groups.
                        </AlertDescription>
                    </Alert>
                )}

                <DataTable
                    caption="Product groups"
                    columns={columns}
                    rows={groups}
                    getRowKey={(group) => group.id}
                    rowHref={
                        canManage
                            ? (group) => ProductGroupController.edit(group)
                            : undefined
                    }
                    empty={
                        <EmptyState
                            icon={Boxes}
                            title="No product groups yet"
                            description="Create a group such as “Shared hosting” before adding products."
                            action={
                                canManage && (
                                    <Button size="sm" asChild>
                                        <Link
                                            href={ProductGroupController.create()}
                                        >
                                            <Plus />
                                            New group
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    }
                />
            </Page>

            {deleting && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setDeleting(null)}
                    title={`Delete ${deleting.name}?`}
                    description="The group is removed from the store. Groups that still contain products can't be deleted."
                    confirmLabel="Delete group"
                    action={ProductGroupController.destroy(deleting)}
                    onSuccess={() => setDeleting(null)}
                />
            )}
        </>
    );
}
