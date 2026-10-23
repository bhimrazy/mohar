import { Link } from '@inertiajs/react';
import { CircleAlert, Receipt, Sparkles } from 'lucide-react';
import { Head } from '@/components/app/head';
import type {
    ListWidgetData,
    NoticeWidgetData,
} from '@/components/app/dashboard/types';
import { EmptyState } from '@/components/app/empty-state';
import { ListWidget } from '@/components/app/list-widget';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { StatCard } from '@/components/app/stat-card';
import { toneSurface } from '@/components/app/status-badge';
import { Button } from '@/components/ui/button';
import { navIcon } from '@/lib/nav-icons';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes/client';
import type { ClientRecord, DashboardCard } from '@/types';

type Props = {
    client: ClientRecord;
    notices: NoticeWidgetData[];
    cards: DashboardCard[];
    panels: ListWidgetData[];
};

function Notice({ notice }: { notice: NoticeWidgetData }) {
    const Icon = notice.tone === 'danger' ? CircleAlert : Receipt;

    return (
        <section
            role={notice.tone === 'danger' ? 'alert' : 'status'}
            data-test={`notice-${notice.key}`}
            className={cn(
                'flex flex-col gap-4 rounded-xl border px-4 py-4 sm:flex-row sm:items-center sm:px-(--card-p)',
                toneSurface(notice.tone),
            )}
        >
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-lg border border-current/20 bg-card/60">
                    <Icon className="size-4" aria-hidden="true" />
                </span>
                <div className="min-w-0 space-y-0.5">
                    <h2 className="font-medium text-foreground">
                        {notice.title}
                    </h2>
                    {notice.body && (
                        <p className="text-sm text-muted-foreground">
                            {notice.body}
                        </p>
                    )}
                </div>
            </div>
            {notice.actions.length > 0 && (
                <div className="flex shrink-0 flex-wrap gap-2">
                    {notice.actions.map((action) => (
                        <Button
                            key={action.label}
                            asChild
                            variant={action.primary ? 'default' : 'outline'}
                        >
                            <Link href={action.href}>{action.label}</Link>
                        </Button>
                    ))}
                </div>
            )}
        </section>
    );
}

function PanelEmpty({ panel }: { panel: ListWidgetData }) {
    return (
        <div className="flex flex-col items-center gap-3">
            <p>{panel.empty ?? 'Nothing here yet.'}</p>
            {panel.emptyAction && (
                <Button asChild variant="outline" size="sm">
                    <Link href={panel.emptyAction.href}>
                        {panel.emptyAction.label}
                    </Link>
                </Button>
            )}
        </div>
    );
}

export default function ClientDashboard({
    client,
    notices,
    cards,
    panels,
}: Props) {
    const nothing =
        notices.length === 0 && cards.length === 0 && panels.length === 0;

    return (
        <>
            <Head title="Dashboard" />
            <Page>
                <PageHeader
                    title={`Welcome back, ${client.first_name}`}
                    description={
                        client.company_name
                            ? `You're managing ${client.company_name.replace(/\.$/, '')}.`
                            : 'Everything about your account in one place.'
                    }
                />

                {notices.map((notice) => (
                    <Notice key={notice.key} notice={notice} />
                ))}

                {cards.length > 0 && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {cards.map((card) => (
                            <StatCard
                                key={card.key}
                                label={card.label}
                                value={card.value}
                                hint={card.hint}
                                icon={navIcon(card.icon)}
                                href={card.href}
                            />
                        ))}
                    </div>
                )}

                {panels.length > 0 && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {panels.map((panel) => (
                            <ListWidget
                                key={panel.key}
                                title={panel.title}
                                items={panel.items.map((item) => ({
                                    ...item,
                                    href: item.href ?? undefined,
                                    meta: item.meta ?? undefined,
                                    status: item.status ?? undefined,
                                }))}
                                href={panel.href}
                                empty={<PanelEmpty panel={panel} />}
                                // A lone last panel (a module not installed) takes the full row.
                                className="lg:[&:last-child:nth-child(odd)]:col-span-2"
                            />
                        ))}
                    </div>
                )}

                {nothing && (
                    <EmptyState
                        variant="bordered"
                        icon={Sparkles}
                        title="You're all set"
                        description="Your services, domains, invoices and tickets will show up here once you have them."
                    />
                )}
            </Page>
        </>
    );
}

ClientDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
