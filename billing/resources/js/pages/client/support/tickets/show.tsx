import { Head } from '@inertiajs/react';
import TicketController from '@/actions/App/Modules/Support/Http/Controllers/Client/TicketController';
import { Page } from '@/components/app/page';
import { CustomerTicket } from '@/components/support/customer-ticket';
import type {
    TicketDetail,
    TicketMessage,
    UploadLimits,
} from '@/types/support';

export default function ClientTicket({
    ticket,
    messages,
    limits,
}: {
    ticket: TicketDetail;
    messages: TicketMessage[];
    limits: UploadLimits;
}) {
    return (
        <>
            <Head title={`#${ticket.number} ${ticket.subject}`} />
            <Page>
                <CustomerTicket
                    ticket={ticket}
                    messages={messages}
                    limits={limits}
                    back={{
                        href: TicketController.index(),
                        label: 'Support tickets',
                    }}
                    replyForm={TicketController.reply.form(ticket.number)}
                    closeAction={TicketController.close(ticket.number)}
                    rateUrl={(message) =>
                        TicketController.rate.url({
                            number: ticket.number,
                            message,
                        })
                    }
                />
            </Page>
        </>
    );
}
