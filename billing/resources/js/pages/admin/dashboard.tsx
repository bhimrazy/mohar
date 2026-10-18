import { Head, Link } from '@inertiajs/react';
import { ArrowRight, LayoutGrid } from 'lucide-react';
import ClientController from '@/actions/App/Modules/Core/Http/Controllers/Admin/ClientController';
import ActivityLogController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/ActivityLogController';
import { ActivityFeed } from '@/components/app/activity-feed';
import type { ActivityItem } from '@/components/app/activity-feed';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { Button } from '@/components/ui/button';
import { navIcon } from '@/lib/nav-icons';
import { dashboard } from '@/routes/admin';
import type { DashboardCard } from '@/types';

type Props = {
    cards: DashboardCard[];
    activity: ActivityItem[] | null;
};

export default function AdminDashboard({ cards, activity }: Props) {
    return (
        <>
            <Head title="Dashboard" />
            <Page>
                <PageHeader
                    title="Dashboard"
                    description="What needs attention across clients, billing and support."
                />

                {cards.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {cards.map((card) => (
                            <StatCard
                                key={card.key}
                                label={card.label}
                                value={
                                    typeof card.value === 'number'
                                        ? card.value.toLocaleString()
                                        : card.value
                                }
                                hint={card.hint}
                                trend={card.trend}
                                icon={navIcon(card.icon)}
                                href={card.href}
                            />
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        variant="bordered"
                        icon={LayoutGrid}
                        title="No dashboard cards for your role"
                        description="Cards appear here for the areas your role can view."
                    />
                )}

                {activity && (
                    <SectionCard
                        title="Recent activity"
                        description="The latest changes made by staff, clients and automation."
                        flush
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={ActivityLogController.index()}>
                                    View all
                                    <ArrowRight />
                                </Link>
                            </Button>
                        }
                    >
                        <ActivityFeed
                            items={activity.map((item) => ({
                                ...item,
                                clientHref: item.client
                                    ? ClientController.show.url(item.client.id)
                                    : undefined,
                            }))}
                        />
                    </SectionCard>
                )}
            </Page>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
