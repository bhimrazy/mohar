import { Link, usePage } from '@inertiajs/react';
import { AccountSwitcher } from '@/components/account-switcher';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavSearch } from '@/components/nav-search';
import { NavUser } from '@/components/nav-user';
import { QuickCreate } from '@/components/quick-create';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { QuickAction, ServerNavGroup } from '@/types';

type AppSidebarProps = {
    /** Overrides for previews/tests; default to the shared Inertia props. */
    navigation?: ServerNavGroup[];
    quickActions?: QuickAction[];
    area?: 'admin' | 'client';
};

export function AppSidebar(overrides: AppSidebarProps = {}) {
    const props = usePage().props;
    const navigation = overrides.navigation ?? props.navigation;
    const quickActions = overrides.quickActions ?? props.quickActions ?? [];
    const area = overrides.area ?? props.area;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="gap-3">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {area === 'client' && <AccountSwitcher />}
                {area === 'admin' ? (
                    // Admin search (⌘K, records) is Platform's command palette in the header.
                    <QuickCreate
                        actions={quickActions}
                        label="Create new"
                        className="w-full justify-start group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:p-0 [&>span]:group-data-[collapsible=icon]:hidden"
                    />
                ) : (
                    <NavSearch groups={navigation} quickActions={[]} />
                )}
            </SidebarHeader>

            <SidebarContent className="gap-1">
                <NavMain groups={navigation} />
            </SidebarContent>

            <SidebarFooter className="border-t border-sidebar-border">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
