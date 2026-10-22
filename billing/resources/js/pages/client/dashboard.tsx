import { Link, usePage } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { ArrowRight, Sparkles } from 'lucide-react';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatCard } from '@/components/app/stat-card';
import { navIcon } from '@/lib/nav-icons';
import { dashboard } from '@/routes/client';
import type { ClientRecord, DashboardCard } from '@/types';

type Props = {
    client: ClientRecord;
    cards: DashboardCard[];
};

export default function ClientDashboard({ client, cards }: Props) {
    const { navigation } = usePage().props;
    const links = navigation
        .flatMap((group) => group.items)
        .filter((item) => !item.active);

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

                {cards.length > 0 ? (
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
                ) : (
                    <EmptyState
                        variant="bordered"
                        icon={Sparkles}
                        title="You're all set"
                        description="Your services, domains, invoices and tickets will show up here once you have them."
                    />
                )}

                {links.length > 0 && (
                    <SectionCard title="Quick links" flush>
                        <ul className="grid divide-y sm:grid-cols-2 sm:divide-y-0">
                            {links.map((link) => {
                                const Icon = navIcon(link.icon);

                                return (
                                    <li key={link.href}>
                                        <Link
                                            href={link.href}
                                            className="flex items-center gap-3 px-4 py-3.5 text-sm transition-colors hover:bg-muted/50 sm:px-6"
                                        >
                                            <span className="flex size-8 items-center justify-center rounded-lg border bg-muted/50">
                                                <Icon
                                                    className="size-4 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <span className="flex-1 font-medium">
                                                {link.title}
                                            </span>
                                            <ArrowRight
                                                className="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </SectionCard>
                )}
            </Page>
        </>
    );
}

ClientDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
