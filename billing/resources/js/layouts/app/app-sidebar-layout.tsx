import { AppContent } from '@/components/app-content';
import { ImpersonationBanner } from '@/components/app/impersonation-banner';
import { KycBanner } from '@/components/kyc/kyc-banner';
import { StoreHeaderTools } from '@/components/orders/store-header-tools';
import { PasswordConfirmationGate } from '@/components/app/password-confirmation-gate';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell>
            <AppSidebar />
            <AppContent className="min-w-0 overflow-x-clip">
                <ImpersonationBanner />
                <KycBanner />
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    actions={<StoreHeaderTools />}
                />
                {children}
            </AppContent>
            <PasswordConfirmationGate />
        </AppShell>
    );
}
