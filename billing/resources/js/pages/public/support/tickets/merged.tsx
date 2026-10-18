import { Head } from '@inertiajs/react';
import { Merge } from 'lucide-react';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PublicSupportNav } from '@/components/support/public-nav';

/** A guest link of a ticket that staff merged into another one. Never reveals the target ticket. */
export default function MergedTicket({ number }: { number: string }) {
    return (
        <>
            <Head title={`Ticket #${number}`} />
            <Page size="narrow">
                <PublicSupportNav />
                <EmptyState
                    icon={Merge}
                    variant="bordered"
                    title={`Ticket #${number} was merged into another ticket`}
                    description="Our team combined related requests. Replies continue in the other ticket; check your email for its link, or contact support if you need help."
                />
            </Page>
        </>
    );
}
