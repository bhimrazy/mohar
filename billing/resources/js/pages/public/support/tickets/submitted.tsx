import { Head } from '@/components/app/head';
import { MailCheck } from 'lucide-react';
import { EmptyState } from '@/components/app/empty-state';
import { Page } from '@/components/app/page';
import { PublicSupportNav } from '@/components/support/public-nav';

/** Guest submission whose private link was emailed instead of shown (the address belongs to an account). */
export default function TicketSubmitted({
    number,
    email,
}: {
    number: string;
    email: string;
}) {
    return (
        <>
            <Head title="Request received" />
            <Page size="narrow">
                <PublicSupportNav />
                <EmptyState
                    icon={MailCheck}
                    variant="bordered"
                    title={`We received your request #${number}`}
                    description={`We've emailed the link to follow this ticket to ${email}. Open it from that mailbox, or sign in to see it in your client area.`}
                />
            </Page>
        </>
    );
}
