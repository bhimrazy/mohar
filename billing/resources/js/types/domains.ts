/** Domains module types (mirror App\Modules\Domains\Http\Resources\*). */

export type DomainStatus =
    | 'pending'
    | 'pending_registration'
    | 'pending_transfer'
    | 'active'
    | 'grace'
    | 'redemption'
    | 'expired'
    | 'transferred_away'
    | 'cancelled'
    | 'fraud';

export type ContactRole = 'registrant' | 'admin' | 'tech' | 'billing';

export const CONTACT_ROLES: ContactRole[] = [
    'registrant',
    'admin',
    'tech',
    'billing',
];

export type ContactFields = {
    first_name: string | null;
    last_name: string | null;
    company: string | null;
    email: string | null;
    phone: string | null;
    address_1: string | null;
    address_2: string | null;
    city: string | null;
    state: string | null;
    postcode: string | null;
    country: string | null;
};

export type DomainContacts = Record<ContactRole, ContactFields>;

export type Domain = {
    id: number;
    name: string;
    name_unicode: string;
    tld: string;
    type: 'register' | 'transfer';
    status: DomainStatus;
    registrar_key: string | null;
    /** The client registered it at the registry (e.g. .np): no registrar, no expiry or renewal tracked here. */
    registered_by_client: boolean;
    /** Staff only: the order recorded at the registrar, or null when none is linked. */
    registrar_order_id?: string | number | null;
    registration_date: string | null;
    expiry_date: string | null;
    next_due_date: string;
    next_invoice_date?: string;
    days_until_expiry: number | null;
    period_years: number;
    first_payment_amount?: number;
    recurring_amount: number;
    currency: string;
    auto_renew: boolean;
    id_protection: boolean;
    dns_management: boolean;
    email_forwarding: boolean;
    registrar_lock: boolean | null;
    nameservers: string[];
    is_premium: boolean;
    contact_change_pending: boolean;
    transfer_lock_until: string | null;
    last_error?: string | null;
    needs_review?: boolean;
    last_synced_at?: string | null;
    last_sync_status?: string | null;
    linked_service_id?: number | null;
    has_epp_code?: boolean;
    client?: { id: number; display_name: string };
    created_at: string | null;
};

export type EppFlash = { code: string | null; emailed: boolean };

export type LookupResult = {
    domain: string;
    domain_unicode: string;
    tld?: string;
    status:
        | 'available'
        | 'registered'
        | 'reserved'
        | 'invalid'
        | 'unknown'
        | 'unsupported'
        | 'self_registered';
    in_system?: boolean;
    is_premium?: boolean;
    badge?: string | null;
    sellable: boolean;
    register?: { years: number; price: number }[];
    renew?: number | null;
    transfer?: number | null;
    transferable?: boolean;
    epp_required?: boolean;
    order_url?: string | null;
    /** The client registers this extension at the registry (e.g. .np); it is not sold here. */
    self_registered?: boolean;
    registration_url?: string | null;
    existing_url?: string | null;
    /** Why the registry cannot have issued this name (e.g. asha.np); replaces the note and "I already have it". */
    level_hint?: string | null;
};

export type DomainSearch = {
    query: string;
    normalised: string | null;
    error: string | null;
    currency: string;
    results: LookupResult[];
    suggestions: LookupResult[];
};

export const CONTACT_ROLE_LABELS: Record<ContactRole, string> = {
    registrant: 'Registrant',
    admin: 'Administrative',
    tech: 'Technical',
    billing: 'Billing',
};

export function expiryHint(daysLeft: number | null): string | null {
    if (daysLeft === null) {
        return null;
    }

    if (daysLeft === 0) {
        return 'expires today';
    }

    if (daysLeft < 0) {
        return `expired ${Math.abs(daysLeft)} day${daysLeft === -1 ? '' : 's'} ago`;
    }

    return `in ${daysLeft} day${daysLeft === 1 ? '' : 's'}`;
}

/** What the status badge shows: an active domain the client registered at the registry is not "Active" here. */
export function domainStatus(
    domain: Pick<Domain, 'status' | 'registered_by_client'>,
): string {
    return domain.registered_by_client && domain.status === 'active'
        ? 'self-registered'
        : domain.status;
}

export const MANAGED_AT_REGISTRY = 'At the registry';
