export type ClientContact = {
    id: number;
    first_name: string;
    last_name: string;
    email: string;
    phone: string | null;
    email_preferences: Record<string, boolean>;
};

export type ClientLogin = {
    id: number;
    name: string;
    email: string;
    is_owner: boolean;
    permissions: string[];
    last_login_at: string | null;
    /** An invitation not accepted yet that still works for this login (none once the login was used). */
    invitation: { state: 'pending' | 'expired'; expires_at: string } | null;
    /** Nobody has used the login: staff send it an invitation rather than a password reset. */
    can_invite: boolean;
};

export const AREA_LABELS: Record<string, string> = {
    billing: 'Billing',
    services: 'Services',
    domains: 'Domains',
    support: 'Support',
    profile: 'Profile',
    contacts: 'Contacts',
};

export const EMAIL_TYPE_LABELS: Record<string, string> = {
    general: 'General',
    invoice: 'Invoices',
    support: 'Support',
    product: 'Products',
    domain: 'Domains',
};
