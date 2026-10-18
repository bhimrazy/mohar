import { router } from '@inertiajs/react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { ReplyForm } from '@/components/support/reply-form';
import { TicketThread } from '@/components/support/ticket-thread';
import { Button } from '@/components/ui/button';
import type { Href, RouteAction } from '@/types/app';
import type {
    TicketDetail,
    TicketMessage,
    UploadLimits,
} from '@/types/support';
import type { RouteFormDefinition } from '@/wayfinder';

type CustomerTicketProps = {
    ticket: TicketDetail;
    messages: TicketMessage[];
    limits: UploadLimits;
    back?: { href: Href; label: string };
    replyForm: RouteFormDefinition<'post'>;
    closeAction: RouteAction;
    rateUrl: (messageId: number) => string;
};

/** Ticket thread + reply/close for signed-in clients and guests. */
export function CustomerTicket({
    ticket,
    messages,
    limits,
    back,
    replyForm,
    closeAction,
    rateUrl,
}: CustomerTicketProps) {
    const closed = ticket.status === 'closed';

    return (
        <>
            <PageHeader
                back={back}
                title={ticket.subject}
                status={ticket.status}
                meta={
                    <>
                        <span>Ticket #{ticket.number}</span>
                        <span aria-hidden="true">·</span>
                        <span>{ticket.department}</span>
                        <span aria-hidden="true">·</span>
                        <span>
                            Opened <DateTime value={ticket.created_at} />
                        </span>
                    </>
                }
                actions={
                    ticket.can_close && (
                        <ConfirmDialog
                            trigger={
                                <Button variant="outline">Close ticket</Button>
                            }
                            title="Close this ticket?"
                            description="Close it if your question is answered. You can reopen it any time by replying."
                            confirmLabel="Close ticket"
                            destructive={false}
                            action={closeAction}
                        />
                    )
                }
            />

            <div className="flex max-w-4xl flex-col gap-4">
                <TicketThread
                    messages={messages}
                    audience="customer"
                    onRate={(message, rating) =>
                        router.post(
                            rateUrl(message.id),
                            { rating },
                            { preserveScroll: true },
                        )
                    }
                />

                <SectionCard
                    title={closed ? 'Need more help?' : 'Reply'}
                    description={
                        closed
                            ? 'This ticket is closed. Replying reopens it.'
                            : 'We reply as soon as we can; you will get an email when we do.'
                    }
                >
                    <ReplyForm
                        form={replyForm}
                        limits={limits}
                        submitLabel={
                            closed ? 'Reopen with reply' : 'Send reply'
                        }
                    />
                </SectionCard>
            </div>
        </>
    );
}
