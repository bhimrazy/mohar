import { Head } from '@/components/app/head';
import { KeyRound } from 'lucide-react';
import GuestTicketController from '@/actions/App/Modules/Support/Http/Controllers/Public/GuestTicketController';
import { Page } from '@/components/app/page';
import { CustomerTicket } from '@/components/support/customer-ticket';
import { PublicSupportNav } from '@/components/support/public-nav';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type {
    TicketDetail,
    TicketMessage,
    UploadLimits,
} from '@/types/support';

type Props = {
    ticket: TicketDetail;
    messages: TicketMessage[];
    accessKey: string;
    limits: UploadLimits;
};

export default function GuestTicket({
    ticket,
    messages,
    accessKey,
    limits,
}: Props) {
    const query = { query: { key: accessKey } };

    return (
        <>
            <Head title={`#${ticket.number} ${ticket.subject}`} />
            <Page>
                <PublicSupportNav />
                <Alert className="max-w-4xl">
                    <KeyRound />
                    <AlertDescription>
                        Keep this page's link private: anyone with it can read
                        and reply to this ticket.
                    </AlertDescription>
                </Alert>
                <CustomerTicket
                    ticket={ticket}
                    messages={messages}
                    limits={limits}
                    replyForm={GuestTicketController.reply.form(
                        ticket.number,
                        query,
                    )}
                    closeAction={GuestTicketController.close(
                        ticket.number,
                        query,
                    )}
                    rateUrl={(message) =>
                        GuestTicketController.rate.url(
                            { number: ticket.number, message },
                            query,
                        )
                    }
                />
            </Page>
        </>
    );
}
