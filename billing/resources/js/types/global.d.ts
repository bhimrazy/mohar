import type { Auth } from '@/types/auth';
import type { ClientAccount, ServerNavGroup } from '@/types/navigation';

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
            clientAccounts: ClientAccount[];
            currentClientId: number | null;
            /** Company name setting (falls back to the app name). */
            brand: string;
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
