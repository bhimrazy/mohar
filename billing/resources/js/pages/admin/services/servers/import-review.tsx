import { Link, setLayoutProps } from '@inertiajs/react';
import { UserRoundSearch } from 'lucide-react';
import { useState } from 'react';
import ServerController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerController';
import ServerImportController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerImportController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DataTable } from '@/components/app/data-table';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Head } from '@/components/app/head';
import { Money } from '@/components/app/money';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { ImportChoiceDialog } from '@/components/services/import-choice-dialog';
import { ImportClientFlag } from '@/components/services/import-client-flag';
import type { ImportClientFlagData } from '@/components/services/import-client-flag';
import type { ImportChoice } from '@/components/services/import-choice-dialog';
import { Button } from '@/components/ui/button';
import type { SelectOption } from '@/types/app';
import { cycleLabel } from '@/types/services';

type Row = {
    username: string;
    domain: string | null;
    suspended: boolean;
    decision: {
        client: { id: number; name: string; currency: string } | null;
        product: { id: number; name: string } | null;
        cycle: string | null;
        next_due_date: string | null;
        amount: number | null;
        list_amount: number | null;
        discount: { group: string; percent: number } | null;
        flag: ImportClientFlagData | null;
        problems: string[];
    };
};

type Props = {
    server: { id: number; name: string; hostname: string };
    rows: Row[];
    page: { current: number; last: number };
    counts: { ready: number; excluded: number; clients: number };
    totals: {
        currency: string;
        cycle: string;
        amount: number;
        accounts: number;
    }[];
    digest: string;
    cycles: SelectOption[];
};

const BLANK: ImportChoice = {
    client: null,
    product: null,
    cycle: null,
    next_due_date: null,
};

const plural = (count: number, noun: string) =>
    `${count} ${noun}${count === 1 ? '' : 's'}`;

function Account({ row }: { row: Row }) {
    return (
        <span className="flex flex-col">
            <span className="font-mono">{row.username}</span>
            <span className="text-xs font-normal text-muted-foreground">
                {row.domain ?? 'No domain'}
            </span>
        </span>
    );
}

