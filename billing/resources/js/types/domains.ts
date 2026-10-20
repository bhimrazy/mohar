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
        | 'unsupported';
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

/** A .np registration request (App\Modules\Domains\Http\Resources\NpRegistrationResource). Staff-only fields are optional. */
export type NpRequest = {
    stage:
        | 'awaiting_documents'
        | 'documents_review'
        | 'ready_to_submit'
        | 'submitted'
        | 'approved';
    applicant_type: 'individual' | 'organisation' | null;
    missing: string[];
    returned_reasons: string | null;
    rejected: boolean;
    rejection_note: string | null;
    rejection_code: number | null;
    documents: {
        id: number;
        kind: string;
        name: string;
        size: number;
        uploaded_at: string | null;
        current: boolean;
        delete_after: string | null;
        url: string | null;
    }[];
    handling_fee_note: string | null;
    /** No client upload and no staff step for 90 days while documents are awaited. */
    abandoned: boolean;
    stage_changed_at?: string;
    submitted_at?: string | null;
    approved_at?: string | null;
    registry_expires_on?: string | null;
    review_due_on?: string | null;
    review_pending?: boolean;
    overdue?: boolean;
    hostmaster_email?: string | null;
    /** Staff: the amount paid on the order (formatted) and a link to its invoice. */
    paid?: string | null;
    invoice_url?: string | null;
};

export const NP_DOCUMENTS: Record<string, string> = {
    identity:
        'Citizenship, passport, driving licence, voter card, NRN card or resident visa',
    registration_certificate:
        'Company registration, PAN or trademark certificate',
    request_letter: 'Request letter on letterhead, stamped and signed',
};

export const NP_REQUIRED: Record<'individual' | 'organisation', string[]> = {
    individual: ['identity'],
    organisation: ['registration_certificate', 'request_letter'],
};
