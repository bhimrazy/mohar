// JSON endpoints. The app has no public REST API yet; these are the JSON routes the UI calls: the admin global
// search and client picker (signed in as staff) and the storefront's live price preview (anonymous; limited to
// 120/min per IP, so with one load-generator IP some answers are 429 and counted in rate_limited, not as errors).
import exec from 'k6/execution';
import {
    EMAIL_DOMAIN,
    accountIndex,
    json,
    newSession,
    pick,
    rows,
    signIn,
    think,
    visit,
} from '../lib.js';

const STAFF = parseInt(__ENV.STAFF || '2', 10);
const CLIENTS = parseInt(__ENV.CLIENTS || '200', 10);
const QUOTE_SHARE = parseFloat(__ENV.QUOTE_SHARE || '0.3');
const staff = newSession();
const visitor = newSession();
let productIds = null;

export function api() {
    const email = `staff${accountIndex(exec.vu.idInTest, 3, STAFF)}@${EMAIL_DOMAIN}`;
    if (!signIn(staff, email)) {
        think();
        return;
    }
    const term = `client${Math.floor(Math.random() * CLIENTS) + 1}`;
    // Typing in the search box: one request per pause, as the UI debounces input.
    json(staff, `/admin/search?q=${term.slice(0, 7)}`, 'api_admin_search', {
        limited: true,
    });
    think(1, 2);
    json(staff, `/admin/search?q=${term}`, 'api_admin_search', {
        limited: true,
    });
    think(1, 2);
    json(
        staff,
        `/admin/billing/clients/search?q=${term}`,
        'api_client_search',
        { limited: true },
    );
    think(1, 2);

    if (Math.random() < QUOTE_SHARE) {
        if (productIds === null) {
            const store = visit(visitor, '/store', 'store');
            productIds = [];
            for (const group of rows(
                store && store.props ? store.props.groups : null,
            )) {
                for (const product of rows(group.products)) {
                    if (product.payment_type === 'recurring') {
                        productIds.push(product.id);
                    }
                }
            }
        }
        const product = pick(productIds);
        if (product !== null) {
            json(
                visitor,
                `/store/products/${product}/quote`,
                'api_store_quote',
                { method: 'POST', body: { cycle: 'monthly' }, limited: true },
            );
        }
        think(1, 2);
    }
}
