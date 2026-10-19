import { Link, setLayoutProps } from '@inertiajs/react';
import { CalendarClock, UserRoundSearch } from 'lucide-react';
import { useState } from 'react';
import ServerImportController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerImportController';
import ServerController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Head } from '@/components/app/head';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatusBadge } from '@/components/app/status-badge';
import { ImportChoiceDialog } from '@/components/services/import-choice-dialog';
import type { ImportChoice } from '@/components/services/import-choice-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import type { SelectOption } from '@/types/app';
import { cycleLabel } from '@/types/services';

type Row = {
    username: string;
    domain: string | null;
    suspended: boolean;
    decision: ImportChoice & { amount: number | null; problems: string[] };
};

const BLANK: ImportChoice = {
    client: null,
    product: null,
    cycle: null,
    next_due_date: null,
};

type Props = {
    server: { id: number; name: string; hostname: string };
    rows: Row[];
    totals: { currency: string; amount: number }[];
    cycles: SelectOption[];
};

export default function ServerImportReview({
    server,
    rows,
    totals,
    cycles,
}: Props) {
    const [confirming, setConfirming] = useState(false);
    const [bulk, setBulk] = useState(false);
    const ready = rows.filter((row) => row.decision.problems.length === 0);
    const blocked = rows.length - ready.length;
    const clients = new Set(ready.map((row) => row.decision.client?.id)).size;
    const back = ServerImportController.show(server.id);

    setLayoutProps({
        breadcrumbs: [
            { title: 'Servers', href: ServerController.index().url },
            { title: server.name, href: ServerController.edit(server.id).url },
            { title: 'Import accounts', href: back.url },
        ],
    });

    return (
        <>
            <Head title="Review import" />
            <Page>
                <PageHeader
                    back={{ href: back, label: 'Import accounts' }}
                    title="Review import"
                    meta={
                        <>
                            <span>{server.name}</span>
                            <span aria-hidden="true">·</span>
                            <span className="font-mono">{server.hostname}</span>
                        </>
                    }
                    description="Check every row: matches come from the contact email the server reports, which the account holder can change. Each account becomes a service for its client, active or suspended as on the server. No invoice, welcome email or server change follows; clients set a password themselves."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                disabled={rows.length === 0}
                                onClick={() => setBulk(true)}
                            >
                                <CalendarClock />
                                Set cycle and due date
                            </Button>
                            <Button
                                disabled={ready.length === 0}
                                title={
                                    ready.length === 0
                                        ? 'No account is ready to import.'
                                        : undefined
                                }
                                onClick={() => setConfirming(true)}
                            >
                                Import {ready.length} accounts
                            </Button>
                        </>
                    }
                />

                {blocked > 0 && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            {blocked}{' '}
                            {blocked === 1 ? 'account is' : 'accounts are'} left
                            out until the problem shown on its row is fixed.
                        </AlertDescription>
                    </Alert>
                )}

                {totals.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Recurring total:{' '}
                        {totals.map((total, index) => (
                            <span key={total.currency}>
                                {index > 0 && ' + '}
                                <Money
                                    amount={total.amount}
                                    currency={total.currency}
                                    className="font-medium text-foreground"
                                />
                            </span>
                        ))}{' '}
                        across {clients} {clients === 1 ? 'client' : 'clients'},
                        each at its own cycle.
                    </p>
                )}

                <DataTable
                    caption="Accounts to import"
                    columns={[
                        {
                            key: 'account',
                            header: 'Account',
                            primary: true,
                            cell: (row) => (
                                <span className="flex flex-col">
                                    <span className="font-mono">
                                        {row.username}
                                    </span>
                                    <span className="text-xs font-normal text-muted-foreground">
                                        {row.domain ?? 'No domain'}
                                    </span>
                                </span>
                            ),
                        },
                        {
                            key: 'client',
                            header: 'Client and product',
                            cell: (row) => (
                                <span className="flex min-w-0 flex-col whitespace-normal">
                                    <span>
                                        {row.decision.client?.name ??
                                            'No client'}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {row.decision.product?.name ??
                                            'No product'}
                                    </span>
                                </span>
                            ),
                        },
                        {
                            key: 'status',
                            header: 'Becomes',
                            cell: (row) => (
                                <StatusBadge
                                    status={
                                        row.suspended ? 'suspended' : 'active'
                                    }
                                />
                            ),
                        },
                        {
                            key: 'billing',
                            header: 'Billing',
                            cell: (row) =>
                                row.decision.problems.length > 0 ? (
                                    <span className="block max-w-xs text-sm whitespace-normal text-destructive">
                                        {row.decision.problems.join(' ')}
                                    </span>
                                ) : (
                                    <span className="flex flex-col">
                                        <span>
                                            <Money
                                                amount={
                                                    row.decision.amount ?? 0
                                                }
                                                currency={
                                                    row.decision.client
                                                        ?.currency ?? 'USD'
                                                }
                                            />{' '}
                                            {cycleLabel(
                                                row.decision.cycle ?? '',
                                            ).toLowerCase()}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            Next due{' '}
                                            <DateTime
                                                value={
                                                    row.decision.next_due_date
                                                }
                                            />
                                        </span>
                                    </span>
                                ),
                        },
                    ]}
                    rows={rows}
                    getRowKey={(row) => row.username}
                    empty={
                        <EmptyState
                            icon={UserRoundSearch}
                            title="No accounts ticked"
                            description="Tick the accounts to import on the import page first."
                            action={
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={back}>Choose accounts</Link>
                                </Button>
                            }
                        />
                    }
                />
            </Page>

            <ImportChoiceDialog
                serverId={server.id}
                usernames={rows.map((row) => row.username)}
                initial={bulk ? BLANK : null}
                onClose={() => setBulk(false)}
                productOptions={[]}
                cycles={cycles}
                datesOnly
            />

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Import ${ready.length} accounts from ${server.name}?`}
                description={`This creates ${ready.length} services for ${clients} ${clients === 1 ? 'client' : 'clients'}, billed from their next due dates. No invoices or emails are sent and nothing changes on the server. Imported services can be deleted later; the accounts stay on the server.`}
                confirmLabel={`Import ${ready.length} accounts`}
                destructive={false}
                action={ServerImportController.apply(server.id)}
            />
        </>
    );
}