export default function ServerImportReview({
    server,
    rows,
    page,
    counts,
    totals,
    digest,
    cycles,
}: Props) {
    const [confirming, setConfirming] = useState(false);
    const [bulk, setBulk] = useState(false);
    const change = (row: Row) =>
        ServerImportController.show(server.id, {
            query: { search: row.username },
        });
    const back = ServerImportController.show(server.id);
    const ready = rows.filter((row) => row.decision.problems.length === 0);
    const excluded = rows.filter((row) => row.decision.problems.length > 0);
    const pageLink = (to: number) =>
        ServerImportController.review(server.id, { query: { page: to } });

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
                    meta={<span className="font-mono">{server.hostname}</span>}
                    description="Check every row: matches come from the contact email the server reports, which the account holder can change. Each account becomes a service for its client, active or suspended as on the server. No invoice, welcome email or server change follows; clients set a password themselves."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                disabled={counts.ready === 0}
                                onClick={() => setBulk(true)}
                            >
                                Set cycle and due date
                            </Button>
                            <Button
                                disabled={counts.ready === 0}
                                title={
                                    counts.ready === 0
                                        ? 'No account is ready to import.'
                                        : undefined
                                }
                                onClick={() => setConfirming(true)}
                            >
                                Import {plural(counts.ready, 'account')}
                            </Button>
                        </>
                    }
                />

                {totals.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Recurring:{' '}
                        {totals.map((total, index) => (
                            <span key={`${total.currency}:${total.cycle}`}>
                                {index > 0 && ' · '}
                                <Money
                                    amount={total.amount}
                                    currency={total.currency}
                                    className="font-medium text-foreground"
                                />{' '}
                                {cycleLabel(total.cycle).toLowerCase()} (
                                {plural(total.accounts, 'account')})
                            </span>
                        ))}
                        , for {plural(counts.clients, 'client')}.
                    </p>
                )}

                {excluded.length > 0 && (
                    <SectionCard
                        title={`${counts.excluded} not imported`}
                        description="These ticked accounts are left out until the reason shown is fixed on the import page or in the catalog."
                    >
                        <ul className="grid gap-2 text-sm">
                            {excluded.map((row) => (
                                <li
                                    key={row.username}
                                    className="flex flex-col gap-0.5 sm:flex-row sm:gap-3"
                                >
                                    <span className="font-mono sm:w-32">
                                        {row.username}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {row.decision.flag && (
                                            <>
                                                <ImportClientFlag
                                                    flag={row.decision.flag}
                                                    className=""
                                                >
                                                    .
                                                </ImportClientFlag>{' '}
                                            </>
                                        )}
                                        {row.decision.problems.join(' ')}{' '}
                                        <Link
                                            href={change(row)}
                                            className="text-primary hover:underline"
                                        >
                                            Change
                                        </Link>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </SectionCard>
                )}

                <DataTable
                    caption="Accounts to import"
                    columns={[
                        {
                            key: 'account',
                            header: 'Account',
                            primary: true,
                            cell: (row) => <Account row={row} />,
                        },
                        {
                            key: 'client',
                            header: 'Client and product',
                            cell: (row) => (
                                <span className="flex min-w-0 flex-col whitespace-normal">
                                    <span>{row.decision.client?.name}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {row.decision.product?.name}
                                    </span>
                                    {row.decision.flag && (
                                        <ImportClientFlag
                                            flag={row.decision.flag}
                                        >
                                            ; you chose this client.
                                        </ImportClientFlag>
                                    )}
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
                            cell: (row) => (
                                <span className="flex min-w-0 flex-col break-words whitespace-normal">
                                    <span>
                                        <Money
                                            amount={row.decision.amount ?? 0}
                                            currency={
                                                row.decision.client?.currency ??
                                                'USD'
                                            }
                                        />{' '}
                                        {cycleLabel(
                                            row.decision.cycle ?? '',
                                        ).toLowerCase()}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        Next due{' '}
                                        <DateTime
                                            value={row.decision.next_due_date}
                                        />
                                        {' · '}
                                        <Link
                                            href={change(row)}
                                            className="text-primary hover:underline"
                                        >
                                            Change
                                        </Link>
                                    </span>
                                    {row.decision.list_amount !== null && (
                                        <span className="text-xs text-muted-foreground">
                                            {row.decision.discount
                                                ? `${row.decision.discount.group} group, ${row.decision.discount.percent}% off`
                                                : 'Client group discount'}{' '}
                                            (list price{' '}
                                            <Money
                                                amount={
                                                    row.decision.list_amount
                                                }
                                                currency={
                                                    row.decision.client
                                                        ?.currency ?? 'USD'
                                                }
                                            />
                                            )
                                        </span>
                                    )}
                                </span>
                            ),
                        },
                    ]}
                    rows={ready}
                    getRowKey={(row) => row.username}
                    empty={
                        <EmptyState
                            icon={UserRoundSearch}
                            title={
                                counts.ready === 0
                                    ? 'Nothing ready to import'
                                    : 'No ready accounts on this page'
                            }
                            description="Tick accounts with a matched client and product on the import page."
                            action={
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={back}>Choose accounts</Link>
                                </Button>
                            }
                        />
                    }
                    footer={
                        page.last > 1 && (
                            <p className="flex gap-4 px-4 py-3 text-sm">
                                Page {page.current} of {page.last}
                                {page.current > 1 && (
                                    <Link
                                        href={pageLink(page.current - 1)}
                                        className="text-primary hover:underline"
                                    >
                                        Previous
                                    </Link>
                                )}
                                {page.current < page.last && (
                                    <Link
                                        href={pageLink(page.current + 1)}
                                        className="text-primary hover:underline"
                                    >
                                        Next
                                    </Link>
                                )}
                            </p>
                        )
                    }
                />
            </Page>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Import ${plural(counts.ready, 'account')} from ${server.name}?`}
                description={`This creates ${plural(counts.ready, 'service')} for ${plural(counts.clients, 'client')}, billed from their next due dates, exactly as reviewed here. No invoices or emails are sent and nothing changes on the server. The import runs in the background; the import page shows its progress and report.`}
                confirmLabel={`Import ${plural(counts.ready, 'account')}`}
                destructive={false}
                action={ServerImportController.apply(server.id)}
                data={{ digest }}
            />
            <ImportChoiceDialog
                serverId={server.id}
                usernames={[]}
                ticked
                count={counts.ready + counts.excluded}
                initial={bulk ? BLANK : null}
                onClose={() => setBulk(false)}
                productOptions={[]}
                cycles={cycles}
                datesOnly
            />
        </>
    );
}
