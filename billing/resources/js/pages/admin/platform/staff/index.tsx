import { Head, Link, router } from '@inertiajs/react';
import {
    MailPlus,
    Send,
    ShieldCheck,
    ShieldOff,
    Trash2,
    UserPlus,
    Users,
} from 'lucide-react';
import StaffController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/StaffController';
import StaffInvitationController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/StaffInvitationController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { FilterBar, FilterSelect } from '@/components/app/filter-bar';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import type { LaravelPaginated } from '@/types';

type StaffRow = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    status: string;
    two_factor: boolean;
    last_login_at: string | null;
    is_self: boolean;
};

type Invitation = {
    id: number;
    email: string;
    name: string | null;
    role: string;
    expires_at: string;
    expired: boolean;
};

type Props = {
    twoFactorRequired: boolean;
    staff: LaravelPaginated<StaffRow>;
    invitations: Invitation[];
    filters: { search?: string; status?: string; two_factor?: string };
    can: { manage: boolean };
};

const staffColumns = (
    twoFactorRequired: boolean,
): DataTableColumn<StaffRow>[] => [
    {
        key: 'name',
        header: 'Name',
        primary: true,
        cell: (row) => (
            <span className="flex min-w-0 flex-col">
                <span className="truncate">
                    {row.name}
                    {row.is_self && (
                        <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                            (you)
                        </span>
                    )}
                </span>
                <span className="truncate text-xs font-normal text-muted-foreground">
                    {row.email}
                </span>
            </span>
        ),
    },
    {
        key: 'role',
        header: 'Role',
        cell: (row) =>
            row.role ?? <span className="text-muted-foreground">—</span>,
    },
    {
        key: '2fa',
        header: '2FA',
        cell: (row) =>
            row.two_factor ? (
                <span className="inline-flex items-center gap-1 text-sm">
                    <ShieldCheck
                        className="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    On
                </span>
            ) : twoFactorRequired ? (
                <StatusBadge
                    status="required"
                    tone="warning"
                    label="Off — required"
                />
            ) : (
                <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                    <ShieldOff className="size-4" aria-hidden="true" />
                    Off
                </span>
            ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (row) => <StatusBadge status={row.status} />,
    },
    {
        key: 'last_login',
        header: 'Last sign-in',
        hideOnMobile: true,
        cell: (row) => (
            <DateTime value={row.last_login_at} relative fallback="Never" />
        ),
    },
];

export default function StaffIndex({
    twoFactorRequired,
    staff,
    invitations,
    filters,
    can,
}: Props) {
    const invitationColumns: DataTableColumn<Invitation>[] = [
        {
            key: 'email',
            header: 'Email',
            primary: true,
            cell: (invitation) => (
                <span className="flex min-w-0 flex-col">
                    <span className="truncate">{invitation.email}</span>
                    {invitation.name && (
                        <span className="truncate text-xs font-normal text-muted-foreground">
                            {invitation.name}
                        </span>
                    )}
                </span>
            ),
        },
        { key: 'role', header: 'Role', cell: (invitation) => invitation.role },
        {
            key: 'expires',
            header: 'Expires',
            cell: (invitation) =>
                invitation.expired ? (
                    <StatusBadge status="expired" />
                ) : (
                    <DateTime value={invitation.expires_at} relative />
                ),
        },
        ...(can.manage
            ? [
                  {
                      key: 'actions',
                      header: <span className="sr-only">Actions</span>,
                      align: 'right' as const,
                      hideOnMobile: true,
                      cell: (invitation: Invitation) => (
                          <div className="flex justify-end gap-1">
                              <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() =>
                                      router.visit(
                                          StaffInvitationController.resend.url(
                                              invitation.id,
                                          ),
                                          {
                                              method: 'post',
                                              preserveScroll: true,
                                          },
                                      )
                                  }
                              >
                                  <Send />
                                  Resend
                              </Button>
                              <ConfirmDialog
                                  title={`Cancel the invitation for ${invitation.email}?`}
                                  description="The link in their email stops working."
                                  confirmLabel="Cancel invitation"
                                  cancelLabel="Keep invitation"
                                  action={StaffInvitationController.destroy(
                                      invitation.id,
                                  )}
                                  trigger={
                                      <Button
                                          variant="ghost"
                                          size="icon"
                                          aria-label={`Cancel invitation for ${invitation.email}`}
                                      >
                                          <Trash2 />
                                      </Button>
                                  }
                              />
                          </div>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Staff" />
            <Page>
                <PageHeader
                    title="Staff"
                    description="Admin accounts, their roles and sign-in security."
                    actions={
                        can.manage && (
                            <Button asChild>
                                <Link href={StaffController.create()}>
                                    <UserPlus />
                                    Add staff
                                </Link>
                            </Button>
                        )
                    }
                />

                <FilterBar
                    searchPlaceholder="Search name or email…"
                    filterKeys={['status', 'two_factor']}
                    only={['staff', 'filters']}
                    filters={
                        <>
                            <FilterSelect
                                name="status"
                                label="Status"
                                allLabel="All statuses"
                                only={['staff', 'filters']}
                                options={[
                                    { value: 'active', label: 'Active' },
                                    { value: 'inactive', label: 'Inactive' },
                                ]}
                            />
                            <FilterSelect
                                name="two_factor"
                                label="Two-factor"
                                allLabel="Any 2FA"
                                only={['staff', 'filters']}
                                options={[
                                    { value: 'on', label: '2FA on' },
                                    { value: 'off', label: '2FA off' },
                                ]}
                            />
                        </>
                    }
                />

                <DataTable
                    caption="Staff"
                    columns={staffColumns(twoFactorRequired)}
                    rows={staff.data}
                    getRowKey={(row) => row.id}
                    rowHref={
                        can.manage
                            ? (row) => StaffController.edit(row.id)
                            : undefined
                    }
                    empty={
                        <EmptyState
                            icon={Users}
                            title={
                                filters.search ||
                                filters.status ||
                                filters.two_factor
                                    ? 'No staff match these filters'
                                    : 'No staff yet'
                            }
                        />
                    }
                    footer={
                        <Pagination
                            paginator={staff}
                            itemLabel="staff"
                            only={['staff', 'filters']}
                        />
                    }
                />

                {invitations.length > 0 && (
                    <SectionCard
                        title="Pending invitations"
                        description="Invitation links are valid for 7 days. Resend to issue a fresh link."
                        flush
                    >
                        <DataTable
                            className="rounded-none border-0 shadow-none"
                            caption="Pending invitations"
                            columns={invitationColumns}
                            rows={invitations}
                            getRowKey={(invitation) => invitation.id}
                            empty={
                                <EmptyState
                                    icon={MailPlus}
                                    title="No pending invitations"
                                />
                            }
                        />
                    </SectionCard>
                )}
            </Page>
        </>
    );
}

StaffIndex.layout = {
    breadcrumbs: [{ title: 'Staff', href: StaffController.index() }],
};
