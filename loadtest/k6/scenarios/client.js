// Client area browsing: sign in, dashboard, invoices and one invoice (sometimes its PDF), services and one service,
// tickets and one ticket. One HTML page per session, Inertia JSON navigations after that.
import exec from 'k6/execution';
import {
    EMAIL_DOMAIN,
    accountIndex,
    contentErrors,
    download,
    html,
    newSession,
    pick,
    rows,
    signIn,
    think,
    visit,
} from '../lib.js';

const CLIENTS = parseInt(__ENV.CLIENTS || '200', 10);
const PDF_SHARE = parseFloat(__ENV.PDF_SHARE || '0.1');
const session = newSession();

export function client() {
    const email = `client${accountIndex(exec.vu.idInTest, 0, CLIENTS)}@${EMAIL_DOMAIN}`;
    if (!signIn(session, email)) {
        think();
        return;
    }
    if (exec.vu.iterationInScenario === 0) {
        html(session, '/client', 'client_dashboard');
    } else {
        visit(session, '/client', 'client_dashboard');
    }
    think();

    const invoices = visit(session, '/client/invoices', 'client_invoices');
    const invoice = pick(
        rows(invoices && invoices.props ? invoices.props.invoices : null),
    );
    think();
    if (invoice) {
        visit(session, `/client/invoices/${invoice.id}`, 'client_invoice');
        if (Math.random() < PDF_SHARE) {
            // The PDF is rendered on first download and cached in object storage afterwards.
            download(
                session,
                `/client/invoices/${invoice.id}/pdf`,
                'client_invoice_pdf',
                'application/pdf',
            );
        }
        think();
    } else if (
        !invoices ||
        !invoices.props ||
        invoices.props.invoices === undefined
    ) {
        // A client with no invoice in the seeded year (a long-cycle or one-time service) has an empty list: valid.
        contentErrors.add(1, { page: 'client_invoices' });
    }

    const services = visit(session, '/client/services', 'client_services');
    const service = pick(
        rows(services && services.props ? services.props.services : null),
    );
    think();
    if (service) {
        visit(session, `/client/services/${service.id}`, 'client_service');
        think();
    }

    const tickets = visit(
        session,
        '/client/support/tickets?state=all',
        'client_tickets',
    );
    const ticket = pick(
        rows(tickets && tickets.props ? tickets.props.tickets : null),
    );
    think();
    if (ticket) {
        visit(
            session,
            `/client/support/tickets/${encodeURIComponent(ticket.number)}`,
            'client_ticket',
        );
        think();
    }
}
