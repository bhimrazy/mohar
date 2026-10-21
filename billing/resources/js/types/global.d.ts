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
            [key: string]: unknown;
        };
    }
}
