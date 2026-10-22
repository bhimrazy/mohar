import { parseMoneyInput } from '@/lib/money';
import type { GatewayLogoData } from './gateway-logo';

/**
 * Billing page props as sent by app/Modules/Billing/Http/Resources/*.
 * Money is always integer minor units + currency.
 */

export type TaxLine = { name: string; rate: number; amount: number };

export type InvoiceItem = {
    id: number;
    type: string;
    billable_id: number | null;
    description: string;
    quantity: number;
    unit_amount: number;
    amount: number;
    taxable: boolean;
    tax_amount: number;
    period_start: string | null;
    period_end: string | null;
    cancelled: boolean;
};

export type Adjustment = {
    id: number;
    kind: 'credit' | 'debit';
    reason: string;
    description: string | null;
    amount: number;
    signed_amount: number;
    tax_amount: number;
    created_by?: string | null;
    created_at: string | null;
};

export type Invoice = {
    id: number;
    number: string;
    legal_number: string | null;
    proforma_number: string | null;
    status: string;
    display_status: string;
    kind: 'standard' | 'add_funds';
    currency: string;
    issue_date: string | null;
    due_date: string | null;
    /** "28 Poush 2082 BS" when the BS date setting is on, else null. */
    issue_date_bs: string | null;
    due_date_bs: string | null;
    paid_at: string | null;
    subtotal: number;
    discount_total: number;
    taxes: TaxLine[];
    tax_inclusive: boolean;
    tax_exempt_reason: 'exempt' | 'reverse_charge' | null;
    total: number;
    amount_paid: number;
    credit_applied: number;
    adjustments_total: number;
    balance: number;
    payment_gateway: string | null;
    notes: string | null;
    admin_notes?: string | null;
    client_snapshot: Record<string, string | null> | null;
    client?: { id: number; display_name: string };
    items?: InvoiceItem[];
    adjustments?: Adjustment[];
    created_at: string | null;
};

export type Transaction = {
    id: number;
    type: string;
    status: string;
    gateway: string;
    gateway_txn_id: string | null;
    amount_in: number;
    amount_out: number;
    fee?: number;
    currency: string;
    gateway_amount: number | null;
    gateway_currency: string | null;
    exchange_rate: string | null;
    refundable_remaining: number;
    refundable: boolean;
    to_review?: number | null;
    awaiting_confirmation?: boolean;
    description: string | null;
    parent_transaction_id: number | null;
    invoice_id: number | null;
    invoice_number?: string | null;
    client?: { id: number; display_name: string };
    occurred_at: string;
};

export type QuoteItem = {
    id: number;
    description: string;
    quantity: number;
    unit_amount: number;
    discount_percent: number;
    amount: number;
    taxable: boolean;
};

export type Quote = {
    id: number;
    number: string;
    subject: string;
    stage: string;
    currency: string;
    valid_until: string | null;
    proposal_text: string | null;
    customer_notes: string | null;
    admin_notes?: string | null;
    subtotal: number;
    taxes: TaxLine[];
    total: number;
    invoice_id: number | null;
    delivered_at: string | null;
    accepted_at: string | null;
    declined_at: string | null;
    client?: { id: number; display_name: string };
    items?: QuoteItem[];
    created_at: string | null;
};

export type PayMethod = {
    id: number;
    gateway: string;
    kind: string;
    brand: string | null;
    last4: string | null;
    label: string;
    expiry: string | null;
    is_default: boolean;
    expired: boolean;
    disabled: boolean;
};

export type CreditEntry = {
    id: number;
    currency: string;
    amount: number;
    balance_after: number;
    reason: string;
    description: string | null;
    invoice_id: number | null;
    client?: { id: number; display_name: string };
    staff?: string | null;
    created_at: string | null;
};

export type ClientGatewayOption = {
    key: string;
    /** Staff-chosen method name, shown in the method list. */
    name: string;
    /** The provider the customer is sent to (e.g. "Stripe"), used in redirect copy. */
    provider: string;
    kind: 'card' | 'redirect' | 'offline';
    instructions: string | null;
    vault_url: string | null;
    sandbox?: boolean;
    logo?: GatewayLogoData | null;
    show_name?: boolean;
    /** Why this method can't take this invoice (shown, and the method is disabled). */
    unavailable_reason?: string | null;
    /** What the customer pays at the provider when it charges in another currency. */
    conversion?: {
        amount: number;
        currency: string;
        rate: number;
        from: string;
        /** "You'll pay Rs 13,440.00 on Khalti ($1.00 = Rs 134.40)." */
        label: string;
    } | null;
};

/** A hosted payment started and not yet confirmed (client and staff invoice pages). */
export type PaymentInProgressData = {
    id: number | null;
    reference: string;
    gateway: string;
    gateway_name: string;
    logo: GatewayLogoData | null;
    amount: number;
    currency: string;
    /** failed / expired: ended by the customer while the provider could still take it (shown with a warning). */
    status: 'pending' | 'ambiguous' | 'failed' | 'expired';
    started_at: string | null;
    expires_at: string | null;
    check_url: string;
    can_pay_another_way: boolean;
    /** Client only: the provider page can be opened again ("Continue payment"). */
    can_resume: boolean;
    /** Client only: the attempt can be closed to pay another way ("Use another method"). */
    can_close: boolean;
    review_reason: string | null;
};

export type PaymentOptions = {
    gateways: ClientGatewayOption[];
    can_choose_gateway: boolean;
    pay_methods: PayMethod[];
    selected_gateway: string | null;
    credit_available: number;
};

export type GatewayOption = {
    value: string;
    partial_refund?: boolean;
    refund_lookup?: boolean;
    sandbox?: boolean;
    label: string;
    capture?: boolean;
    refund?: boolean;
};

export type ClientOption = {
    id: number;
    display_name: string;
    currency?: string;
    email?: string;
};

/** "item_cancellation" → "Item cancellation" */
export function humanize(value: string): string {
    const words = value.replace(/[_-]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/** Minor units → "12.50" for prefilling decimal amount inputs. */
export function toDecimalInput(amount: number): string {
    return (amount / 100).toFixed(2);
}

/**
 * Canonicalise a typed amount with parseMoneyInput() ("1,299.1" → "1299.10", Indian grouping accepted).
 * Invalid input is passed through unchanged so the server rejects it with a field error instead of a guess.
 */
export function normalizeMoney(input: string): string {
    const minor = parseMoneyInput(input);

    if (minor === null) {
        return input.trim();
    }

    return `${minor < 0 ? '-' : ''}${(Math.abs(minor) / 100).toFixed(2)}`;
}

/** Normalise the given money fields of a form payload. */
export function withMoney<T extends Record<string, unknown>>(
    data: T,
    keys: (keyof T)[],
): T {
    const copy = { ...data };

    for (const key of keys) {
        const value = copy[key];

        if (typeof value === 'string' && value.trim() !== '') {
            copy[key] = normalizeMoney(value) as T[keyof T];
        }
    }

    return copy;
}
