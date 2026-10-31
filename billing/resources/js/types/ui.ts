import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
    /** The top-left way out; the storefront home when not given. */
    backHref?: string;
    backLabel?: string;
};

/** Company branding (Themes module → BrandAssets::brand()). */
export type Brand = {
    name: string;
    tagline: string;
    /** Wordmark; null when a renamed brand has no uploaded logo (show mark + name). */
    logoLight: string | null;
    logoDark: string | null;
    mark: string;
    favicon: string;
    customised: boolean;
    /** The host uploaded the light wordmark (else logoLight, if set, is the product's default). */
    hasUploadedLogo: boolean;
    /** The host uploaded the mark (else mark is the product's default). */
    hasUploadedMark: boolean;
};

export type ThemeOption = {
    key: string;
    name: string;
    description: string;
    custom: boolean;
};

export type Density = 'theme' | 'compact' | 'comfortable' | 'spacious';

/** The request's resolved theme (Themes module → ShareTheme middleware). */
export type SharedTheme = {
    key: string;
    name: string;
    area: 'admin' | 'client' | 'public';
    density: Density;
    canChoose: boolean;
    preference: { theme: string | null; density: Density | null } | null;
    options: ThemeOption[];
};
