import { Head } from '@inertiajs/react';
import EmailHistoryController from '@/actions/App/Modules/Notifications/Http/Controllers/Client/EmailHistoryController';
import { DateTime } from '@/components/app/date';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { EmailFrame } from '@/components/notifications/email-frame';

type Message = {
    uuid: string;
    recipient: string;
    subject: string;
    sent_at: string | null;
    body_html: string | null;
    body_text: string | null;
};

export default function EmailHistoryShow({ message }: { message: Message }) {
    return (
        <>
            <Head title={message.subject} />
            <Page>
                <PageHeader
                    back={{
                        href: EmailHistoryController.index(),
                        label: 'Email history',
                    }}
                    title={message.subject}
                    description={
                        <>
                            To {message.recipient} ·{' '}
                            <DateTime value={message.sent_at} />
                        </>
                    }
                />
                <SectionCard>
                    <EmailFrame
                        title={message.subject}
                        html={message.body_html}
                        text={message.body_text}
                    />
                </SectionCard>
            </Page>
        </>
    );
}
