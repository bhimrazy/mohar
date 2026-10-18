import { Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Megaphone, Rss } from 'lucide-react';
import AnnouncementController from '@/actions/App/Modules/Support/Http/Controllers/Public/AnnouncementController';
import { DateTime } from '@/components/app/date';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { Pagination } from '@/components/app/pagination';
import { PublicSupportNav } from '@/components/support/public-nav';
import { Button } from '@/components/ui/button';
import type { LaravelPaginated } from '@/types/app';

type Item = {
    title: string;
    slug: string;
    excerpt: string | null;
    published_at: string | null;
};

export default function Announcements({
    announcements,
    feedUrl,
}: {
    announcements: LaravelPaginated<Item>;
    feedUrl: string;
}) {
    return (
        <>
            <Head title="Announcements" />
            <Page size="narrow">
                <PublicSupportNav />
                <PageHeader
                    title="Announcements"
                    description="Product news, maintenance and changes to our services."
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <a href={feedUrl}>
                                <Rss />
                                RSS feed
                            </a>
                        </Button>
                    }
                />
                {announcements.data.length === 0 ? (
                    <EmptyState
                        icon={Megaphone}
                        variant="bordered"
                        title="No announcements yet"
                        description="News and maintenance notices will appear here."
                    />
                ) : (
                    <ol className="flex flex-col gap-4">
                        {announcements.data.map((item) => (
                            <li key={item.slug}>
                                <Link
                                    href={AnnouncementController.show(
                                        item.slug,
                                    )}
                                    className="block rounded-xl border bg-card p-5 shadow-xs transition-colors hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                >
                                    <DateTime
                                        value={item.published_at}
                                        className="text-xs text-muted-foreground"
                                    />
                                    <h2 className="mt-1 text-base font-semibold">
                                        {item.title}
                                    </h2>
                                    {item.excerpt && (
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {item.excerpt}
                                        </p>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ol>
                )}
                {announcements.last_page > 1 && (
                    <Pagination
                        paginator={announcements}
                        itemLabel="announcements"
                    />
                )}
            </Page>
        </>
    );
}
