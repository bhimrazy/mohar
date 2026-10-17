import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
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
};

export type ServerNavGroup = {
    title: string;
    items: ServerNavItem[];
};

export type ClientAccount = {
    id: number;
    name: string;
};
