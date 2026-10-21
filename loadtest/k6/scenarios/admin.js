// Admin area: one staff member per virtual user working through the dashboard and the main lists, opening a
// client, an unpaid invoice, a service and a ticket.
import exec from 'k6/execution';
import {
    EMAIL_DOMAIN,
    accountIndex,
    contentErrors,
    html,
    newSession,
    pick,
    rows,
    signIn,
    think,
    visit,
} from '../lib.js';

const STAFF = parseInt(__ENV.STAFF || '2', 10);
const CLIENTS = parseInt(__ENV.CLIENTS || '200', 10);
const session = newSession();

function first(page, prop) {
    return pick(rows(page && page.props ? page.props[prop] : null));
}

export function admin() {
    const email = `staff${accountIndex(exec.vu.idInTest, 0, STAFF)}@${EMAIL_DOMAIN}`;
    if (!signIn(session, email)) {
        think();
        return;
    }
    if (exec.vu.iterationInScenario === 0) {
        html(session, '/admin', 'admin_dashboard');
    } else {
        visit(session, '/admin', 'admin_dashboard');
    }
    think(2, 5);

    const clients = visit(session, '/admin/clients', 'admin_clients');
    think();
    // Search by a seeded e-mail prefix, as staff do from the client list.
    const found = visit(
        session,
        `/admin/clients?search=client${Math.floor(Math.random() * CLIENTS) + 1}`,
        'admin_clients_search',
    );
    const client = first(found, 'clients') || first(clients, 'clients');
    think();
    if (client) {
        visit(session, `/admin/clients/${client.id}`, 'admin_client');
        think(2, 4);
    } else {
        contentErrors.add(1, { page: 'admin_clients' });
    }

    const invoices = visit(
        session,
        '/admin/billing/invoices?status=unpaid',
        'admin_invoices',
    );
    const invoice = first(invoices, 'invoices');
    think();
    if (invoice) {
        visit(
            session,
            `/admin/billing/invoices/${invoice.id}`,
            'admin_invoice',
        );
        think(2, 4);
    }

    const services = visit(session, '/admin/services', 'admin_services');
    const service = first(services, 'services');
    think();
    if (service) {
        visit(session, `/admin/services/${service.id}`, 'admin_service');
        think();
    }

    const tickets = visit(session, '/admin/support/tickets', 'admin_tickets');
    const ticket = first(tickets, 'tickets');
    think();
    if (ticket) {
        visit(session, `/admin/support/tickets/${ticket.id}`, 'admin_ticket');
        think(2, 4);
    }

    const [path, name] = pick([
        ['/admin/billing/transactions', 'admin_transactions'],
        ['/admin/orders', 'admin_orders'],
    ]);
    visit(session, path, name);
    think();
}
