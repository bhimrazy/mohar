import type { CurrencyFormat } from '@/lib/money';
import type { Auth } from '@/types/auth';
import type { Site } from '@/types/storefront';
import type { Brand, SharedTheme } from '@/types/ui';
import type {
    ClientAccount,
    QuickAction,
    ServerNavGroup,
} from '@/types/navigation';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            /** Settings → Company → Date format, one of DATE_FORMATS in lib/date.ts. */
            dateFormat: string;
            /** BCP 47 tag of the request locale, for example `ne-NP`. */
            locale: string;
            /** Catalogue for the locale (ADR 0028); empty for English. Inertia reuses a once prop across visits; pages still receive it. */
            translations?: Record<string, string>;
            /** ISO 3166-1 alpha-2 codes a country can be chosen from (sent once per visit). */
            countries: string[];
            area: 'admin' | 'client';
            navigation: ServerNavGroup[];
            quickActions: QuickAction[];
            brand: Brand;
            theme: SharedTheme;
            clientAccounts: ClientAccount[];
            currentClientId: number | null;
            currencyFormats: CurrencyFormat[];
            /** Public layout data; present on public pages when the Storefront module is installed. */
            site?: Site;
            impersonation?: {
                staff: string;
                user: string;
                client: string;
                expires_at: string;
            };
            /** Client-area identity verification banner; null when off, verified or outside the client area. */
            kyc?: { status: string; href: string } | null;
            [key: string]: unknown;
        };
    }
}
