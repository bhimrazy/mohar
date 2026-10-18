import { useId, useState } from 'react';
import { ListRows } from '@/components/app/list-widget';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
import { ViewAllLink } from './panel';
import type { ListWidgetData } from './types';

/** Several list widgets sharing a `group`, shown as tabs of one card (e.g. Activity: changes, sign-ins). */
export function ListGroupCard({
    lists,
    className,
}: {
    lists: ListWidgetData[];
    className?: string;
}) {
    const titleId = useId();
    const [tab, setTab] = useState(lists[0]?.key ?? '');
    const current = lists.find((list) => list.key === tab) ?? lists[0];

    return (
        <section
            aria-labelledby={titleId}
            className={cn(
                'flex min-w-0 flex-col overflow-hidden rounded-xl border bg-card text-card-foreground elevation-card',
                className,
            )}
        >
            <Tabs
                value={tab}
                onValueChange={setTab}
                className="flex flex-1 flex-col gap-0"
            >
                <header className="flex flex-wrap items-center justify-between gap-3 px-(--stat-p) pt-(--stat-p) pb-3">
                    <div className="flex flex-wrap items-center gap-3">
                        <h2 id={titleId} className="font-display text-base">
                            {lists[0]?.groupTitle ?? lists[0]?.title}
                        </h2>
                        <TabsList aria-label={lists[0]?.groupTitle}>
                            {lists.map((list) => (
                                <TabsTrigger
                                    key={list.key}
                                    value={list.key}
                                    className="px-2.5 text-xs"
                                >
                                    {list.tabTitle ?? list.title}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </div>
                    {current?.href && <ViewAllLink href={current.href} />}
                </header>
                {lists.map((list) => (
                    <TabsContent
                        key={list.key}
                        value={list.key}
                        className="flex flex-col"
                    >
                        <ListRows
                            items={list.items.slice(0, 6).map((item) => ({
                                ...item,
                                href: item.href ?? undefined,
                                meta: item.meta ?? undefined,
                                status: item.status ?? undefined,
                            }))}
                            empty={list.empty}
                        />
                    </TabsContent>
                ))}
            </Tabs>
        </section>
    );
}
