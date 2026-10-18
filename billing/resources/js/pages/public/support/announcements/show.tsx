import { Head } from '@/components/app/head';
import AnnouncementController from '@/actions/App/Modules/Support/Http/Controllers/Public/AnnouncementController';
import { DateTime } from '@/components/app/date';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Markdown } from '@/components/support/markdown';
import { PublicSupportNav } from '@/components/support/public-nav';

type Props = {
    announcement: {
        title: string;
        excerpt: string | null;
        body_html: string;
        published_at: string | null;
    };
};

export default function Announcement({ announcement }: Props) {
    return (
        <>
            <Head title={announcement.title} />
            <Page size="narrow">
                <PublicSupportNav />
                <PageHeader
                    back={{
                        href: AnnouncementController.index(),
                        label: 'Announcements',
                    }}
                    title={announcement.title}
                    meta={<DateTime value={announcement.published_at} />}
                    description={announcement.excerpt}
                />
                <SectionCard>
                    <Markdown html={announcement.body_html} />
                </SectionCard>
            </Page>
        </>
    );
}
