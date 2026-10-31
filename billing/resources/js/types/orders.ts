/** Shapes sent by the Orders module (all amounts are integer minor units). */

export type PriceRow = { cycle: string; recurring: number; setup: number };

export type PricedLine = {
    key: string;
    kind: 'product' | 'addon' | 'domain';
    description: string;
    cycle: string | null;
    years: number;
    domain: string | null;
    recurring: number;
    setup: number;
    renewal: number;
    discount: number;
    due_today: number;
    breakdown: { label: string; recurring: number; setup: number }[];
    /** Price of one full cycle; `recurring` is the first amount, pro-rated or not. */
    cycle_price: number;
    /** Dates the first amount covers (end exclusive); services and addons only. */
    first_period: { start: string; end: string; prorated: boolean } | null;
};

export type PricedItem = {
    key: string;
    kind: 'product' | 'domain_register' | 'domain_transfer';
    title: string;
    subtitle: string | null;
    product_id: number | null;
    /** Configure page of the product, opened for editing this item. */
    product_url: string | null;
    cycle: string | null;
    domain: string | null;
    domain_mode: string | null;
    years: number;
    lines: PricedLine[];
    errors: string[];
    /** The registrar's extension details as the customer typed them (ID numbers shortened). */
    details?: { label: string; value: string }[];
    due_today: number;
};

export type PricedCart = {
    currency: string;
    items: PricedItem[];
    promotion: {
        code: string;
        applied: boolean;
        error: string | null;
        summary: string | null;
    } | null;
    subtotal: number;
    setup_total: number;
    discount_total: number;
    /** Due today after discounts, before tax. */
    net_total: number;
    taxes: { name: string; rate: number; amount: number }[];
    tax_total: number;
    total: number;
    /** `from`: first renewal date (end of the first period); null for domains. */
    recurring: { cycle: string; amount: number; from: string | null }[];
    has_errors: boolean;
    count: number;
};

export type StoreProduct = {
    id: number;
    name: string;
    description: string | null;
    type: string;
    requires_domain: boolean;
    in_stock: boolean;
    slug: string;
    /** Configure page on the slug URL, with the visitor's own domain carried along. */
    url: string;
    pricing: PriceRow[];
};

export type StoreGroup = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    products: StoreProduct[];
};

export type OrderItem = {
    id: number;
    type: 'service' | 'addon' | 'domain' | 'upgrade';
    item_id: number | null;
    parent_id: number | null;
    status: string;
    description: string;
    domain: string | null;
    cycle: string | null;
    first_amount: number;
    recurring_amount: number;
    setup_fee: number;
    discount_amount: number;
    promotion_cycles_remaining: number | null;
    auto_setup?: string | null;
};

export type Order = {
    id: number;
    number: string;
    kind: string;
    status: string;
    currency: string;
    subtotal: number;
    discount_total: number;
    setup_total: number;
    tax_total: number;
    total: number;
    promotion_code: string | null;
    payment_gateway: string | null;
    payment_gateway_label: string | null;
    invoice_id: number | null;
    notes: string | null;
    created_at: string | null;
    accepted_at: string | null;
    cancelled_at: string | null;
    items_count?: number;
    items?: OrderItem[];
    client?: { id: number; display_name: string; email: string };
    ip_address?: string | null;
    user_agent?: string | null;
    referral_code?: string | null;
    admin_notes?: string | null;
    fraud_provider?: string | null;
    fraud_score?: string | null;
    fraud_decision?: string | null;
    fraud_reasons?: string[];
    placed_by_staff?: boolean;
};

export type Promotion = {
    id: number;
    code: string;
    type: string;
    type_label: string;
    value: string;
    summary: string;
    status: string;
    recurring: boolean;
    recur_count: number;
    applies_to_products: number[];
    applies_to_addons: number[];
    applies_to_tlds: string;
    requires_products: number[];
    requires_existing_ok: boolean;
    cycles: string[];
    starts_on: string | null;
    expires_on: string | null;
    max_uses: number;
    uses: number;
    apply_once: boolean;
    once_per_client: boolean;
    new_clients_only: boolean;
    existing_clients_only: boolean;
    lifetime: boolean;
    allow_upgrades: boolean;
    min_cart_amount: number | null;
    discount_setup_fee: boolean;
    admin_notes: string | null;
    expired_at: string | null;
};
