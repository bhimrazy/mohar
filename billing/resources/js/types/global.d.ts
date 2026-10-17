import type { Auth } from '@/types/auth';
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
            clientAccounts: ClientAccount[];
            currentClientId: number | null;
            [key: string]: unknown;
        };
    }
}
