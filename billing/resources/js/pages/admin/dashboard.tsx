import { Head, usePage } from '@inertiajs/react';
import {
    ReportingCurrencyProvider,
    variantCodes,
} from '@/components/app/dashboard/reporting-currency';
import { DashboardWidgets } from '@/components/app/dashboard-widgets';
import type {
    DashboardWidget,
    HealthCheck,
} from '@/components/app/dashboard-widgets';
import type { TodoItem } from '@/components/app/needs-attention';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { QuickCreate } from '@/components/quick-create';
import { formatDate } from '@/lib/date';
import { dashboard } from '@/routes/admin';

/**
 * Props from Core's Admin\DashboardController (kernel Dashboard registry): eager `widgets` and `todos` (Needs
 * attention), then `deferredWidgets` and `health` (system health) in one deferred request.
 */
type DashboardProps = {
    widgets: DashboardWidget[];
    deferredWidgets?: DashboardWidget[];
    todos: TodoItem[];
    health?: HealthCheck[];
};

function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12
        ? 'Good morning'
        : hour < 18
          ? 'Good afternoon'
          : 'Good evening';
}

export default function AdminDashboard({
    widgets,
    deferredWidgets,
    todos,
    health,
}: DashboardProps) {
    const { auth, quickActions } = usePage().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';
    const codes = variantCodes([...widgets, ...(deferredWidgets ?? [])]);

    return (
        <>
            <Head title="Dashboard" />
            <ReportingCurrencyProvider codes={codes}>
                {(currencySwitch) => (
                    <Page>
                        <PageHeader
                            eyebrow={formatDate(
                                new Date().toISOString().slice(0, 10),
                            )}
                            title={`${greeting()}${firstName ? `, ${firstName}` : ''}`}
                            description="What needs you today and how the business is trending."
                            actions={
                                <>
                                    {currencySwitch}
                                    <QuickCreate actions={quickActions ?? []} />
                                </>
                            }
                        />

                        <DashboardWidgets
                            widgets={widgets}
                            todos={todos}
                            deferred={deferredWidgets}
                            health={health}
                        />
                    </Page>
                )}
            </ReportingCurrencyProvider>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
