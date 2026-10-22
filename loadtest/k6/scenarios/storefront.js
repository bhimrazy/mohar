// Storefront and cart: the public pages anonymously (home, store, a product, knowledge base, announcements), then
// signed in as a customer: add a product to the cart, view the cart, and either remove it or check out with bank
// transfer (CHECKOUT_SHARE of iterations). Cart actions are limited per signed-in user (orders-cart, 60/min), so they
// run signed in: anonymously every virtual user would share the load generator's one IP and its limit.
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
    submit,
    think,
    visit,
} from '../lib.js';

const CLIENTS = parseInt(__ENV.CLIENTS || '200', 10);
const CHECKOUT_SHARE = parseFloat(__ENV.CHECKOUT_SHARE || '0.2');
const customer = newSession();

function products(page) {
    const groups = page && page.props ? rows(page.props.groups) : [];
    const list = [];
    for (const group of groups) {
        for (const product of rows(group.products)) {
            const cycles = rows(product.pricing)
                .map((price) => price.cycle)
                .filter((cycle) => cycle !== 'onetime');
            if (
                product.payment_type === 'recurring' &&
                product.in_stock !== false &&
                cycles.length > 0
            ) {
                list.push({
                    id: product.id,
                    requiresDomain: product.requires_domain === true,
                    // Hosting plans order with a domain only; servers also ask for custom fields (hostname, OS).
                    simple: product.type === 'shared_hosting',
                    cycle: cycles[0],
                });
            }
        }
    }

    return list;
}

export function storefront() {
    // A new anonymous visitor each iteration (new cookies, so a new session row): a full first page load, then
    // in-app navigation.
    const anonymous = newSession();
    const home = html(anonymous, '/', 'home');
    if (home === null) {
        think();
        return;
    }
    think();
    const store = visit(anonymous, '/store', 'store');
    const offer = products(store);
    const product = pick(offer);
    think();
    if (product) {
        visit(anonymous, `/store/products/${product.id}`, 'store_product');
        think();
    } else {
        contentErrors.add(1, { page: 'store' });
    }
    const [path, name] = pick([
        ['/knowledgebase', 'knowledgebase'],
        ['/announcements', 'announcements'],
    ]);
    visit(anonymous, path, name);
    think();

    // Signed-in customer ordering.
    const email = `client${accountIndex(exec.vu.idInTest, Math.floor(CLIENTS / 2), CLIENTS)}@${EMAIL_DOMAIN}`;
    const ordered = pick(offer.filter((item) => item.simple)) || product;
    if (!ordered || !signIn(customer, email)) {
        return;
    }
    const domain = `lt-${exec.vu.idInTest}-${exec.vu.iterationInScenario}.example.org`;
    const fields = {
        cycle: ordered.cycle,
        ...(ordered.requiresDomain
            ? { domain_mode: 'existing', domain_name: domain }
            : {}),
    };
    const added = submit(
        customer,
        'POST',
        `/store/cart/products/${ordered.id}`,
        fields,
        'cart_add',
        { limited: true },
    );
    const key = (added.location.match(/[?&]added=([^&]+)/) || [])[1];
    think(1, 2);
    visit(customer, '/store/cart', 'cart');
    think();
    if (!key) {
        if (added.status !== 429) {
            contentErrors.add(1, { page: 'cart_add' });
        }
        return;
    }

    if (Math.random() < CHECKOUT_SHARE) {
        const checkout = visit(customer, '/store/checkout', 'checkout_form');
        const idempotency =
            checkout && checkout.props ? checkout.props.idempotency_key : null;
        think(2, 5);
        if (idempotency) {
            const placed = submit(
                customer,
                'POST',
                '/store/checkout',
                {
                    payment_gateway: 'bank_transfer',
                    accept_tos: '1',
                    idempotency_key: idempotency,
                },
                'checkout',
                { limited: true },
            );
            if (placed.location) {
                visit(
                    customer,
                    placed.location.replace(/^https?:\/\/[^/]+/, ''),
                    'order_complete',
                );
            }
            think();
            return;
        }
        contentErrors.add(1, { page: 'checkout_form' });
    }
    submit(
        customer,
        'DELETE',
        `/store/cart/items/${key}`,
        null,
        'cart_remove',
        { limited: true },
    );
    think();
}
