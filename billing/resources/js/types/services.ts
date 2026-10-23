/** Props shared by the Services module pages (admin + client area). */

export type ServiceStatus =
    | 'pending'
    | 'active'
    | 'suspended'
    | 'terminated'
    | 'cancelled'
    | 'fraud'
    | 'completed';

export type ServiceRow = {
    id: number;
    label: string;
    domain: string | null;
    product_id: number;
    product_name: string | null;
    status: ServiceStatus;
    cycle: string;
    currency: string;
    quantity: number;
    recurring_amount: number;
    first_payment_amount: number;
    registration_date: string;
    next_due_date: string | null;
    next_invoice_date: string | null;
    is_overdue: boolean;
    username: string | null;
    dedicated_ip: string | null;
    assigned_ips: string[];
    suspension_reason: string | null;
    do_not_renew: boolean;
    termination_date: string | null;
    activated_at: string | null;
    server?: {
        id: number;
        name: string;
        hostname: string;
        nameservers: string[];
    } | null;
    client?: { id: number; name: string };
    // Staff-only fields
    order_id?: number | null;
    server_id?: number | null;
    billing_anchor_day?: number | null;
    renewals_invoiced?: number;
    recurring_cycles_limit?: number;
    suspended_at?: string | null;
    suspend_exempt_until?: string | null;
    suspend_days_override?: number | null;
    terminate_days_override?: number | null;
    terminate_at_period_end?: boolean;
    payment_gateway?: string | null;
    config_options?: Record<string, number>;
    admin_notes?: string | null;
};

export type AddonRow = {
    id: number;
    name: string;
    status: string;
    cycle: string;
    recurring_amount: number;
    currency: string;
    next_due_date: string | null;
    suspend_parent_on_overdue?: boolean;
};

export type UsageData = {
    disk_used_mb: number;
    disk_limit_mb: number | null;
    bw_used_mb: number;
    bw_limit_mb: number | null;
    captured_at: string;
} | null;

export type ChangeOption = {
    product_id: number;
    product_name: string;
    cycle: string;
    recurring_amount: number;
    currency: string;
    credit: number;
    charge: number;
    amount_due: number;
    next_due_date: string | null;
    remaining_days: number;
    cycle_days: number;
};

export type ChangeOptions = {
    available: boolean;
    reason: string | null;
    options: ChangeOption[];
};

export type ModuleCommandRow = {
    id: number;
    service_id: number | null;
    service_label?: string | null;
    server_name?: string | null;
    driver: string;
    command: string;
    initiator: string;
    attempt: number;
    success: boolean;
    error: string | null;
    request: Record<string, unknown> | null;
    response: Record<string, unknown> | null;
    duration_ms: number;
    created_at: string | null;
};

export type ServerRow = {
    id: number;
    name: string;
    hostname: string;
    ip_address: string | null;
    assigned_ips: string[];
    driver: string;
    username: string | null;
    has_password: boolean;
    has_api_token: boolean;
    has_access_hash: boolean;
    /** Stored credentials (password, api_token, access_hash) shorter than the minimum. */
    credentials_short: string[];
    secure: boolean;
    port: number | null;
    max_accounts: number;
    active_accounts: number | null;
    is_active: boolean;
    is_default: boolean;
    nameservers: string[];
    monthly_cost: number;
    currency: string;
    datacenter: string | null;
    status_url: string | null;
    /** Driver settings: non-secret values, whether each secret one is stored, and which stored ones are too short. */
    settings: {
        values: Record<string, string>;
        secrets_set: Record<string, boolean>;
        secrets_short: string[];
    };
    groups?: { id: number; name: string }[];
};

export const CYCLE_LABELS: Record<string, string> = {
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    semiannually: 'Semi-annually',
    annually: 'Annually',
    biennially: 'Biennially',
    triennially: 'Triennially',
    onetime: 'One time',
    free: 'Free',
};

export function cycleLabel(cycle: string): string {
    return CYCLE_LABELS[cycle] ?? cycle;
}

/** "overdue" → "Overdue", "staff:abuse report" → "Staff: abuse report" */
export function suspensionReasonLabel(reason: string | null): string | null {
    if (!reason) {
        return null;
    }

    if (reason.startsWith('staff:')) {
        return `Staff: ${reason.slice(6)}`;
    }

    const labels: Record<string, string> = {
        overdue: 'Overdue invoice',
        addon_overdue: 'Overdue addon invoice',
        parent_suspended: 'Parent service suspended',
    };

    return labels[reason] ?? reason;
}

export function formatMegabytes(value: number | null): string {
    if (value === null) {
        return 'Unlimited';
    }

    return value >= 1024
        ? `${(value / 1024).toLocaleString('en-US', { maximumFractionDigits: 1 })} GB`
        : `${value.toLocaleString('en-US')} MB`;
}
