/**
 * Sample data for the dev design previews (pages/dev/*-preview.tsx). Amounts are integer
 * minor units, like the backend sends them (ADR 0002).
 */
import type { QuickAction, ServerNavGroup } from '@/types';

const D = '/dev/dashboard-preview';
const I = '/dev/invoice-preview';
const C = '/dev/client-preview';
const K = '/dev/components';

export function adminNavigation(active: string): ServerNavGroup[] {
    const item = (
        title: string,
        href: string,
        icon: string,
        badge: number | null = null,
    ) => ({ title, href, icon, active: title === active, badge });

    return [
        {
            title: 'General',
            items: [
                item('Dashboard', D, 'LayoutGrid'),
                item('Activity log', K, 'Activity'),
            ],
        },
        {
            title: 'Clients',
            items: [item('Clients', K, 'Users')],
        },
        {
            title: 'Orders',
            items: [item('Orders', K, 'ShoppingCart', 6)],
        },
        {
            title: 'Billing',
            items: [
                item('Invoices', I, 'FileText', 9),
                item('Transactions', K, 'CreditCard'),
                item('Quotes', K, 'Receipt'),
            ],
        },
        {
            title: 'Services',
            items: [
                item('Services', K, 'Server'),
                item('Products', K, 'Package'),
                item('Servers', K, 'Boxes', 2),
            ],
        },
        {
            title: 'Domains',
            items: [
                item('Domains', K, 'Globe', 5),
                item('TLD pricing', K, 'Tag'),
            ],
        },
        {
            title: 'Support',
            items: [
                item('Tickets', K, 'LifeBuoy', 4),
                item('Knowledge base', K, 'BookOpen'),
                item('Announcements', K, 'Megaphone'),
            ],
        },
        {
            title: 'Setup',
            items: [
                item('Automation', K, 'Wrench'),
                item('Settings', K, 'Settings'),
            ],
        },
    ];
}

export const ADMIN_NAVIGATION = adminNavigation('Dashboard');

export const CLIENT_NAVIGATION: ServerNavGroup[] = [
    {
        title: 'Overview',
        items: [{ title: 'Home', href: C, icon: 'LayoutGrid', active: true }],
    },
    {
        title: 'Products',
        items: [
            { title: 'Services', href: C, icon: 'Server', active: false },
            { title: 'Domains', href: C, icon: 'Globe', active: false },
            {
                title: 'Order new',
                href: C,
                icon: 'ShoppingCart',
                active: false,
            },
        ],
    },
    {
        title: 'Billing',
        items: [
            {
                title: 'Invoices',
                href: I,
                icon: 'FileText',
                active: false,
                badge: 1,
            },
            {
                title: 'Payment methods',
                href: C,
                icon: 'CreditCard',
                active: false,
            },
        ],
    },
    {
        title: 'Support',
        items: [
            {
                title: 'Tickets',
                href: C,
                icon: 'LifeBuoy',
                active: false,
                badge: 1,
            },
            {
                title: 'Knowledge base',
                href: C,
                icon: 'BookOpen',
                active: false,
            },
        ],
    },
];

export const QUICK_ACTIONS: QuickAction[] = [
    { title: 'New client', href: K, icon: 'UserRound' },
    { title: 'New order', href: K, icon: 'ShoppingCart' },
    { title: 'New invoice', href: I, icon: 'FileText' },
    { title: 'Open ticket', href: K, icon: 'LifeBuoy' },
    { title: 'Register domain', href: K, icon: 'Globe' },
];

/* ---------- money ---------- */

export type PreviewCurrency = 'USD' | 'INR' | 'NPR';

/** Display rates from USD (sample only). */
export const FX: Record<PreviewCurrency, number> = {
    USD: 1,
    INR: 83.2,
    NPR: 133.1,
};

export const LOCALES: Record<PreviewCurrency, string> = {
    USD: 'en-US',
    INR: 'en-IN',
    NPR: 'en-IN',
};

export function convert(usdMinor: number, currency: PreviewCurrency): number {
    return Math.round(usdMinor * FX[currency]);
}

/* ---------- dashboard ---------- */

export const MONTHS = [
    '2025-11-01',
    '2025-12-01',
    '2026-01-01',
    '2026-02-01',
    '2026-03-01',
    '2026-04-01',
    '2026-05-01',
    '2026-06-01',
    '2026-07-01',
    '2026-08-01',
    '2026-09-01',
    '2026-10-01',
];

/** USD minor units per month. */
export const INCOME_USD = [
    2810400, 2942050, 3120880, 3061200, 3290410, 3411760, 3380125, 3562090,
    3719800, 3611340, 3890215, 3694055,
];
export const INCOME_LAST_YEAR_USD = [
    2301200, 2410060, 2528800, 2490150, 2681020, 2740900, 2802300, 2911780,
    2988800, 3012400, 3150770, 3204100,
];

export const KPI_SPARKS = {
    mrr: [
        41.2, 41.9, 42.4, 43.1, 43.0, 44.2, 45.1, 45.6, 46.3, 46.9, 47.6, 48.2,
    ],
    income: [
        28.1, 29.4, 31.2, 30.6, 32.9, 34.1, 33.8, 35.6, 37.2, 36.1, 38.9, 36.9,
    ],
    outstanding: [
        10.1, 11.4, 10.8, 12.2, 11.6, 12.9, 13.4, 12.1, 12.8, 13.2, 12.6, 12.5,
    ],
    overdue: [2.1, 2.4, 2.2, 2.6, 2.3, 2.5, 2.9, 2.7, 2.8, 3.0, 2.9, 3.1],
};

export const ORDER_DAYS = [
    '2026-10-04',
    '2026-10-05',
    '2026-10-06',
    '2026-10-07',
    '2026-10-08',
    '2026-10-09',
    '2026-10-10',
    '2026-10-11',
    '2026-10-12',
    '2026-10-13',
    '2026-10-14',
    '2026-10-15',
    '2026-10-16',
    '2026-10-17',
];
export const ORDERS_HOSTING = [6, 4, 9, 11, 8, 7, 5, 6, 10, 12, 9, 8, 11, 7];
export const ORDERS_DOMAINS = [4, 3, 5, 6, 7, 3, 2, 4, 6, 5, 8, 6, 5, 4];
export const ORDERS_VPS = [1, 0, 2, 3, 1, 2, 1, 0, 2, 3, 2, 1, 3, 2];
