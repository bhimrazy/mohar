import { Head, usePage } from '@inertiajs/react';
import { DashboardWidgets } from '@/components/app/dashboard-widgets';
import type { DashboardWidget } from '@/components/app/dashboard-widgets';
import type { TodoItem } from '@/components/app/needs-attention';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { formatDate } from '@/lib/date';
import { dashboard } from '@/routes/admin';

/**
 * Props from Core's Admin\DashboardController (kernel Dashboard registry). Recent activity and
 * automation health arrive as Platform's `list` widgets; the controller's separate `activity`
 * and `automation` props remain for simpler consumers.
 */
type DashboardProps = {
    widgets: DashboardWidget[];
    deferredWidgets?: DashboardWidget[];
    todos: TodoItem[];
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
}: DashboardProps) {
    const { auth } = usePage().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';

    return (
        <>
            <Head title="Dashboard" />
            <Page>
                <PageHeader
                    eyebrow={formatDate(new Date().toISOString().slice(0, 10))}
                    title={`${greeting()}${firstName ? `, ${firstName}` : ''}`}
                    description="What needs you today and how the business is trending."
                />

                <DashboardWidgets
                    widgets={widgets}
                    todos={todos}
                    deferred={deferredWidgets}
                />
            </Page>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
