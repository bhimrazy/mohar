import { Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { DateTime } from '@/components/app/date';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { StatusBadge } from '@/components/app/status-badge';
import { Markdown } from '@/components/support/markdown';
import { PriorityBadge } from '@/components/support/priority-badge';
import { PublicSupportNav } from '@/components/support/public-nav';

type Incident = {
    id: number;
    title: string;
    type: string;
    affected: string | null;
    priority: string;
    status: string;
    description_html: string;
    starts_at: string;
    ends_at: string | null;
    updated_at: string | null;
    updates: {
        id: number;
        body_html: string;
        status: string;
        created_at: string | null;
    }[];
};

function IncidentCard({ incident }: { incident: Incident }) {
    return (
        <SectionCard
            title={incident.title}
            description={
                <span className="flex flex-wrap items-center gap-x-2">
                    {incident.affected && <span>{incident.affected}</span>}
                    {incident.affected && <span aria-hidden="true">·</span>}
                    <span>
                        {incident.status === 'scheduled' ? 'Starts' : 'Started'}{' '}
                        <DateTime
                            value={incident.starts_at}
                            format="datetime"
                        />
                    </span>
                    {incident.ends_at && (
                        <>
                            <span aria-hidden="true">·</span>
                            <span>
                                Ended{' '}
                                <DateTime
                                    value={incident.ends_at}
                                    format="datetime"
                                />
                            </span>
                        </>
                    )}
                </span>
            }
            actions={
                <>
                    <PriorityBadge priority={incident.priority} />
                    <StatusBadge status={incident.status} />
                </>
            }
        >
            <Markdown html={incident.description_html} />
            {incident.updates.length > 0 && (
                <ol className="mt-4 grid gap-3 border-t pt-4">
                    {incident.updates.map((update) => (
                        <li
                            key={update.id}
                            className="grid gap-1 border-l-2 pl-3"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={update.status} />
                                <DateTime
                                    value={update.created_at}
                                    relative
                                    className="text-xs text-muted-foreground"
                                />
                            </div>
                            <Markdown
                                html={update.body_html}
                                className="text-muted-foreground"
                            />
                        </li>
                    ))}
                </ol>
            )}
        </SectionCard>
    );
}

export default function NetworkStatus({
    open,
    resolved,
}: {
    open: Incident[];
    resolved: Incident[];
}) {
    return (
        <>
            <Head title="Network status" />
            <Page size="narrow">
                <PublicSupportNav />
                <PageHeader
                    title="Network status"
                    description="Current incidents, planned maintenance and recently resolved issues."
                />
                {open.length === 0 ? (
                    <div className="flex items-center gap-3 rounded-xl border bg-card p-5 shadow-xs">
                        <CheckCircle2
                            className="size-5 text-muted-foreground"
                            aria-hidden
                        />
                        <div>
                            <p className="font-medium">
                                All systems operational
                            </p>
                            <p className="text-sm text-muted-foreground">
                                There are no open incidents right now.
                            </p>
                        </div>
                    </div>
                ) : (
                    <section
                        className="flex flex-col gap-4"
                        aria-labelledby="open-incidents"
                    >
                        <h2
                            id="open-incidents"
                            className="text-sm font-medium text-muted-foreground"
                        >
                            Open incidents and maintenance
                        </h2>
                        {open.map((incident) => (
                            <IncidentCard
                                key={incident.id}
                                incident={incident}
                            />
                        ))}
                    </section>
                )}
                <section
                    className="flex flex-col gap-4"
                    aria-labelledby="resolved-incidents"
                >
                    <h2
                        id="resolved-incidents"
                        className="text-sm font-medium text-muted-foreground"
                    >
                        Resolved in the last 30 days
                    </h2>
                    {resolved.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing to report.
                        </p>
                    ) : (
                        resolved.map((incident) => (
                            <IncidentCard
                                key={incident.id}
                                incident={incident}
                            />
                        ))
                    )}
                </section>
            </Page>
        </>
    );
}
