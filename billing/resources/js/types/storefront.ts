/** Shapes sent by the Storefront module. Amounts are integer minor units, already in the visitor's price display. */

export type FromPrice = {
    amount: number;
    cycle: string;
    per_month: boolean;
    /** One-time setup fee on that price (0 when none). */
    setup: number;
};

export type SiteLink = { label: string; url: string; badge: number | null };

export type SiteGroup = {
    id: number;
    name: string;
    slug: string;
    tagline: string | null;
    url: string;
    from: FromPrice | null;
};

export type PaymentMark = {
    key: string;
    label: string;
    logo: { url: string; dark_url: string | null; alt: string } | null;
};

export type CompanyDetails = {
    name: string;
    legal_name: string | null;
    address: string | null;
    email: string | null;
    phone: string | null;
    whatsapp: string | null;
    /** Support hours as the host wrote them. */
    hours: string | null;
    ids: { kind: string; label: string; value: string }[];
    social: { label: string; url: string }[];
};

/** The shared `site` prop on public pages (public layout header and footer). */
export type Site = {
    products: {
        mode: 'links' | 'menu';
        label: string;
        groups: SiteGroup[];
        compare_url: string | null;
    };
    domains: SiteLink[];
    support: SiteLink[];
    company: CompanyDetails;
    payments: PaymentMark[];
    tax_note: string | null;
    legal: { label: string; url: string }[];
    currency: string;
    currencies: string[];
    currency_url: string | null;
    cart: { count: number; url: string } | null;
    account: {
        signed_in: boolean;
        client_area_url: string | null;
        login_url: string | null;
        register_url: string | null;
        reset_url: string | null;
        invoices_url: string | null;
    };
};

export type PlanPrice = {
    cycle: string;
    recurring: number;
    setup: number;
    monthly: number | null;
};

export type Plan = {
    id: number;
    name: string;
    tagline: string | null;
    features: string[];
    featured: boolean;
    /** Checkout taxes this plan; untaxed plans show their price as charged. */
    taxable: boolean;
    in_stock: boolean;
    url: string;
    pricing: PlanPrice[];
};

export type DomainPrice = {
    tld: string;
    featured: boolean;
    badge: string | null;
    register: number | null;
    renew: number | null;
    transfer: number | null;
};

export type PageSeo = {
    title: string;
    description: string;
    canonical: string;
    robots: string;
    image: string | null;
};
