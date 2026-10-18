/** Shapes sent by the Core module (App\Modules\Core\Http\Resources). */

export type ClientRecord = {
    id: number;
    display_name: string;
    first_name: string;
    last_name: string;
    company_name: string | null;
    email: string;
    phone: string | null;
    address_1: string | null;
    address_2: string | null;
    city: string | null;
    state: string | null;
    postcode: string | null;
    country: string | null;
    tax_id: string | null;
    tax_exempt: boolean;
    currency: string;
    language: string;
    status: string;
    notes?: string | null;
    client_group_id: number | null;
    group?: { id: number; name: string; colour: string | null } | null;
    closed_at: string | null;
    merged_into_client_id: number | null;
    created_at: string | null;
};

/** A dashboard card resolved by App\Support\Dashboard\Dashboard. */
export type DashboardCard = {
    key: string;
    label: string;
    value: string | number;
    href?: string;
    icon?: string;
    hint?: string;
    trend?: {
        value: string;
        direction: 'up' | 'down' | 'flat';
        upIsGood?: boolean;
    };
};
