import type { Auth } from '@/types/auth';
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
            area: 'admin' | 'client';
            navigation: ServerNavGroup[];
            quickActions: QuickAction[];
            brand: Brand;
            theme: SharedTheme;
            clientAccounts: ClientAccount[];
            currentClientId: number | null;
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
