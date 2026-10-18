import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    /** Omitted for a level that is only a label, such as a sidebar group. */
    href?: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
};

/** Server-driven navigation shared by HandleInertiaRequests (App\Support\Navigation). */
export type ServerNavItem = {
    title: string;
    href: string;
    icon: string;
    active: boolean;
    /** Count shown next to the item (e.g. tickets awaiting reply); null/0 hides it. */
    badge?: number | null;
};

/** Admin quick-create action (Navigation::quickActions()). */
export type QuickAction = {
    title: string;
    href: string;
    icon: string;
};

export type ServerNavGroup = {
    title: string;
    items: ServerNavItem[];
};

export type ClientAccount = {
    id: number;
    name: string;
};
