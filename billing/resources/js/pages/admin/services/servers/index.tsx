import { Link, router, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import {
    Boxes,
    MoreHorizontal,
    Plus,
    Server as ServerIcon,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ServerController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerController';
import ServerGroupController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerGroupController';
import ServerImportController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerImportController';
import { index as servicesIndex } from '@/routes/admin/services';
import { index as moduleLogIndex } from '@/routes/admin/services/module-log';
import type { SelectOption } from '@/types/app';
import type { ServerRow } from '@/types/services';

type GroupRow = {
    id: number;
    name: string;
    fill_type: string;
    fill_type_label: string;
    servers_count: number;
};

type Summary = {
    servers: number;
    active: number;
    accounts: number;
    /** Sum of the active servers' limits; `unlimited` when one of them has none. */
    capacity: number;
    unlimited: boolean;
    near_full: number;
    failed_commands: number;
    failing_servers: number;
};

type Props = {
    servers: ServerRow[];
    summary: Summary;
    groups: GroupRow[];
    drivers: SelectOption[];
    canManage: boolean;
    canViewModuleLog: boolean;
    /** Driver keys whose servers can list their accounts for import; empty without the import permission. */
    importDrivers: string[];
};

function Capacity({ server }: { server: ServerRow }) {
    const used = server.active_accounts ?? 0;
    const percent = server.max_accounts
        ? Math.round((used / server.max_accounts) * 100)
        : null;

    return (
        <span className="tabular-nums">
            {used}
            <span className="text-muted-foreground">
                {' '}
                / {server.max_accounts || '∞'}
                {percent !== null && ` · ${percent}%`}
            </span>
        </span>
    );
}

export default function ServersIndex({
    servers,
    summary,
    groups,
    drivers,
    canManage,
    canViewModuleLog,
    importDrivers,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [deleting, setDeleting] = useState<ServerRow | null>(null);
    const [deletingGroup, setDeletingGroup] = useState<GroupRow | null>(null);
    const driverName = (key: string) =>
        drivers.find((driver) => driver.value === key)?.label ?? key;

    const columns: DataTableColumn<ServerRow>[] = [
        {
            key: 'name',
            header: 'Server',
            primary: true,
            cell: (server) => (
                <span className="flex flex-col">
                    <span>
                        {server.name}
                        {server.is_default && (
                            <span className="ml-2 text-xs font-normal text-muted-foreground">
                                default
                            </span>
                        )}
                    </span>
                    <span className="font-mono text-xs font-normal text-muted-foreground">
                        {server.hostname}
                    </span>
                </span>
            ),
        },
        {
            key: 'driver',
            header: 'Driver',
            cell: (server) => driverName(server.driver),
        },
        {
            key: 'groups',
            header: 'Groups',
            hideOnMobile: true,
            cell: (server) =>
                server.groups?.map((group) => group.name).join(', ') || (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'accounts',
            header: 'Accounts',
            align: 'right',
            cell: (server) => <Capacity server={server} />,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (server) => (
                <StatusBadge
                    status={server.is_active ? 'active' : 'inactive'}
                />
            ),
        },
        ...(canManage || importDrivers.length > 0
            ? [
                  {
                      key: 'actions',
                      header: <span className="sr-only">Actions</span>,
                      align: 'right' as const,
                      hideOnMobile: true,
                      cell: (server: ServerRow) => (
                          <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                  <Button
                                      variant="ghost"
                                      size="icon"
                                      aria-label={`Actions for ${server.name}`}
                                  >
                                      <MoreHorizontal />
                                  </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                  {importDrivers.includes(server.driver) && (
                                      <DropdownMenuItem asChild>
                                          <Link
                                              href={ServerImportController.show(
                                                  server.id,
                                              )}
                                          >
                                              Import accounts
                                          </Link>
                                      </DropdownMenuItem>
                                  )}
                                  {canManage && (
                                      <>
                                          <DropdownMenuItem
                                              onSelect={() =>
                                                  router.post(
                                                      ServerController.test(
                                                          server.id,
                                                      ).url,
                                                      {},
                                                      { preserveScroll: true },
                                                  )
                                              }
                                          >
                                              Test connection
                                          </DropdownMenuItem>
                                          <DropdownMenuItem asChild>
                                              <Link
                                                  href={servicesIndex({
                                                      query: {
                                                          server: server.id,
                                                      },
                                                  })}
                                              >
                                                  View services
                                              </Link>
                                          </DropdownMenuItem>
                                          <DropdownMenuSeparator />
                                          <DropdownMenuItem
                                              variant="destructive"
                                              onSelect={() =>
                                                  setDeleting(server)
                                              }
                                          >
                                              Delete server
                                          </DropdownMenuItem>
                                      </>
                                  )}
                              </DropdownMenuContent>
                          </DropdownMenu>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Servers" />
            <Page>
                <PageHeader
                    title="Servers"
                    description="Provisioning targets and the groups that decide where new accounts are created."
                    actions={
                        canManage && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={ServerGroupController.create()}>
                                        <Boxes />
                                        New group
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link href={ServerController.create()}>
                                        <Plus />
                                        New server
                                    </Link>
                                </Button>
                            </>
                        )
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Servers"
                        value={summary.active}
                        hint={`active of ${summary.servers}`}
                    />
                    <StatCard
                        label="Accounts"
                        value={summary.accounts}
                        hint={
                            summary.unlimited || summary.capacity === 0
                                ? 'no account limit'
                                : `of ${summary.capacity} (${Math.round((summary.accounts / summary.capacity) * 100)}% used)`
                        }
                    />
                    <StatCard
                        label="Nearly full"
                        value={summary.near_full}
                        hint="active servers at 90% of their limit or more"
                        tone={summary.near_full > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Failed commands, 24 h"
                        value={summary.failed_commands}
                        hint={
                            summary.failed_commands > 0
                                ? `on ${summary.failing_servers} ${summary.failing_servers === 1 ? 'server' : 'servers'}`
                                : 'no failures'
                        }
                        tone={
                            summary.failed_commands > 0 ? 'danger' : 'default'
                        }
                        href={
                            canViewModuleLog
                                ? moduleLogIndex({
                                      query: { result: 'failed' },
                                  })
                                : undefined
                        }
                    />
                </div>

                {errors.server && (
                    <Alert variant="destructive">
                        <AlertDescription>{errors.server}</AlertDescription>
                    </Alert>
                )}

                <DataTable
                    caption="Servers"
                    columns={columns}
                    rows={servers}
                    getRowKey={(server) => server.id}
                    rowHref={
                        canManage
                            ? (server) => ServerController.edit(server.id)
                            : undefined
                    }
                    empty={
                        <EmptyState
                            icon={ServerIcon}
                            title="No servers yet"
                            description="Add a server so new accounts can be provisioned automatically."
                            action={
                                canManage && (
                                    <Button size="sm" asChild>
                                        <Link href={ServerController.create()}>
                                            <Plus />
                                            New server
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    }
                />

                <SectionCard
                    title="Server groups"
                    description="Products pick a group; the group's fill type picks the server."
                    flush
                >
                    <DataTable
                        className="rounded-none border-0 shadow-none"
                        caption="Server groups"
                        columns={[
                            {
                                key: 'name',
                                header: 'Group',
                                primary: true,
                                cell: (group) => group.name,
                            },
                            {
                                key: 'fill',
                                header: 'Fill type',
                                cell: (group) => group.fill_type_label,
                            },
                            {
                                key: 'servers',
                                header: 'Servers',
                                align: 'right',
                                cell: (group) => group.servers_count,
                            },
                            ...(canManage
                                ? [
                                      {
                                          key: 'actions',
                                          header: (
                                              <span className="sr-only">
                                                  Actions
                                              </span>
                                          ),
                                          align: 'right' as const,
                                          hideOnMobile: true,
                                          cell: (group: GroupRow) => (
                                              <Button
                                                  variant="ghost"
                                                  size="sm"
                                                  onClick={() =>
                                                      setDeletingGroup(group)
                                                  }
                                              >
                                                  Delete
                                              </Button>
                                          ),
                                      },
                                  ]
                                : []),
                        ]}
                        rows={groups}
                        getRowKey={(group) => group.id}
                        rowHref={
                            canManage
                                ? (group) =>
                                      ServerGroupController.edit(group.id)
                                : undefined
                        }
                        empty={
                            <EmptyState
                                icon={Boxes}
                                title="No server groups"
                                description="Without a group, products use the default server of their driver."
                            />
                        }
                    />
                </SectionCard>
            </Page>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name ?? 'server'}?`}
                description="The server is removed from its groups. Servers that still have services cannot be deleted."
                confirmLabel="Delete server"
                action={
                    deleting ? ServerController.destroy(deleting.id) : undefined
                }
            />
            <ConfirmDialog
                open={deletingGroup !== null}
                onOpenChange={(open) => !open && setDeletingGroup(null)}
                title={`Delete ${deletingGroup?.name ?? 'group'}?`}
                description="Servers in the group are kept. Products using this group fall back to the driver's default server."
                confirmLabel="Delete group"
                action={
                    deletingGroup
                        ? ServerGroupController.destroy(deletingGroup.id)
                        : undefined
                }
            />
        </>
    );
}
