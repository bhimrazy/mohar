import { Link, router } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import {
    ArrowUpRight,
    Contact as ContactIcon,
    KeyRound,
    LogIn,
    MoreHorizontal,
    Pencil,
    Plus,
    Send,
    Trash2,
    ShieldOff,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import ActivityLogController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ActivityLogController';
import ClientContactController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ClientContactController';
import ClientLifecycleController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ClientLifecycleController';
import ClientUserController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ClientUserController';
import ImpersonationController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ImpersonationController';
import { ActivityFeed } from '@/components/app/activity-feed';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import type { DataTableColumn } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import type { DashboardWidget } from '@/components/app/dashboard/types';
import { SectionCard } from '@/components/app/section-card';
import { ClientSummary } from '@/components/clients/client-summary';
import { ContactDialog } from '@/components/clients/contact-dialog';
import { LoginDialog } from '@/components/clients/login-dialog';
import { MergeDialog } from '@/components/clients/merge-dialog';
import { AREA_LABELS, EMAIL_TYPE_LABELS } from '@/components/clients/types';
import type { ClientContact, ClientLogin } from '@/components/clients/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { countryName } from '@/lib/country';
import { navIcon } from '@/lib/nav-icons';
import type { ClientRecord } from '@/types';

/** "en" -> "English"; falls back to the stored code. */
function languageName(code: string): string {
    if (!code) {
        return code;
    }

    try {
        return (
            new Intl.DisplayNames(['en'], { type: 'language' }).of(code) ?? code
        );
    } catch {
        return code;
    }
}

type Props = {
    client: ClientRecord;
    contacts: ClientContact[];
    users: ClientLogin[];
    summary: DashboardWidget[];
    links: { title: string; href: string; icon: string }[];
    imported: {
        at: string;
        fields: { label: string; value: string; source: string }[];
    } | null;
    /** Null unless the staff member may read the activity log. */
    activity:
        | {
              id: number;
              description: string;
              actor: string | null;
              impersonator: string | null;
              created_at: string | null;
          }[]
        | null;
    areas: string[];
    emailTypes: string[];
    can: {
        update: boolean;
        close: boolean;
        merge: boolean;
        impersonate: boolean;
        manageContacts: boolean;
        manageUsers: boolean;
        resetTwoFactor: boolean;
        viewActivity: boolean;
    };
};

function address(client: ClientRecord): string {
    return [
        client.address_1,
        client.address_2,
        [client.city, client.state, client.postcode].filter(Boolean).join(' '),
        countryName(client.country),
    ]
        .filter(Boolean)
        .join(', ');
}

export default function ClientShow({
    client,
    contacts,
    users,
    summary,
    links,
    imported,
    activity,
    areas,
    emailTypes,
    can,
}: Props) {
    const [confirm, setConfirm] = useState<'close' | 'reopen' | null>(null);
    const [mergeOpen, setMergeOpen] = useState(false);
    const [editingLogin, setEditingLogin] = useState<ClientLogin | null>(null);
    /** Invitation or password reset to confirm for a login (opened from its actions menu). */
    const [accessEmail, setAccessEmail] = useState<{
        login: ClientLogin;
        kind: 'invitation' | 'reset';
    } | null>(null);
    const closed = client.status === 'closed';
    const hasMenu = (can.close || can.merge) && !client.merged_into_client_id;

    const contactColumns: DataTableColumn<ClientContact>[] = [
        {
            key: 'name',
            header: 'Name',
            primary: true,
            cell: (contact) => `${contact.first_name} ${contact.last_name}`,
        },
        { key: 'email', header: 'Email', cell: (contact) => contact.email },
        {
            key: 'emails',
            header: 'Receives',
            hideOnMobile: true,
            cell: (contact) => {
                const types = emailTypes.filter(
                    (type) => contact.email_preferences[type],
                );

                return types.length ? (
                    <span className="text-muted-foreground">
                        {types
                            .map((type) => EMAIL_TYPE_LABELS[type] ?? type)
                            .join(', ')}
                    </span>
                ) : (
                    <span className="text-muted-foreground">No email</span>
                );
            },
        },
        ...(can.manageContacts
            ? [
                  {
                      key: 'actions',
                      header: <span className="sr-only">Actions</span>,
                      align: 'right' as const,
                      cell: (contact: ClientContact) => (
                          <div className="flex justify-end gap-1">
                              <ContactDialog
                                  clientId={client.id}
                                  contact={contact}
                                  emailTypes={emailTypes}
                                  trigger={
                                      <Button
                                          variant="ghost"
                                          size="icon"
                                          aria-label={`Edit ${contact.email}`}
                                      >
                                          <Pencil />
                                      </Button>
                                  }
                              />
                              <ConfirmDialog
                                  title={`Delete contact ${contact.email}?`}
                                  description="They stop receiving email for this account."
                                  confirmLabel="Delete contact"
                                  action={ClientContactController.destroy([
                                      client.id,
                                      contact.id,
                                  ])}
                                  trigger={
                                      <Button
                                          variant="ghost"
                                          size="icon"
                                          aria-label={`Delete ${contact.email}`}
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

    const loginColumns: DataTableColumn<ClientLogin>[] = [
        {
            key: 'name',
            header: 'Login',
            primary: true,
            cell: (login) => (
                <span className="flex min-w-0 flex-col">
                    <span className="flex items-center gap-2 truncate">
                        {login.name}
                        {login.is_owner && (
                            <Badge variant="secondary">Owner</Badge>
                        )}
                        {login.invitation && (
                            <Badge variant="outline">
                                {login.invitation.state === 'expired'
                                    ? 'Invitation expired'
                                    : 'Invited'}
                            </Badge>
                        )}
                    </span>
                    <span className="truncate text-xs font-normal text-muted-foreground">
                        {login.email}
                    </span>
                </span>
            ),
        },
        {
            key: 'access',
            header: 'Access',
            cell: (login) =>
                login.is_owner ? (
                    <span className="text-muted-foreground">Everything</span>
                ) : login.permissions.length ? (
                    <span className="text-muted-foreground">
                        {login.permissions
                            .map((area) => AREA_LABELS[area] ?? area)
                            .join(', ')}
                    </span>
                ) : (
                    <span className="text-muted-foreground">No access</span>
                ),
        },
        {
            key: 'last_login',
            header: 'Last sign-in',
            hideOnMobile: true,
            cell: (login) => (
                <DateTime
                    value={login.last_login_at}
                    relative
                    fallback="Never"
                />
            ),
        },
        ...(can.manageUsers || can.resetTwoFactor
            ? [
                  {
                      key: 'actions',
                      header: <span className="sr-only">Actions</span>,
                      align: 'right' as const,
                      cell: (login: ClientLogin) => {
                          const post = (
                              url: string,
                              method: 'post' | 'delete' = 'post',
                          ) =>
                              router.visit(url, {
                                  method,
                                  preserveScroll: true,
                              });
                          const manage = can.manageUsers && !login.is_owner;

                          if (!manage && !can.resetTwoFactor) {
                              return null;
                          }

                          return (
                              <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                      <Button
                                          variant="ghost"
                                          size="icon"
                                          aria-label={`Actions for ${login.email}`}
                                      >
                                          <MoreHorizontal />
                                      </Button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                      {manage && (
                                          <>
                                              <DropdownMenuItem
                                                  onSelect={() =>
                                                      setEditingLogin(login)
                                                  }
                                              >
                                                  <KeyRound />
                                                  Edit permissions
                                              </DropdownMenuItem>
                                              {login.can_invite ? (
                                                  <DropdownMenuItem
                                                      onSelect={() =>
                                                          setAccessEmail({
                                                              login,
                                                              kind: 'invitation',
                                                          })
                                                      }
                                                  >
                                                      <Send />
                                                      {login.invitation
                                                          ? 'Send a new invitation'
                                                          : 'Send an invitation'}
                                                  </DropdownMenuItem>
                                              ) : (
                                                  <DropdownMenuItem
                                                      onSelect={() =>
                                                          setAccessEmail({
                                                              login,
                                                              kind: 'reset',
                                                          })
                                                      }
                                                  >
                                                      <Send />
                                                      Send a password reset
                                                  </DropdownMenuItem>
                                              )}
                                              {login.email_verified && (
                                                  <DropdownMenuItem
                                                      onSelect={() =>
                                                          post(
                                                              ClientUserController.transfer.url(
                                                                  [
                                                                      client.id,
                                                                      login.id,
                                                                  ],
                                                              ),
                                                          )
                                                      }
                                                  >
                                                      <UsersRound />
                                                      Make owner
                                                  </DropdownMenuItem>
                                              )}
                                          </>
                                      )}
                                      {can.resetTwoFactor && (
                                          <DropdownMenuItem
                                              onSelect={() =>
                                                  post(
                                                      ClientUserController.resetTwoFactor.url(
                                                          [client.id, login.id],
                                                      ),
                                                  )
                                              }
                                          >
                                              <ShieldOff />
                                              Reset 2FA
                                          </DropdownMenuItem>
                                      )}
                                      {manage && (
                                          <>
                                              <DropdownMenuSeparator />
                                              <DropdownMenuItem
                                                  variant="destructive"
                                                  onSelect={() =>
                                                      post(
                                                          ClientUserController.destroy.url(
                                                              [
                                                                  client.id,
                                                                  login.id,
                                                              ],
                                                          ),
                                                          'delete',
                                                      )
                                                  }
                                              >
                                                  <Trash2 />
                                                  Remove access
                                              </DropdownMenuItem>
                                          </>
                                      )}
                                  </DropdownMenuContent>
                              </DropdownMenu>
                          );
                      },
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={client.display_name} />
            <Page>
                <PageHeader
                    back={{ href: ClientController.index(), label: 'Clients' }}
                    title={client.display_name}
                    status={client.status}
                    meta={
                        <>
                            <span>Client #{client.id}</span>
                            <span aria-hidden="true">·</span>
                            <span>{client.email}</span>
                            {client.group && (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <span>{client.group.name}</span>
                                </>
                            )}
                            <span aria-hidden="true">·</span>
                            <span>
                                Since <DateTime value={client.created_at} />
                            </span>
                        </>
                    }
                    actions={
                        <>
                            {hasMenu && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label="More actions"
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {can.merge && !closed && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setMergeOpen(true)
                                                }
                                            >
                                                Merge into another client
                                            </DropdownMenuItem>
                                        )}
                                        {can.close && closed && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setConfirm('reopen')
                                                }
                                            >
                                                Reopen client
                                            </DropdownMenuItem>
                                        )}
                                        {can.close && !closed && (
                                            <>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    onSelect={() =>
                                                        setConfirm('close')
                                                    }
                                                >
                                                    Close client
                                                </DropdownMenuItem>
                                            </>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {can.update && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={ClientController.edit(client.id)}
                                    >
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.impersonate && !closed && users.length > 0 && (
                                <Button
                                    onClick={() =>
                                        router.visit(
                                            ImpersonationController.start.url(
                                                client.id,
                                            ),
                                            { method: 'post' },
                                        )
                                    }
                                >
                                    <LogIn />
                                    Sign in as client
                                </Button>
                            )}
                        </>
                    }
                />

                <ClientSummary widgets={summary} />

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <SectionCard title="Profile">
                            <DescriptionList
                                items={[
                                    {
                                        label: 'Name',
                                        value: `${client.first_name} ${client.last_name}`,
                                    },
                                    {
                                        label: 'Company',
                                        value: client.company_name,
                                    },
                                    { label: 'Email', value: client.email },
                                    { label: 'Phone', value: client.phone },
                                    {
                                        label: 'Address',
                                        value: address(client),
                                        full: true,
                                    },
                                    { label: 'Tax ID', value: client.tax_id },
                                    {
                                        label: 'Tax',
                                        value: client.tax_exempt
                                            ? 'Exempt'
                                            : 'Charged',
                                    },
                                    ...(client.notes
                                        ? [
                                              {
                                                  label: 'Notes',
                                                  value: client.notes,
                                                  full: true,
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </SectionCard>
                        {imported && (
                            <SectionCard
                                title="Imported fields"
                                description={
                                    <>
                                        Imported{' '}
                                        <DateTime value={imported.at} />.
                                        Columns of the file kept as they were.
                                    </>
                                }
                            >
                                {imported.fields.length > 0 ? (
                                    <DescriptionList
                                        items={imported.fields.map((field) => ({
                                            label: field.label,
                                            value: (
                                                <span className="flex flex-col break-words">
                                                    <span>{field.value}</span>
                                                    <span className="text-xs text-muted-foreground">
                                                        From {field.source}
                                                    </span>
                                                </span>
                                            ),
                                        }))}
                                    />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        The import kept no extra columns for
                                        this client.
                                    </p>
                                )}
                            </SectionCard>
                        )}
                        <SectionCard title="Account" className="lg:hidden">
                            <DescriptionList
                                columns={1}
                                items={[
                                    {
                                        label: 'Currency',
                                        value: (
                                            <span className="font-mono">
                                                {client.currency}
                                            </span>
                                        ),
                                    },
                                    {
                                        label: 'Client group',
                                        value: client.group?.name,
                                    },
                                    {
                                        label: 'Language',
                                        value: languageName(client.language),
                                    },
                                    ...(client.closed_at
                                        ? [
                                              {
                                                  label: 'Closed',
                                                  value: (
                                                      <DateTime
                                                          value={
                                                              client.closed_at
                                                          }
                                                      />
                                                  ),
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </SectionCard>

                        <SectionCard
                            title="Contacts"
                            description="People who get account email but cannot sign in."
                            flush
                            actions={
                                can.manageContacts && (
                                    <ContactDialog
                                        clientId={client.id}
                                        emailTypes={emailTypes}
                                        trigger={
                                            <Button variant="outline" size="sm">
                                                <Plus />
                                                Add contact
                                            </Button>
                                        }
                                    />
                                )
                            }
                        >
                            <DataTable
                                className="rounded-none border-0 shadow-none"
                                caption="Contacts"
                                columns={contactColumns}
                                rows={contacts}
                                getRowKey={(contact) => contact.id}
                                empty={
                                    <EmptyState
                                        icon={ContactIcon}
                                        title="No contacts"
                                        description="Add a contact to send invoices or support email to someone else."
                                    />
                                }
                            />
                        </SectionCard>

                        <SectionCard
                            title="Logins"
                            description="Users who can sign in to this account in the client area."
                            flush
                            actions={
                                can.manageUsers && (
                                    <LoginDialog
                                        clientId={client.id}
                                        areas={areas}
                                        trigger={
                                            <Button variant="outline" size="sm">
                                                <Plus />
                                                Add login
                                            </Button>
                                        }
                                    />
                                )
                            }
                        >
                            <DataTable
                                className="rounded-none border-0 shadow-none"
                                caption="Logins"
                                columns={loginColumns}
                                rows={users}
                                getRowKey={(login) => login.id}
                                empty={
                                    <EmptyState
                                        icon={UsersRound}
                                        title="No logins"
                                        description="This account has no client-area users yet."
                                    />
                                }
                            />
                        </SectionCard>

                        {activity && (
                            <SectionCard
                                title="Activity"
                                flush
                                actions={
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link
                                            href={ActivityLogController.index({
                                                query: { client: client.id },
                                            })}
                                        >
                                            View all
                                        </Link>
                                    </Button>
                                }
                            >
                                <ActivityFeed
                                    items={activity}
                                    emptyText="Changes to this account appear here."
                                />
                            </SectionCard>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <SectionCard
                            title="Account"
                            className="hidden lg:block"
                        >
                            <DescriptionList
                                columns={1}
                                items={[
                                    {
                                        label: 'Currency',
                                        value: (
                                            <span className="font-mono">
                                                {client.currency}
                                            </span>
                                        ),
                                    },
                                    {
                                        label: 'Client group',
                                        value: client.group?.name,
                                    },
                                    {
                                        label: 'Language',
                                        value: languageName(client.language),
                                    },
                                    ...(client.closed_at
                                        ? [
                                              {
                                                  label: 'Closed',
                                                  value: (
                                                      <DateTime
                                                          value={
                                                              client.closed_at
                                                          }
                                                      />
                                                  ),
                                              },
                                          ]
                                        : []),
                                ]}
                            />
                        </SectionCard>

                        <SectionCard title="Quick links" flush>
                            {links.length ? (
                                <ul className="divide-y">
                                    {links.map((link) => {
                                        const Icon = navIcon(link.icon);

                                        return (
                                            <li key={link.href}>
                                                <Link
                                                    href={link.href}
                                                    className="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-muted/50 sm:px-6"
                                                >
                                                    <Icon
                                                        className="size-4 text-muted-foreground"
                                                        aria-hidden="true"
                                                    />
                                                    <span className="flex-1">
                                                        {link.title}
                                                    </span>
                                                    <ArrowUpRight
                                                        className="size-4 text-muted-foreground"
                                                        aria-hidden="true"
                                                    />
                                                </Link>
                                            </li>
                                        );
                                    })}
                                </ul>
                            ) : (
                                <p className="px-4 py-4 text-sm text-muted-foreground sm:px-6">
                                    Services, domains, invoices and tickets
                                    appear here as those modules are enabled.
                                </p>
                            )}
                        </SectionCard>
                    </div>
                </div>
            </Page>

            {accessEmail && (
                <ConfirmDialog
                    key={`${accessEmail.kind}-${accessEmail.login.id}`}
                    open
                    onOpenChange={(open) => !open && setAccessEmail(null)}
                    title={
                        accessEmail.kind === 'invitation'
                            ? `Send ${accessEmail.login.invitation ? 'a new' : 'an'} invitation to ${accessEmail.login.email}?`
                            : `Send a password reset to ${accessEmail.login.email}?`
                    }
                    description={
                        accessEmail.kind === 'invitation'
                            ? accessEmail.login.invitation
                                ? 'The link in the earlier invitation stops working. The new one works once, for the number of days set for invitations.'
                                : 'Its link lets them choose a password once, for the number of days set for invitations.'
                            : 'They get the usual password reset email. Their current password keeps working until they choose a new one.'
                    }
                    confirmLabel={
                        accessEmail.kind === 'invitation'
                            ? 'Send invitation'
                            : 'Send reset link'
                    }
                    destructive={false}
                    action={
                        accessEmail.kind === 'invitation'
                            ? ClientUserController.resendInvitation([
                                  client.id,
                                  accessEmail.login.id,
                              ])
                            : ClientUserController.sendPasswordReset([
                                  client.id,
                                  accessEmail.login.id,
                              ])
                    }
                    onSuccess={() => setAccessEmail(null)}
                />
            )}

            {editingLogin && (
                <LoginDialog
                    key={editingLogin.id}
                    clientId={client.id}
                    areas={areas}
                    login={editingLogin}
                    open
                    onOpenChange={(open) => !open && setEditingLogin(null)}
                />
            )}

            <MergeDialog
                client={client}
                open={mergeOpen}
                onOpenChange={setMergeOpen}
            />

            <ConfirmDialog
                open={confirm === 'close'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Close ${client.display_name}?`}
                description="Unpaid invoices and services are cancelled and domains stop auto-renewing. Servers are not terminated. Logins can no longer use this account. You can reopen it later."
                confirmLabel="Close client"
                action={ClientLifecycleController.close(client.id)}
            />
            <ConfirmDialog
                open={confirm === 'reopen'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Reopen ${client.display_name}?`}
                description="The account becomes active again. Cancelled invoices and services are not restored."
                confirmLabel="Reopen client"
                destructive={false}
                action={ClientLifecycleController.reopen(client.id)}
            />
        </>
    );
}

ClientShow.layout = {
    breadcrumbs: [{ title: 'Clients', href: ClientController.index() }],
};
