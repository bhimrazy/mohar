import { Bell, CircleHelp } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import {
    adminNavigation,
    CLIENT_NAVIGATION,
    QUICK_ACTIONS,
} from '@/components/dev/preview-data';
import { ThemeSwitcher } from '@/components/theme-switcher';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

/**
 * Layout for the dev design-direction previews: the real sidebar/header fed with sample
 * navigation (badges, quick actions) so every theme can be judged on a full screen.
 */
export default function PreviewLayout({
    children,
    area = 'admin',
    active = 'Dashboard',
    breadcrumbs = [],
}: {
    children: ReactNode;
    area?: 'admin' | 'client';
    /** Title of the highlighted admin nav item. */
    active?: string;
    breadcrumbs?: BreadcrumbItem[];
}) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar
                area={area}
                navigation={
                    area === 'admin'
                        ? adminNavigation(active)
                        : CLIENT_NAVIGATION
                }
                quickActions={area === 'admin' ? QUICK_ACTIONS : []}
            />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    actions={
                        <>
                            <ThemeSwitcher />
                            <Button
                                variant="ghost"
                                size="icon"
                                className="relative size-8"
                                aria-label="Notifications, 3 unread"
                            >
                                <Bell />
                                <span
                                    aria-hidden="true"
                                    className="absolute top-1.5 right-1.5 size-2 rounded-full bg-danger ring-2 ring-background"
                                />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="hidden size-8 sm:inline-flex"
                                aria-label="Help"
                            >
                                <CircleHelp />
                            </Button>
                        </>
                    }
                />
                {children}
            </AppContent>
        </AppShell>
    );
}
