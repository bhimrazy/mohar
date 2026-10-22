// Mixed load at one concurrency level (loadtest/README.md): client area, storefront and cart, admin area and the
// JSON endpoints, as constant virtual users with think time. loadtest/bin/run calls this once per level.
//
//   k6 run -e VUS=50 -e DURATION=2m -e CLIENTS=50000 -e STAFF=20 -e LOADTEST_PASSWORD=... -e OUT=/results/k6-50.json main.js
//
// Each request is tagged with `page`; a threshold per page makes k6 report it as its own sub-metric, which
// bin/summarize.py turns into the per-page latency table.
import { client } from './scenarios/client.js';
import { storefront } from './scenarios/storefront.js';
import { admin } from './scenarios/admin.js';
import { api } from './scenarios/api.js';

export { client, storefront, admin, api };

const VUS = parseInt(__ENV.VUS || '10', 10);
const DURATION = __ENV.DURATION || '2m';
const MIX = { client: 0.5, storefront: 0.3, admin: 0.1, api: 0.1 };

export const PAGES = [
    'login_form',
    'login',
    'client_dashboard',
    'client_invoices',
    'client_invoice',
    'client_invoice_pdf',
    'client_services',
    'client_service',
    'client_tickets',
    'client_ticket',
    'home',
    'store',
    'store_product',
    'cart_add',
    'cart',
    'cart_remove',
    'checkout_form',
    'checkout',
    'order_complete',
    'knowledgebase',
    'announcements',
    'admin_dashboard',
    'admin_clients',
    'admin_clients_search',
    'admin_client',
    'admin_invoices',
    'admin_invoice',
    'admin_services',
    'admin_service',
    'admin_tickets',
    'admin_ticket',
    'admin_transactions',
    'admin_orders',
    'api_admin_search',
    'api_client_search',
    'api_store_quote',
];

const scenarios = {};
for (const [name, share] of Object.entries(MIX)) {
    scenarios[name] = {
        executor: 'constant-vus',
        exec: name,
        vus: Math.max(1, Math.round(VUS * share)),
        duration: DURATION,
        gracefulStop: '20s',
    };
}

const thresholds = {
    // Reported, not enforced: the run records every level, including the ones past the knee.
    http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: false }],
};
for (const page of PAGES) {
    thresholds[`http_req_duration{page:${page}}`] = ['max>=0'];
    thresholds[`http_req_failed{page:${page}}`] = ['rate>=0'];
    thresholds[`content_errors{page:${page}}`] = ['count>=0'];
    thresholds[`http_reqs{page:${page}}`] = ['count>=0'];
}
for (const name of Object.keys(MIX)) {
    thresholds[`http_req_duration{scenario:${name}}`] = ['max>=0'];
    thresholds[`http_reqs{scenario:${name}}`] = ['count>=0'];
    thresholds[`http_req_failed{scenario:${name}}`] = ['rate>=0'];
}

export const options = {
    scenarios,
    thresholds,
    insecureSkipTLSVerify: true,
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    userAgent: 'mohar-loadtest/k6',
    discardResponseBodies: false,
};

export function handleSummary(data) {
    return { [__ENV.OUT || '/results/k6-summary.json']: JSON.stringify(data) };
}
