import { Link } from '@inertiajs/react';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { History } from 'lucide-react';

export type ActivityItem = {
    id: number;
    description: string;
    actor?: string | null;
    impersonator?: string | null;
    client?: { id: number; name: string } | null;
    clientHref?: string;
    created_at: string | null;
};

type ActivityFeedProps = {
    items: ActivityItem[];
    emptyText?: string;
};

/** Compact newest-first activity list (dashboard, client profile). */
export function ActivityFeed({
    items,
    emptyText = 'Activity appears here as staff and clients make changes.',
}: ActivityFeedProps) {
    if (items.length === 0) {
        return (
            <EmptyState
                icon={History}
                title="No activity yet"
                description={emptyText}
            />
        );
    }

    return (
        <ol className="divide-y" data-slot="activity-feed">
            {items.map((item) => (
                <li
                    key={item.id}
                    className="flex items-start justify-between gap-4 px-4 py-3 sm:px-6"
                >
                    <div className="min-w-0 space-y-0.5">
                        <p className="text-sm">{item.description}</p>
                        <p className="truncate text-xs text-muted-foreground">
                            {item.actor ?? 'System'}
                            {item.impersonator &&
                                ` · by ${item.impersonator} as client`}
                            {item.client && (
                                <>
                                    {' · '}
                                    {item.clientHref ? (
                                        <Link
                                            href={item.clientHref}
                                            className="hover:text-foreground hover:underline"
                                        >
                                            {item.client.name}
                                        </Link>
                                    ) : (
                                        item.client.name
                                    )}
                                </>
                            )}
                        </p>
                    </div>
                    <DateTime
                        value={item.created_at}
                        relative
                        className="shrink-0 text-xs text-muted-foreground"
                    />
                </li>
            ))}
        </ol>
    );
}
